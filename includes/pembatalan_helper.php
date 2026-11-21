<?php
/**
 * Helper sistem PERSETUJUAN PEMBATALAN BOOKING + REFUND ArenaKu.
 *
 * Aturan utama:
 * - Pengajuan pembatalan TIDAK langsung membatalkan booking.
 * - Booking menunggu keputusan admin maksimal 24 jam sejak requested_at.
 * - Keputusan admin (setuju/tolak) dan kedaluwarsa diproses dengan
 *   transaksi + SELECT ... FOR UPDATE agar aman dari proses bersamaan.
 * - Status refund dicatat terpisah dari status keputusan pembatalan.
 * - Semua perhitungan batas waktu memakai NOW() MySQL, bukan jam browser,
 *   agar tidak terpengaruh perbedaan timezone PHP/MySQL.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/midtrans_helper.php';

const PEMBATALAN_BATAS_JAM = 24;

// ---------------------------------------------------------------------
// LABEL / TAMPILAN
// ---------------------------------------------------------------------
function pembatalanLabelStatus($status)
{
    $map = [
        'pending'  => ['Menunggu Persetujuan Admin', 'badge-warning'],
        'approved' => ['Pembatalan Disetujui', 'badge-success'],
        'rejected' => ['Pembatalan Ditolak', 'badge-danger'],
        'expired'  => ['Pengajuan Kedaluwarsa', 'badge-neutral'],
    ];
    return $map[$status] ?? ['Status tidak dikenal', 'badge-neutral'];
}

function pembatalanLabelRefund($status)
{
    $map = [
        'none'       => ['Tidak ada refund', 'badge-neutral'],
        'processing' => ['Refund sedang diproses', 'badge-warning'],
        'success'    => ['Refund berhasil', 'badge-success'],
        'failed'     => ['Refund gagal / perlu tindakan', 'badge-danger'],
    ];
    return $map[$status] ?? ['Status refund tidak dikenal', 'badge-neutral'];
}

// Ubah jumlah detik menjadi teks sisa waktu yang mudah dibaca.
function pembatalanFormatSisa($detik)
{
    $detik = (int) $detik;
    if ($detik <= 0) {
        return 'Kedaluwarsa';
    }
    $hari  = intdiv($detik, 86400);
    $jam   = intdiv($detik % 86400, 3600);
    $menit = intdiv($detik % 3600, 60);

    $bagian = [];
    if ($hari > 0) {
        $bagian[] = $hari . ' hari';
    }
    if ($jam > 0) {
        $bagian[] = $jam . ' jam';
    }
    if ($menit > 0) {
        $bagian[] = $menit . ' menit';
    }
    if (empty($bagian)) {
        $bagian[] = 'kurang dari 1 menit';
    }
    return implode(' ', $bagian);
}

// ---------------------------------------------------------------------
// PENGAMBILAN DATA
// ---------------------------------------------------------------------
// Ambil pengajuan terakhir untuk satu booking (termasuk sisa waktu versi DB).
function pembatalanAmbilTerakhir($koneksi, $bookingId)
{
    $stmt = $koneksi->prepare(
        "SELECT cr.*, u.name AS decided_by_name,
                TIMESTAMPDIFF(SECOND, NOW(), cr.deadline_at) AS sisa_detik,
                (cr.deadline_at <= NOW()) AS sudah_lewat
         FROM cancellation_requests cr
         LEFT JOIN users u ON u.id = cr.decided_by
         WHERE cr.booking_id = ?
         ORDER BY cr.id DESC
         LIMIT 1"
    );
    $stmt->execute([(int) $bookingId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Ambil pengajuan terakhir untuk banyak booking sekaligus (dipakai Booking Saya).
function pembatalanAmbilTerakhirBanyak($koneksi, array $bookingIds)
{
    $hasil = [];
    $bookingIds = array_values(array_unique(array_map('intval', $bookingIds)));
    if (empty($bookingIds)) {
        return $hasil;
    }

    $placeholder = implode(',', array_fill(0, count($bookingIds), '?'));
    $stmt = $koneksi->prepare(
        "SELECT cr.*, u.name AS decided_by_name,
                TIMESTAMPDIFF(SECOND, NOW(), cr.deadline_at) AS sisa_detik,
                (cr.deadline_at <= NOW()) AS sudah_lewat
         FROM cancellation_requests cr
         JOIN (
             SELECT booking_id, MAX(id) AS max_id
             FROM cancellation_requests
             WHERE booking_id IN ($placeholder)
             GROUP BY booking_id
         ) terakhir ON terakhir.max_id = cr.id
         LEFT JOIN users u ON u.id = cr.decided_by"
    );
    $stmt->execute($bookingIds);

    foreach ($stmt->fetchAll() as $row) {
        $hasil[(int) $row['booking_id']] = $row;
    }
    return $hasil;
}

// ---------------------------------------------------------------------
// KELAYAKAN PENGAJUAN (server-side)
// ---------------------------------------------------------------------
function pembatalanCekKelayakan($booking, $koneksi = null)
{
    if ($booking['booking_status'] === 'completed') {
        return ['boleh' => false, 'alasan' => 'Booking yang sudah selesai tidak dapat dibatalkan.'];
    }

    if ($booking['booking_status'] === 'cancelled') {
        return ['boleh' => false, 'alasan' => 'Booking ini sudah dibatalkan sebelumnya.'];
    }

    if ($booking['booking_status'] === 'pending_payment') {
        return ['boleh' => true, 'alasan' => ''];
    }

    if ($booking['booking_status'] === 'confirmed') {
        // Hitung sisa waktu memakai jam MySQL agar konsisten dengan deadline
        // pengajuan. Jangan memakai time() PHP karena timezone bisa berbeda.
        if ($koneksi instanceof PDO) {
            $stmt = $koneksi->prepare(
                "SELECT TIMESTAMPDIFF(SECOND, NOW(), TIMESTAMP(?, ?)) AS detik_ke_jadwal"
            );
            $stmt->execute([$booking['booking_date'], $booking['start_time']]);
            $detikKeJadwal = (int) $stmt->fetchColumn();
        } else {
            $waktuJadwal  = strtotime($booking['booking_date'] . ' ' . $booking['start_time']);
            $detikKeJadwal = $waktuJadwal - time();
        }

        if ($detikKeJadwal <= PEMBATALAN_BATAS_JAM * 3600) {
            return [
                'boleh'  => false,
                'alasan' => 'Pengajuan pembatalan hanya bisa dilakukan maksimal 24 jam sebelum jadwal. Waktu untuk booking ini sudah lewat.',
            ];
        }
        return ['boleh' => true, 'alasan' => ''];
    }

    return ['boleh' => false, 'alasan' => 'Status booking tidak dikenali.'];
}

// ---------------------------------------------------------------------
// PENGECEKAN KEDALUWARSA (fallback saat halaman diakses)
// Cron job tetap menjadi mekanisme utama agar berjalan tanpa kunjungan.
// ---------------------------------------------------------------------
function pembatalanExpireDue($koneksi, $limit = 200)
{
    $limit = max(1, (int) $limit);

    $stmt = $koneksi->query(
        "SELECT id FROM cancellation_requests
         WHERE decision_status = 'pending' AND deadline_at <= NOW()
         ORDER BY id ASC
         LIMIT $limit"
    );
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $jumlah = 0;
    foreach ($ids as $id) {
        try {
            $koneksi->beginTransaction();

            $lock = $koneksi->prepare(
                "SELECT id, decision_status,
                        (deadline_at <= NOW()) AS sudah_lewat
                 FROM cancellation_requests
                 WHERE id = ?
                 FOR UPDATE"
            );
            $lock->execute([(int) $id]);
            $row = $lock->fetch();

            if ($row && $row['decision_status'] === 'pending' && (int) $row['sudah_lewat'] === 1) {
                $upd = $koneksi->prepare(
                    "UPDATE cancellation_requests
                     SET decision_status = 'expired',
                         decided_at = NOW(),
                         active_booking_id = NULL,
                         refund_status = 'none',
                         refund_note = 'Kedaluwarsa: tidak ada keputusan admin dalam 24 jam'
                     WHERE id = ? AND decision_status = 'pending'"
                );
                $upd->execute([(int) $id]);
                if ($upd->rowCount() > 0) {
                    $jumlah++;
                }
            }

            $koneksi->commit();
        } catch (Throwable $e) {
            if ($koneksi->inTransaction()) {
                $koneksi->rollBack();
            }
            error_log('[Pembatalan] Gagal memproses kedaluwarsa #' . $id . ': ' . $e->getMessage());
        }
    }

    return $jumlah;
}

// ---------------------------------------------------------------------
// USER: AJUKAN PEMBATALAN
// ---------------------------------------------------------------------
function pembatalanAjukan($koneksi, $bookingId, $userId, $reason)
{
    $reason = trim((string) $reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Alasan pembatalan wajib diisi.'];
    }
    if (mb_strlen($reason) > 500) {
        $reason = mb_substr($reason, 0, 500);
    }

    try {
        $koneksi->beginTransaction();

        $stmt = $koneksi->prepare(
            "SELECT bookings.*, payments.id AS payment_id, payments.payment_status
             FROM bookings
             JOIN payments ON payments.booking_id = bookings.id
             WHERE bookings.id = ? AND bookings.user_id = ?
             FOR UPDATE"
        );
        $stmt->execute([(int) $bookingId, (int) $userId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Booking tidak ditemukan atau bukan milik kamu.'];
        }

        $cek = pembatalanCekKelayakan($booking, $koneksi);
        if (!$cek['boleh']) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => $cek['alasan']];
        }

        $cekAktif = $koneksi->prepare(
            "SELECT id FROM cancellation_requests
             WHERE booking_id = ? AND decision_status = 'pending'
             LIMIT 1
             FOR UPDATE"
        );
        $cekAktif->execute([(int) $bookingId]);
        if ($cekAktif->fetch()) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Sudah ada pengajuan pembatalan yang sedang menunggu keputusan admin.'];
        }

        $ins = $koneksi->prepare(
            "INSERT INTO cancellation_requests
                (booking_id, active_booking_id, user_id, reason,
                 previous_booking_status, decision_status, requested_at, deadline_at)
             VALUES (?, ?, ?, ?, ?, 'pending', NOW(), DATE_ADD(NOW(), INTERVAL " . PEMBATALAN_BATAS_JAM . " HOUR))"
        );
        $ins->execute([
            (int) $bookingId,
            (int) $bookingId,
            (int) $userId,
            $reason,
            $booking['booking_status'],
        ]);
        $requestId = (int) $koneksi->lastInsertId();

        $info = $koneksi->prepare(
            "SELECT requested_at, deadline_at,
                    TIMESTAMPDIFF(SECOND, NOW(), deadline_at) AS sisa_detik
             FROM cancellation_requests WHERE id = ?"
        );
        $info->execute([$requestId]);
        $row = $info->fetch();

        $koneksi->commit();

        return [
            'ok'         => true,
            'request_id' => $requestId,
            'requested_at' => $row['requested_at'],
            'deadline_at'  => $row['deadline_at'],
            'sisa_detik'   => (int) $row['sisa_detik'],
        ];
    } catch (PDOException $e) {
        if ($koneksi->inTransaction()) {
            $koneksi->rollBack();
        }
        $kode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($kode === 1062) {
            return ['ok' => false, 'error' => 'Sudah ada pengajuan pembatalan yang sedang menunggu keputusan admin.'];
        }
        error_log('[Pembatalan] Gagal menyimpan pengajuan: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Terjadi kesalahan saat menyimpan pengajuan. Silakan coba lagi.'];
    } catch (Throwable $e) {
        if ($koneksi->inTransaction()) {
            $koneksi->rollBack();
        }
        error_log('[Pembatalan] Gagal menyimpan pengajuan: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Terjadi kesalahan saat menyimpan pengajuan. Silakan coba lagi.'];
    }
}

// ---------------------------------------------------------------------
// ADMIN: SETUJUI PEMBATALAN
// ---------------------------------------------------------------------
function pembatalanSetujui($koneksi, $requestId, $adminId)
{
    try {
        $koneksi->beginTransaction();

        $stmt = $koneksi->prepare(
            "SELECT cr.*, bookings.booking_code, bookings.booking_status AS current_booking_status,
                    payments.id AS payment_id, payments.payment_status,
                    payments.amount, payments.is_simulated, payments.midtrans_order_id,
                    (cr.deadline_at <= NOW()) AS sudah_lewat
             FROM cancellation_requests cr
             JOIN bookings ON bookings.id = cr.booking_id
             JOIN payments ON payments.booking_id = bookings.id
             WHERE cr.id = ?
             FOR UPDATE"
        );
        $stmt->execute([(int) $requestId]);
        $req = $stmt->fetch();

        if (!$req) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Pengajuan pembatalan tidak ditemukan.'];
        }

        if ($req['decision_status'] !== 'pending') {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Pengajuan ini sudah diproses dan tidak bisa diproses ulang.'];
        }

        if ((int) $req['sudah_lewat'] === 1) {
            $upd = $koneksi->prepare(
                "UPDATE cancellation_requests
                 SET decision_status = 'expired', decided_at = NOW(),
                     active_booking_id = NULL, refund_status = 'none',
                     refund_note = 'Kedaluwarsa: tidak ada keputusan admin dalam 24 jam'
                 WHERE id = ? AND decision_status = 'pending'"
            );
            $upd->execute([(int) $requestId]);
            $koneksi->commit();
            return ['ok' => false, 'expired' => true,
                    'error' => 'Pengajuan sudah melewati batas 24 jam dan otomatis kedaluwarsa.'];
        }

        if ($req['current_booking_status'] === 'completed') {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Booking sudah selesai dan tidak dapat dibatalkan.'];
        }

        $dibayar = ($req['payment_status'] === 'paid');

        $upd = $koneksi->prepare(
            "UPDATE cancellation_requests
             SET decision_status = 'approved',
                 decided_at = NOW(),
                 decided_by = ?,
                 active_booking_id = NULL,
                 refund_status = ?,
                 refund_amount = ?,
                 refund_requested_at = CASE WHEN ? = 1 THEN NOW() ELSE NULL END,
                 refund_note = ?
             WHERE id = ? AND decision_status = 'pending'"
        );
        $upd->execute([
            (int) $adminId,
            $dibayar ? 'processing' : 'none',
            $dibayar ? $req['amount'] : null,
            $dibayar ? 1 : 0,
            $dibayar
                ? 'Pembatalan disetujui. Menunggu pemrosesan refund.'
                : 'Pembatalan disetujui. Tidak ada pembayaran lunas yang perlu direfund.',
            (int) $requestId,
        ]);

        if ($upd->rowCount() === 0) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Pengajuan ini sudah diproses dan tidak bisa diproses ulang.'];
        }

        $book = $koneksi->prepare("UPDATE bookings SET booking_status = 'cancelled' WHERE id = ?");
        $book->execute([(int) $req['booking_id']]);

        if (!$dibayar) {
            // Belum ada dana yang masuk: hentikan pembayaran yang masih terbuka,
            // tanpa menjalankan refund.
            $pay = $koneksi->prepare(
                "UPDATE payments
                 SET payment_status = 'failed',
                     status_detail = 'Dibatalkan admin, tanpa refund'
                 WHERE id = ? AND payment_status IN ('unpaid', 'pending')"
            );
            $pay->execute([(int) $req['payment_id']]);
        }

        $koneksi->commit();

        $hasil = [
            'ok'             => true,
            'request_id'     => (int) $requestId,
            'booking_id'     => (int) $req['booking_id'],
            'paid'           => $dibayar,
            'refund_state'   => 'none',
            'refund_message' => $dibayar ? 'Refund akan diproses.' : 'Tidak ada refund karena pembayaran belum lunas.',
        ];

        if ($dibayar) {
            $refund = pembatalanProsesRefund($koneksi, $requestId);
            $hasil['refund_state']   = $refund['state'] ?? 'failed';
            $hasil['refund_message'] = $refund['message'] ?? ($refund['error'] ?? 'Status refund belum diketahui.');
        } elseif ($req['payment_status'] === 'pending'
            && !empty($req['midtrans_order_id'])
            && midtransReady()) {
            // Best effort: hentikan transaksi Midtrans yang masih menunggu.
            try {
                \Midtrans\Transaction::cancel($req['midtrans_order_id']);
            } catch (Throwable $e) {
                error_log('[Midtrans] Gagal membatalkan transaksi pending '
                    . $req['midtrans_order_id'] . ': ' . $e->getMessage());
            }
        }

        return $hasil;
    } catch (Throwable $e) {
        if ($koneksi->inTransaction()) {
            $koneksi->rollBack();
        }
        error_log('[Pembatalan] Gagal menyetujui pengajuan: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Terjadi kesalahan saat menyetujui pengajuan. Silakan coba lagi.'];
    }
}

// ---------------------------------------------------------------------
// ADMIN: TOLAK PEMBATALAN
// ---------------------------------------------------------------------
function pembatalanTolak($koneksi, $requestId, $adminId, $rejectionReason)
{
    $rejectionReason = trim((string) $rejectionReason);
    if ($rejectionReason === '') {
        return ['ok' => false, 'error' => 'Alasan penolakan wajib diisi.'];
    }
    if (mb_strlen($rejectionReason) > 500) {
        $rejectionReason = mb_substr($rejectionReason, 0, 500);
    }

    try {
        $koneksi->beginTransaction();

        $stmt = $koneksi->prepare(
            "SELECT id, decision_status, booking_id,
                    (deadline_at <= NOW()) AS sudah_lewat
             FROM cancellation_requests
             WHERE id = ?
             FOR UPDATE"
        );
        $stmt->execute([(int) $requestId]);
        $req = $stmt->fetch();

        if (!$req) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Pengajuan pembatalan tidak ditemukan.'];
        }

        if ($req['decision_status'] !== 'pending') {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Pengajuan ini sudah diproses dan tidak bisa diproses ulang.'];
        }

        if ((int) $req['sudah_lewat'] === 1) {
            $upd = $koneksi->prepare(
                "UPDATE cancellation_requests
                 SET decision_status = 'expired', decided_at = NOW(),
                     active_booking_id = NULL, refund_status = 'none',
                     refund_note = 'Kedaluwarsa: tidak ada keputusan admin dalam 24 jam'
                 WHERE id = ? AND decision_status = 'pending'"
            );
            $upd->execute([(int) $requestId]);
            $koneksi->commit();
            return ['ok' => false, 'expired' => true,
                    'error' => 'Pengajuan sudah melewati batas 24 jam dan otomatis kedaluwarsa.'];
        }

        $upd = $koneksi->prepare(
            "UPDATE cancellation_requests
             SET decision_status = 'rejected',
                 decided_at = NOW(),
                 decided_by = ?,
                 rejection_reason = ?,
                 active_booking_id = NULL
             WHERE id = ? AND decision_status = 'pending'"
        );
        $upd->execute([(int) $adminId, $rejectionReason, (int) $requestId]);

        if ($upd->rowCount() === 0) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Pengajuan ini sudah diproses dan tidak bisa diproses ulang.'];
        }

        // Booking & pembayaran TIDAK diubah. Status booking memang tidak pernah
        // dipindahkan selama menunggu, jadi tetap aktif seperti sebelumnya.
        $koneksi->commit();

        return ['ok' => true, 'message' => 'Pengajuan pembatalan ditolak. Booking tetap aktif.'];
    } catch (Throwable $e) {
        if ($koneksi->inTransaction()) {
            $koneksi->rollBack();
        }
        error_log('[Pembatalan] Gagal menolak pengajuan: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Terjadi kesalahan saat menolak pengajuan. Silakan coba lagi.'];
    }
}

// ---------------------------------------------------------------------
// REFUND (setelah pembatalan disetujui)
// ---------------------------------------------------------------------
function pembatalanProsesRefund($koneksi, $requestId)
{
    $req = null;
    $refundKey = '';

    try {
        $koneksi->beginTransaction();

        $stmt = $koneksi->prepare(
            "SELECT cr.*, bookings.booking_code,
                    payments.id AS payment_id, payments.payment_status,
                    payments.amount, payments.is_simulated, payments.midtrans_order_id, payments.payment_type
             FROM cancellation_requests cr
             JOIN bookings ON bookings.id = cr.booking_id
             JOIN payments ON payments.booking_id = bookings.id
             WHERE cr.id = ?
             FOR UPDATE"
        );
        $stmt->execute([(int) $requestId]);
        $req = $stmt->fetch();

        if (!$req) {
            $koneksi->rollBack();
            return ['ok' => false, 'state' => 'failed', 'error' => 'Pengajuan pembatalan tidak ditemukan.'];
        }

        if ($req['decision_status'] !== 'approved') {
            $koneksi->rollBack();
            return ['ok' => false, 'state' => 'failed', 'error' => 'Refund hanya bisa diproses setelah pembatalan disetujui.'];
        }

        if ($req['refund_status'] === 'success') {
            $koneksi->commit();
            return ['ok' => true, 'state' => 'success', 'message' => 'Refund sudah berhasil sebelumnya.'];
        }

        if ($req['payment_status'] !== 'paid') {
            $upd = $koneksi->prepare(
                "UPDATE cancellation_requests
                 SET refund_status = 'none', refund_amount = NULL,
                     refund_note = 'Tidak ada pembayaran lunas yang perlu direfund.'
                 WHERE id = ?"
            );
            $upd->execute([(int) $requestId]);
            $koneksi->commit();
            return ['ok' => true, 'state' => 'none', 'message' => 'Tidak ada pembayaran lunas yang perlu direfund.'];
        }

        // Pembayaran simulasi tidak pernah menyentuh Midtrans: refund-nya juga
        // diselesaikan secara simulasi agar data konsisten.
        if ((int) $req['is_simulated'] === 1) {
            $upd = $koneksi->prepare(
                "UPDATE cancellation_requests
                 SET refund_status = 'success', refund_amount = ?,
                     refund_confirmed_at = NOW(),
                     refund_key = COALESCE(refund_key, ?),
                     refund_note = 'Refund simulasi berhasil (tanpa uang nyata).'
                 WHERE id = ?"
            );
            $upd->execute([
                $req['amount'],
                'SIM-REF-CR-' . (int) $requestId,
                (int) $requestId,
            ]);

            $pay = $koneksi->prepare(
                "UPDATE payments
                 SET payment_status = 'refunded',
                     status_detail = 'Refund simulasi berhasil (tanpa uang nyata)'
                 WHERE id = ?"
            );
            $pay->execute([(int) $req['payment_id']]);

            $koneksi->commit();
            return ['ok' => true, 'state' => 'success', 'message' => 'Refund simulasi berhasil.'];
        }

        if (!midtransReady()) {
            $upd = $koneksi->prepare(
                "UPDATE cancellation_requests
                 SET refund_status = 'failed',
                     refund_note = 'Midtrans belum aktif. Refund perlu tindakan admin.'
                 WHERE id = ?"
            );
            $upd->execute([(int) $requestId]);
            $koneksi->commit();
            return ['ok' => false, 'state' => 'failed',
                    'error' => 'Midtrans belum aktif. Refund belum diproses.'];
        }

        if (empty($req['midtrans_order_id'])) {
            $upd = $koneksi->prepare(
                "UPDATE cancellation_requests
                 SET refund_status = 'failed',
                     refund_note = 'Transaksi Midtrans tidak ditemukan. Refund perlu tindakan admin.'
                 WHERE id = ?"
            );
            $upd->execute([(int) $requestId]);
            $koneksi->commit();
            return ['ok' => false, 'state' => 'failed',
                    'error' => 'Transaksi Midtrans tidak ditemukan. Refund perlu tindakan admin.'];
        }

        $refundKey = !empty($req['refund_key'])
            ? $req['refund_key']
            : ('REF-CR-' . (int) $requestId);

        $upd = $koneksi->prepare(
            "UPDATE cancellation_requests
             SET refund_status = 'processing',
                 refund_key = ?,
                 refund_amount = ?,
                 refund_requested_at = COALESCE(refund_requested_at, NOW()),
                 refund_note = 'Permintaan refund sedang dikirim ke Midtrans.'
             WHERE id = ?"
        );
        $upd->execute([$refundKey, $req['amount'], (int) $requestId]);

        $koneksi->commit();
    } catch (Throwable $e) {
        if ($koneksi->inTransaction()) {
            $koneksi->rollBack();
        }
        error_log('[Pembatalan] Gagal menyiapkan refund: ' . $e->getMessage());
        return ['ok' => false, 'state' => 'failed', 'error' => 'Gagal menyiapkan proses refund.'];
    }

    // Panggilan API dilakukan DI LUAR transaksi agar tidak menahan lock DB
    // selama menunggu respons jaringan Midtrans.
    $hasil = midtransRefundRequest(
        $req['midtrans_order_id'],
        (float) $req['amount'],
        $refundKey,
        'Pembatalan booking ' . $req['booking_code']
    );

    try {
        $koneksi->beginTransaction();

        $lock = $koneksi->prepare(
            "SELECT refund_status FROM cancellation_requests WHERE id = ? FOR UPDATE"
        );
        $lock->execute([(int) $requestId]);
        $current = $lock->fetch();

        if (!$current) {
            $koneksi->rollBack();
            return ['ok' => false, 'state' => 'failed', 'error' => 'Pengajuan pembatalan tidak ditemukan.'];
        }

        // Notifikasi Midtrans bisa datang lebih dulu dan sudah menandai sukses.
        if ($current['refund_status'] === 'success') {
            $koneksi->commit();
            return ['ok' => true, 'state' => 'success', 'message' => 'Refund dikonfirmasi berhasil oleh Midtrans.'];
        }

        if ($hasil['state'] === 'success') {
            $upd = $koneksi->prepare(
                "UPDATE cancellation_requests
                 SET refund_status = 'success', refund_amount = ?,
                     refund_key = ?, refund_confirmed_at = NOW(),
                     refund_note = ?
                 WHERE id = ?"
            );
            $upd->execute([
                $req['amount'],
                $refundKey,
                'Refund berhasil dikonfirmasi Midtrans.',
                (int) $requestId,
            ]);

            $pay = $koneksi->prepare(
                "UPDATE payments
                 SET payment_status = 'refunded',
                     status_detail = 'Refund berhasil (Midtrans)'
                 WHERE id = ?"
            );
            $pay->execute([(int) $req['payment_id']]);

            $koneksi->commit();
            return ['ok' => true, 'state' => 'success', 'message' => 'Refund berhasil diproses Midtrans.'];
        }

        if ($hasil['state'] === 'processing') {
            $upd = $koneksi->prepare(
                "UPDATE cancellation_requests
                 SET refund_status = 'processing', refund_amount = ?, refund_key = ?,
                     refund_note = ?
                 WHERE id = ?"
            );
            $upd->execute([
                $req['amount'],
                $refundKey,
                'Refund sedang diproses Midtrans (menunggu konfirmasi).',
                (int) $requestId,
            ]);
            $koneksi->commit();
            return ['ok' => true, 'state' => 'processing',
                    'message' => 'Permintaan refund diterima Midtrans dan sedang diproses.'];
        }

        $catatan = substr((string) ($hasil['error'] ?? 'Midtrans menolak permintaan refund.'), 0, 255);
        $upd = $koneksi->prepare(
            "UPDATE cancellation_requests
             SET refund_status = 'failed', refund_amount = ?, refund_key = ?, refund_note = ?
             WHERE id = ?"
        );
        $upd->execute([$req['amount'], $refundKey, $catatan, (int) $requestId]);

        $koneksi->commit();
        return ['ok' => false, 'state' => 'failed', 'error' => $catatan];
    } catch (Throwable $e) {
        if ($koneksi->inTransaction()) {
            $koneksi->rollBack();
        }
        error_log('[Pembatalan] Gagal menyimpan hasil refund: ' . $e->getMessage());
        return ['ok' => false, 'state' => 'failed', 'error' => 'Gagal menyimpan hasil refund.'];
    }
}
