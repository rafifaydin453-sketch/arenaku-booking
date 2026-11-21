<?php
/**
 * Helper integrasi Midtrans Snap untuk ArenaKu.
 *
 * Aturan penting:
 * - Server Key HANYA dipakai di backend (file ini), tidak pernah dikirim ke HTML/JS.
 * - Status pembayaran di DB HANYA berubah lewat:
 *     1. notifikasi resmi Midtrans (midtrans_notification.php), atau
 *     2. pengecekan status server-to-server (cek_status_pembayaran.php).
 *   Bukan dari callback JavaScript / halaman redirect.
 * - Semua proses notifikasi IDEMPOTEN: notifikasi berulang tidak merusak data.
 */

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/functions.php';

// =====================================================================
// KONFIGURASI MIDTRANS + BOOTSTRAP LIBRARY RESMI
// =====================================================================
function midtransConfig()
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/midtrans.php';

        if ($cfg['configured']) {
            require_once __DIR__ . '/../vendor/midtrans/Midtrans.php';
            \Midtrans\Config::$serverKey     = $cfg['server_key'];
            \Midtrans\Config::$clientKey     = $cfg['client_key'];
            \Midtrans\Config::$isProduction  = $cfg['is_production'];
            \Midtrans\Config::$is3ds         = false;
        }
    }
    return $cfg;
}

function midtransReady()
{
    return midtransConfig()['configured'];
}

// =====================================================================
// DAFTAR METODE PEMBAYARAN YANG DIIZINKAN PER KATEGORI USER
// Nilai harus cocok dengan tipe kanal yang BENAR-BENAR aktif di akun
// Midtrans (lihat Snap: bca_va, bni_va, gopay, shopeepay, dana, qris, dst).
// Mengirim nama kanal yang tidak ada (mis. 'bank_transfer') membuat Snap
// menampilkan "No payment channels available".
// =====================================================================
function midtransEnabledPayments($kategori)
{
    $map = [
        // QRIS generik: nama kanal yang valid adalah 'other_qris'
        // (mengirim 'qris' saja membuat Snap kosong karena ambigu).
        'qris'          => ['other_qris'],

        // Virtual Account per bank (bukan 'bank_transfer')
        'transfer_bank' => ['bca_va', 'bni_va', 'bri_va', 'permata_va', 'cimb_va', 'seabank_va', 'bsi_va', 'danamon_va'],

        // E-wallet yang aktif di akun
        'e_wallet'      => ['gopay', 'shopeepay', 'dana'],
    ];

    if (isset($map[$kategori])) {
        return $map[$kategori];
    }

    // Default: biarkan Snap menampilkan seluruh kanal aktif akun
    return null;
}

// =====================================================================
// CEK APAKAH TOKEN SNAP LAMA MASIH BERLAKU
// Catatan: transaksi Midtrans baru benar-benar tercipta SETELAH halaman Snap
// dibuka, sehingga API status membalas 404 selama Snap belum dibuka — jadi
// penentuan reuse TIDAK boleh memakai API status.
// expire_time dibandingkan di sisi DATABASE (NOW()) supaya tidak terpengaruh
// perbedaan timezone antara PHP dan MySQL.
// - expire_time ada & > NOW() -> token lama masih boleh dipakai
// - sudah lewat / kosong      -> anggap mati, buat order baru
// =====================================================================
function midtransTokenMasihBerlaku($paymentId)
{
    global $koneksi;

    $stmt = $koneksi->prepare(
        "SELECT (expire_time IS NOT NULL AND expire_time > NOW()) AS valid
         FROM payments WHERE id = ?"
    );
    $stmt->execute([$paymentId]);
    $row = $stmt->fetch();

    return $row ? (bool) $row['valid'] : false;
}

// =====================================================================
// BUAT (ATAU AMBIL ULANG) TRANSAKSI SNAP UNTUK SEBUAH BOOKING
// - gross_amount selalu dari database (payments.amount), bukan input user
// - order_id unik: ARK-{payment_id}-{uniqid}, dan dipakai ulang kalau
//   transaksi sebelumnya masih pending & belum kedaluwarsa
//   (mencegah transaksi ganda)
// =====================================================================
function midtransCreateSnapTransaction($booking, $kategoriMetode)
{
    global $koneksi;

    // Ambil row payment milik booking ini
    $stmt = $koneksi->prepare("SELECT * FROM payments WHERE booking_id = ?");
    $stmt->execute([$booking['id']]);
    $payment = $stmt->fetch();

    if (!$payment) {
        return ['ok' => false, 'error' => 'Data pembayaran booking ini tidak ditemukan.'];
    }

    // Booking harus masih menunggu pembayaran
    if ($booking['booking_status'] !== 'pending_payment') {
        return ['ok' => false, 'error' => 'Booking ini sudah tidak menunggu pembayaran.'];
    }

    // Sudah lunas? tolak.
    if ($payment['payment_status'] === 'paid') {
        return ['ok' => false, 'error' => 'Pembayaran booking ini sudah lunas.'];
    }

    if (!midtransReady()) {
        return ['ok' => false, 'error' => 'Konfigurasi Midtrans belum diisi. Isi config/midtrans_keys.php terlebih dahulu.'];
    }

    // ===== CEGAH TRANSAKSI GANDA =====
    // Kalau sudah ada order_id dan status masih pending & belum kedaluwarsa,
    // pakai token lama (user tinggal membuka lagi halaman Snap yang sama).
    // Token yang sudah lewat masa berlaku tidak boleh di-reuse selamanya.
    if (!empty($payment['midtrans_order_id'])
        && in_array($payment['payment_status'], ['pending'], true)
        && !empty($payment['snap_token'])) {

        $masihHidup = midtransTokenMasihBerlaku($payment['id']);

        if ($masihHidup) {
            return [
                'ok'          => true,
                'order_id'    => $payment['midtrans_order_id'],
                'snap_token'  => $payment['snap_token'],
                'redirect_url'=> null,
                'reused'      => true,
            ];
        }

        // Token lama sudah kedaluwarsa -> bersihkan supaya order_id baru
        // bisa dipakai (kolom midtrans_order_id punya UNIQUE KEY).
        $stmtReset = $koneksi->prepare(
            "UPDATE payments
             SET midtrans_order_id = NULL, snap_token = NULL, payment_status = 'unpaid'
             WHERE id = ?"
        );
        $stmtReset->execute([$payment['id']]);

        $payment['midtrans_order_id'] = null;
        $payment['snap_token']        = null;
        $payment['payment_status']    = 'unpaid';
    }

    // Kalau sebelumnya 'failed'/'expired' -> buat order_id BARU supaya bisa bayar ulang
    $orderId = 'ARK-' . $payment['id'] . '-' . strtoupper(bin2hex(random_bytes(4)));

    // Nominal dari DATABASE, bukan input user
    $grossAmount = (int) round((float) $payment['amount']);
    if ($grossAmount <= 0) {
        return ['ok' => false, 'error' => 'Nominal pembayaran tidak valid.'];
    }

    $enabledPayments = midtransEnabledPayments($kategoriMetode);

    $params = [
        'transaction_details' => [
            'order_id'     => $orderId,
            'gross_amount' => $grossAmount,
        ],
        'item_details' => [[
            'id'       => 'booking-' . $booking['booking_code'],
            'price'    => $grossAmount,
            'quantity' => 1,
            'name'     => 'Sewa ' . $booking['court_name'] . ' (' . $booking['booking_code'] . ')',
        ]],
        'customer_details' => [
            'first_name' => isset($_SESSION['name']) ? $_SESSION['name'] : 'User',
            'email'      => isset($_SESSION['email']) ? $_SESSION['email'] : null,
        ],
        'expiry' => [
            'unit'     => 'minutes',
            'duration' => 60, // menit
        ],
        // Callback JS hanya untuk REDIRECT; pembayaran dianggap lunas
        // hanya setelah notifikasi/cek-status backend.
        'callbacks' => [
            'finish' => '/arenaku/hasil_pembayaran.php?order_id=' . urlencode($orderId),
        ],
    ];

    // Sertakan enabled_payments hanya kalau daftarnya jelas. Kalau null,
    // biarkan Snap menampilkan seluruh kanal aktif akun.
    if (is_array($enabledPayments) && !empty($enabledPayments)) {
        $params['enabled_payments'] = $enabledPayments;
    }

    try {
        $snapResponse = \Midtrans\Snap::createTransaction($params);
    } catch (Exception $e) {
        // Jangan tampilkan detail teknis ke user biasa
        error_log('[Midtrans] createTransaction gagal: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Gagal membuat transaksi Midtrans. Silakan coba lagi nanti.'];
    }

    $token       = isset($snapResponse->token) ? $snapResponse->token : null;
    $redirectUrl = isset($snapResponse->redirect_url) ? $snapResponse->redirect_url : null;

    if (!$token) {
        return ['ok' => false, 'error' => 'Midtrans tidak mengembalikan token pembayaran.'];
    }

    // Simpan ke payments + status jadi 'pending' (menunggu pembayaran)
    $stmtUpdate = $koneksi->prepare(
        "UPDATE payments
         SET midtrans_order_id = ?,
             snap_token = ?,
             payment_method = ?,
             payment_status = 'pending',
             status_detail = 'Menunggu pembayaran via Snap',
             expire_time = DATE_ADD(NOW(), INTERVAL 60 MINUTE)
         WHERE id = ?"
    );
    $stmtUpdate->execute([$orderId, $token, $kategoriMetode, $payment['id']]);

    return [
        'ok'           => true,
        'order_id'     => $orderId,
        'snap_token'   => $token,
        'redirect_url' => $redirectUrl,
        'reused'       => false,
    ];
}

// =====================================================================
// KIRIM PERMINTAAN REFUND KE MIDTRANS
// - refund_key dipakai ulang untuk request yang sama (idempoten), supaya
//   retry tidak menciptakan refund ganda.
// - Hasil 'success' hanya jika Midtrans membalas status refund yang valid;
//   selain itu 'processing' (menunggu notifikasi) atau 'failed'.
// =====================================================================
function midtransRefundRequest($orderId, $amount, $refundKey, $reason = '')
{
    if (!midtransReady()) {
        return ['ok' => false, 'state' => 'failed', 'error' => 'Konfigurasi Midtrans belum aktif.'];
    }

    $params = [
        'refund_key' => $refundKey,
        'amount'     => (int) round((float) $amount),
        'reason'     => $reason !== '' ? $reason : 'Pembatalan booking ArenaKu',
    ];

    try {
        $respons = \Midtrans\Transaction::refund($orderId, $params);
        $respons = (array) $respons;
    } catch (Exception $e) {
        error_log('[Midtrans] Transaction::refund gagal: ' . $e->getMessage());
        return [
            'ok'    => false,
            'state' => 'failed',
            'error' => 'Gagal mengirim permintaan refund ke Midtrans.',
        ];
    }

    $statusCode = isset($respons['status_code']) ? (string) $respons['status_code'] : '';
    $statusTrx  = isset($respons['transaction_status']) ? (string) $respons['transaction_status'] : '';
    $pesan      = isset($respons['status_message']) ? (string) $respons['status_message'] : '';

    if (in_array($statusCode, ['200', '201'], true)) {
        if (in_array($statusTrx, ['refund', 'partial_refund'], true)) {
            return [
                'ok'      => true,
                'state'   => 'success',
                'message' => $pesan !== '' ? $pesan : 'Refund dikonfirmasi Midtrans.',
                'response'=> $respons,
            ];
        }
        return [
            'ok'      => true,
            'state'   => 'processing',
            'message' => $pesan !== '' ? $pesan : 'Permintaan refund diterima Midtrans.',
            'response'=> $respons,
        ];
    }

    return [
        'ok'    => false,
        'state' => 'failed',
        'error' => $pesan !== '' ? $pesan : 'Midtrans menolak permintaan refund.',
        'response' => $respons,
    ];
}

// =====================================================================
// PROSES NOTIFIKASI/STATUS REFUND MIDTRANS
// Status refund dicatat TERPISAH dari payment_status dan dari status
// persetujuan pembatalan.
// =====================================================================
function midtransProcessRefundStatus($payment, $data, $statusDetail = null)
{
    global $koneksi;

    $refundAmount = null;
    if (isset($data['refund_amount']) && $data['refund_amount'] !== '') {
        $refundAmount = (float) $data['refund_amount'];
    } elseif (isset($data['gross_amount']) && $data['gross_amount'] !== '') {
        $refundAmount = (float) $data['gross_amount'];
    } else {
        $refundAmount = (float) $payment['amount'];
    }

    $refundKey = isset($data['refund_key']) ? (string) $data['refund_key'] : null;
    $rawJson = !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;

    try {
        $koneksi->beginTransaction();

        $lock = $koneksi->prepare(
            "SELECT id, decision_status, refund_status
             FROM cancellation_requests
             WHERE booking_id = ?
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $lock->execute([(int) $payment['booking_id']]);
        $req = $lock->fetch();

        if ($req && $req['decision_status'] === 'approved' && $req['refund_status'] !== 'success') {
            $upd = $koneksi->prepare(
                "UPDATE cancellation_requests
                 SET refund_status = 'success',
                     refund_amount = COALESCE(refund_amount, ?),
                     refund_key = COALESCE(refund_key, ?),
                     refund_confirmed_at = NOW(),
                     refund_note = ?
                 WHERE id = ?"
            );
            $upd->execute([
                $refundAmount,
                $refundKey,
                'Refund dikonfirmasi Midtrans.',
                (int) $req['id'],
            ]);
        }

        $pay = $koneksi->prepare(
            "UPDATE payments
             SET payment_status = 'refunded',
                 status_detail = COALESCE(?, 'Refund dikonfirmasi Midtrans'),
                 raw_notification = COALESCE(?, raw_notification)
             WHERE id = ?"
        );
        $pay->execute([$statusDetail, $rawJson, (int) $payment['id']]);

        $koneksi->commit();
    } catch (Throwable $e) {
        if ($koneksi->inTransaction()) {
            $koneksi->rollBack();
        }
        error_log('[Midtrans] Gagal menyimpan status refund: ' . $e->getMessage());
        return ['ok' => false, 'retryable' => true, 'error' => 'Gagal menyimpan status refund.'];
    }

    return [
        'ok'             => true,
        'payment_status' => 'refunded',
        'booking_status' => $payment['booking_status'],
        'label'          => 'Refund dikonfirmasi Midtrans',
    ];
}

// =====================================================================
// PROSES STATUS TRANSAKSI MIDTRANS -> UPDATE DATABASE (IDEMPOTEN)
//
// Dipanggil dari:
//   - midtrans_notification.php (webhook resmi Midtrans)
//   - cek_status_pembayaran.php (pengecekan server-to-server, untuk
//     user dan untuk pengujian lokal di Laragon)
//
// $sumber: 'notification' | 'status_api'
// =====================================================================
function midtransProcessStatus($orderId, $sumber, $notifikasi = [])
{
    global $koneksi;

    // ===== 1. Cari payment berdasarkan order_id =====
    $stmt = $koneksi->prepare(
        "SELECT payments.*, bookings.booking_status, bookings.id AS bid, bookings.user_id
         FROM payments
         JOIN bookings ON bookings.id = payments.booking_id
         WHERE payments.midtrans_order_id = ?"
    );
    $stmt->execute([$orderId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        return ['ok' => false, 'error' => 'order_id tidak dikenali.'];
    }

    // ===== 2. Ambil status resmi dari API Midtrans (sumber kebenaran) =====
    // Untuk notifikasi, payload sudah membawa status; tetapi kita TETAP
    // verifikasi dengan memanggil API status (ketika $notifikasi kosong),
    // atau pakai payload notifikasi yang sudah diverifikasi signature.
    // Data mentah dipakai untuk snapshot notifikasi / status refund.
    $dataMentah = $notifikasi;

    if (!empty($notifikasi)) {
        $status       = isset($notifikasi['transaction_status']) ? $notifikasi['transaction_status'] : '';
        $fraudStatus  = isset($notifikasi['fraud_status']) ? $notifikasi['fraud_status'] : null;
        $statusCode   = isset($notifikasi['status_code']) ? $notifikasi['status_code'] : null;
        $grossAmount  = isset($notifikasi['gross_amount']) ? (int) $notifikasi['gross_amount'] : null;
        $paymentType  = isset($notifikasi['payment_type']) ? $notifikasi['payment_type'] : null;
        $paymentCode  = isset($notifikasi['payment_code']) ? $notifikasi['payment_code'] : null;
        $bank         = isset($notifikasi['bank']) ? $notifikasi['bank'] : null;
        $transactionId= isset($notifikasi['transaction_id']) ? $notifikasi['transaction_id'] : null;
        $statusDetail = isset($notifikasi['status_message']) ? $notifikasi['status_message'] : null;
    } else {
        try {
            $r = \Midtrans\Transaction::status($orderId);
            // Library mengembalikan stdClass; samakan ke array agar konsisten.
            $r = (array) $r;
        } catch (Exception $e) {
            error_log('[Midtrans] Transaction::status gagal: ' . $e->getMessage());
            return ['ok' => false, 'retryable' => true, 'error' => 'Gagal mengambil status dari Midtrans.'];
        }
        $status       = isset($r['transaction_status']) ? $r['transaction_status'] : '';
        $fraudStatus  = isset($r['fraud_status']) ? $r['fraud_status'] : null;
        $statusCode   = isset($r['status_code']) ? $r['status_code'] : null;
        $grossAmount  = isset($r['gross_amount']) ? (int) $r['gross_amount'] : null;
        $paymentType  = isset($r['payment_type']) ? $r['payment_type'] : null;
        $paymentCode  = isset($r['payment_code']) ? $r['payment_code'] : null;
        $bank         = isset($r['bank']) ? $r['bank'] : null;
        $transactionId= isset($r['transaction_id']) ? $r['transaction_id'] : null;
        $statusDetail = isset($r['status_message']) ? $r['status_message'] : null;
        $dataMentah   = $r;
    }

    // ===== 3. VALIDASI KEASLIAN =====
    // Nominal harus cocok dengan database
    if ($grossAmount !== null && $grossAmount !== (int) round((float) $payment['amount'])) {
        return ['ok' => false, 'error' => 'Nominal transaksi tidak sesuai dengan booking.'];
    }

    // transaction_status harus string yang jelas
    if (!is_string($status) || $status === '') {
        return ['ok' => false, 'error' => 'transaction_status tidak valid.'];
    }

    // "settlement" hanya sah jika status_code = 200. status_code ikut
    // ditandatangani notifikasi, sedangkan transaction_status tidak — jadi
    // payload pending (201) yang dimodifikasi menjadi settlement DITOLAK di sini.
    if ($status === 'settlement' && (string) $statusCode !== '200') {
        return ['ok' => false, 'error' => 'Settlement dengan kode status bukan 200 ditolak.'];
    }

    // ===== REFUND: ditangani terpisah dari status pembayaran biasa =====
    // Hanya status refund resmi (status_code 200) yang boleh menandai refund
    // berhasil. Status refund disimpan di cancellation_requests.
    if (in_array($status, ['refund', 'partial_refund'], true)) {
        if ((string) $statusCode !== '200') {
            return ['ok' => false, 'error' => 'Status refund dengan kode status bukan 200 ditolak.'];
        }
        return midtransProcessRefundStatus($payment, $dataMentah, $statusDetail);
    }

    // ===== 4. MAP STATUS MIDTRANS -> STATUS ARENAKU =====
    // Aturan konsisten (lihat dokumen Midtrans):
    //   settlement / capture(fraud accept) -> paid
    //   pending                            -> pending
    //   deny / cancel / failure            -> failed
    //   expire                             -> expired
    $statusBaru    = null; // null = tidak ada perubahan (idempoten)
    $bookingBaru   = null;
    $paidAt        = null;
    $labelStatus   = '';

    switch ($status) {
        case 'settlement':
            $statusBaru  = 'paid';
            $bookingBaru = 'confirmed';
            $paidAt      = date('Y-m-d H:i:s');
            $labelStatus = 'Pembayaran berhasil';
            break;

        case 'capture':
            if ($fraudStatus === 'accept') {
                // fraud_status tidak ikut ditandatangani notifikasi, dan kartu
                // kredit tidak aktif di metode Arenaku — jadi konfirmasi ulang
                // lewat API status sebelum menaikkan ke paid.
                if ($sumber === 'notification') {
                    try {
                        $konfirmasi = \Midtrans\Transaction::status($orderId);
                        $konfirmasi = (array) $konfirmasi;
                    } catch (Exception $e) {
                        error_log('[Midtrans] Gagal konfirmasi capture: ' . $e->getMessage());
                        return ['ok' => false, 'retryable' => true, 'error' => 'Gagal mengkonfirmasi transaksi capture.'];
                    }
                    $statusKonfirmasi = isset($konfirmasi['transaction_status']) ? $konfirmasi['transaction_status'] : '';
                    if ($statusKonfirmasi !== 'settlement' && $statusKonfirmasi !== 'capture') {
                        return ['ok' => false, 'retryable' => true, 'error' => 'Konfirmasi API tidak cocok dengan notifikasi capture.'];
                    }
                    if (isset($konfirmasi['fraud_status']) && $konfirmasi['fraud_status'] !== 'accept') {
                        $statusBaru  = 'pending';
                        $bookingBaru = null;
                        $labelStatus = 'Pembayaran sedang ditinjau';
                        break;
                    }
                }
                $statusBaru  = 'paid';
                $bookingBaru = 'confirmed';
                $paidAt      = date('Y-m-d H:i:s');
                $labelStatus = 'Pembayaran berhasil';
            } else {
                // challenge/deny -> perlakukan sebagai pending manual
                $statusBaru  = 'pending';
                $bookingBaru = null;
                $labelStatus = 'Pembayaran sedang ditinjau';
            }
            break;

        case 'pending':
            $statusBaru  = 'pending';
            $bookingBaru = null;
            $labelStatus = 'Menunggu pembayaran';
            break;

        case 'deny':
        case 'cancel':
        case 'failure':
            $statusBaru  = 'failed';
            $bookingBaru = null;
            $labelStatus = 'Pembayaran gagal';
            break;

        case 'expire':
            $statusBaru  = 'expired';
            $bookingBaru = null;
            $labelStatus = 'Pembayaran kedaluwarsa';
            break;

        default:
            return ['ok' => false, 'error' => 'Status transaksi tidak dikenali: ' . clean($status)];
    }

    // ===== 5. IDEMPOTEN: Jangan "menurunkan" status yang sudah lebih tinggi =====
    // Urutan: unpaid < pending < paid; failed/expired tidak boleh menimpa paid
    $urutan = ['unpaid' => 0, 'pending' => 1, 'expired' => 2, 'failed' => 2, 'paid' => 3, 'refunded' => 4];
    $sekarang = $payment['payment_status'];

    $bolehUpdate = true;
    if ($statusBaru === 'paid' && $sekarang === 'paid') {
        $bolehUpdate = false; // sudah lunas, notifikasi ulang tidak perlu apa-apa
    } elseif (isset($urutan[$statusBaru]) && isset($urutan[$sekarang])
              && $urutan[$statusBaru] < $urutan[$sekarang]) {
        $bolehUpdate = false; // jangan turunkan status (mis. pending datang setelah paid)
    }

    // Booking hanya boleh naik ke 'confirmed' dari 'pending_payment'.
    // Notifikasi terlambat TIDAK boleh menghidupkan kembali booking yang
    // sudah cancelled, atau menurunkan booking completed.
    if ($bookingBaru === 'confirmed' && $payment['booking_status'] !== 'pending_payment') {
        $bookingBaru = null;
    }

    // Payment yang masuk SETELAH booking dibatalkan tidak boleh menghidupkan
    // booking. Dana yang terlanjur masuk ditandai agar admin menindaklanjuti
    // refund (status refund tetap terpisah dari status pembayaran).
    $perluTindakanRefund = ($statusBaru === 'paid' && $payment['booking_status'] === 'cancelled');

    if ($bolehUpdate || $bookingBaru !== null || !empty($notifikasi)) {
        // Selalu simpan snapshot notifikasi terakhir (tidak berisi data kartu)
        $rawJson = !empty($notifikasi) ? json_encode($notifikasi, JSON_UNESCAPED_UNICODE) : null;

        if ($bolehUpdate) {
            $stmtPay = $koneksi->prepare(
                "UPDATE payments
                 SET payment_status = ?,
                     payment_type = COALESCE(?, payment_type),
                     payment_code = COALESCE(?, payment_code),
                     bank = COALESCE(?, bank),
                     fraud_status = COALESCE(?, fraud_status),
                     status_detail = ?,
                     midtrans_transaction_id = COALESCE(?, midtrans_transaction_id),
                     raw_notification = COALESCE(?, raw_notification),
                     paid_at = COALESCE(?, paid_at)
                 WHERE id = ?"
            );
            $stmtPay->execute([
                $statusBaru, $paymentType, $paymentCode, $bank, $fraudStatus,
                $labelStatus, $transactionId, $rawJson, $paidAt, $payment['id'],
            ]);

            // Jaga konsistensi status: transaksi yang terverifikasi Midtrans
            // (lunas) tidak boleh berbenturan dengan verifikasi bukti yang
            // masih pending / ditolak.
            if ($statusBaru === 'paid'
                && in_array($payment['proof_status'] ?? 'none', ['pending', 'rejected'], true)) {
                $stmtProof = $koneksi->prepare(
                    "UPDATE payments SET proof_status = 'approved', rejection_reason = NULL WHERE id = ?"
                );
                $stmtProof->execute([(int) $payment['id']]);
            }
        } elseif ($rawJson !== null) {
            // Notifikasi berulang dengan status sama -> cukup simpan snapshot
            $stmtPay = $koneksi->prepare(
                "UPDATE payments SET raw_notification = ? WHERE id = ?"
            );
            $stmtPay->execute([$rawJson, $payment['id']]);
        }

        if ($bookingBaru !== null) {
            $stmtBook = $koneksi->prepare(
                "UPDATE bookings SET booking_status = ? WHERE id = ? AND booking_status != ?"
            );
            $stmtBook->execute([$bookingBaru, $payment['bid'], $bookingBaru]);
        }
    }

    if ($perluTindakanRefund) {
        $stmtTindak = $koneksi->prepare(
            "UPDATE cancellation_requests
             SET refund_status = 'failed',
                 refund_note = 'Pembayaran masuk setelah pembatalan disetujui. Refund perlu tindakan admin.'
             WHERE booking_id = ?
               AND decision_status = 'approved'
               AND refund_status IN ('none', 'failed')"
        );
        $stmtTindak->execute([(int) $payment['booking_id']]);
    }

    return [
        'ok'             => true,
        'payment_status' => $bolehUpdate ? $statusBaru : $sekarang,
        'booking_status' => $bookingBaru !== null ? $bookingBaru : $payment['booking_status'],
        'label'          => $labelStatus,
    ];
}

// =====================================================================
// VERIFIKASI SIGNATURE NOTIFIKASI MIDTRANS
// signature_key = sha512(order_id + status_code + gross_amount + serverKey)
// =====================================================================
function midtransVerifySignature($payload, $serverKey)
{
    if (!isset($payload['order_id'], $payload['status_code'], $payload['gross_amount'], $payload['signature_key'])) {
        return false;
    }
    $expected = hash(
        'sha512',
        $payload['order_id'] . $payload['status_code'] . $payload['gross_amount'] . $serverKey
    );
    return hash_equals($expected, $payload['signature_key']);
}
