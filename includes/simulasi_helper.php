<?php
/**
 * MODE SIMULASI PEMBAYARAN (khusus development/testing)
 * -----------------------------------------------------
 * Fitur tambahan untuk menguji alur booking & pembayaran ArenaKu
 * TANPA uang sungguhan dan TANPA menyentuh integrasi Midtrans.
 *
 * Aturan penting:
 * - Aktif hanya jika config/environment.php menyatakan
 *   environment = 'development' DAN simulation_enabled = true,
 *   DAN Midtrans tidak dalam mode production.
 *   Aktifasi TIDAK dilihat dari URL / query string / cookie / form / JS.
 * - Bukti pembayaran simulasi hanya diproses DI SERVER:
 *   verifikasi kepemilikan booking, status booking, nominal, format file,
 *   dengan transaction + SELECT ... FOR UPDATE. Tidak memercayai browser.
 * - Mengirim bukti TIDAK langsung melunasi booking. Status pembayaran
 *   menjadi 'pending' (Menunggu Keputusan Admin) sampai admin menerima
 *   atau menolak bukti tersebut.
 * - Tidak ada data yang dikirim ke Midtrans selama simulasi; transaksi
 *   asli Midtrans tetap lewat jalur notifikasi/cek-status yang ada.
 * - Semua pembayaran simulasi ditandai payments.is_simulated = 1 dan
 *   payment_type berawalan 'simulasi_' supaya bisa dibedakan dari
 *   transaksi nyata Tanpa merusak skema status aplikasi.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/../config/koneksi.php';

// =====================================================================
// PEMBACA KONFIGURASI ENVIRONMENT
// =====================================================================
function simulasiConfig()
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = ['aktif' => false, 'alasan' => ''];

        $file = __DIR__ . '/../config/environment.php';
        if (!is_readable($file)) {
            $cfg['alasan'] = 'config/environment.php belum dibuat.';
            return $cfg;
        }

        $env = require $file;
        if (!is_array($env)) {
            $cfg['alasan'] = 'Format config/environment.php tidak valid.';
            return $cfg;
        }

        if (($env['environment'] ?? '') !== 'development') {
            $cfg['alasan'] = 'environment bukan development.';
            return $cfg;
        }

        if (($env['simulation_enabled'] ?? false) !== true) {
            $cfg['alasan'] = 'simulation_enabled tidak aktif.';
            return $cfg;
        }

        // Pertahanan lapis kedua: kalau kunci Midtrans menunjuk production,
        // simulasi WAJIB dianggap mati apa pun isi environment.php.
        $keysFile = __DIR__ . '/../config/midtrans_keys.php';
        if (is_readable($keysFile)) {
            $keys = require $keysFile;
            if (!empty($keys['is_production'])) {
                $cfg['alasan'] = 'Midtrans berstatus production.';
                return $cfg;
            }
        }

        $cfg['aktif']  = true;
        $cfg['alasan'] = '';
    }
    return $cfg;
}

// Dipakai halaman & endpoint: true hanya di development yang dikonfigurasi eksplisit
function simulasiAktif()
{
    return simulasiConfig()['aktif'] === true;
}

// =====================================================================
// LOKASI PENYIMPANAN BUKTI PEMBAYARAN
// =====================================================================
function simulasiBuktiDir()
{
    return dirname(__DIR__) . '/assets/img/bukti';
}

// URL publik untuk ditampilkan ke pengguna & admin (file gambar saja).
function simulasiBuktiUrl($fileName)
{
    $name = basename(str_replace('\\', '/', (string) $fileName));
    if ($name === '' || $name === '.' || $name === '..') {
        return '';
    }
    return assetUrl('img/bukti/' . $name);
}

function simulasiHapusBuktiFile($fileName)
{
    $name = basename(str_replace('\\', '/', (string) $fileName));
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }

    $path = simulasiBuktiDir() . DIRECTORY_SEPARATOR . $name;
    return is_file($path) ? @unlink($path) : false;
}

// Daftar provider e-wallet simulasi. Label & logo dipakai halaman user/admin.
function simulasiEwalletProviders()
{
    return [
        'gopay' => [
            'label' => 'GoPay',
            'logo'  => 'img/providers/gopay.svg',
            'desc'  => 'Simulasi internal ArenaKu, bukan aplikasi Gojek.',
        ],
        'shopeepay' => [
            'label' => 'ShopeePay',
            'logo'  => 'img/providers/shopeepay.svg',
            'desc'  => 'Simulasi internal ArenaKu, bukan aplikasi Shopee.',
        ],
        'dana' => [
            'label' => 'DANA',
            'logo'  => 'img/providers/dana.svg',
            'desc'  => 'Simulasi internal ArenaKu, bukan aplikasi DANA.',
        ],
    ];
}

function simulasiEwalletLabel($channel)
{
    $providers = simulasiEwalletProviders();
    return isset($providers[$channel]) ? $providers[$channel]['label'] : '';
}

function simulasiEwalletLogoUrl($channel)
{
    $providers = simulasiEwalletProviders();
    return isset($providers[$channel]) ? assetUrl($providers[$channel]['logo']) : '';
}

// Provider e-wallet dibaca dari payments.payment_type ('simulasi_gopay', dst).
function simulasiEwalletChannelDariPayment($payment)
{
    if (!is_array($payment)) {
        return '';
    }
    if (isset($payment['payment_method']) && $payment['payment_method'] !== 'e_wallet') {
        return '';
    }

    $type = (string) ($payment['payment_type'] ?? '');
    if (strpos($type, 'simulasi_') !== 0) {
        return '';
    }

    $providers = simulasiEwalletProviders();
    $channel = substr($type, strlen('simulasi_'));
    return isset($providers[$channel]) ? $channel : '';
}

function simulasiEwalletProviderLabel($payment)
{
    $channel = simulasiEwalletChannelDariPayment($payment);
    return $channel !== '' ? simulasiEwalletLabel($channel) : '';
}

// Label status yang konsisten untuk pembayaran simulasi.
// Mengembalikan null untuk pembayaran non-simulasi (pakai labelStatusPembayaran).
// Versi baru juga membaca proof_status (status verifikasi bukti oleh admin),
// sehingga alur bukti Midtrans yang ditutup (non-simulasi) ikut terlabel benar.
function simulasiStatusPembayaran($payment)
{
    if (!is_array($payment)) {
        return null;
    }

    $status      = (string) ($payment['payment_status'] ?? '');
    $hasProof    = !empty($payment['payment_proof']);
    $proofStatus = $payment['proof_status'] ?? null;
    $isSimulasi  = !empty($payment['is_simulated']);

    // Status verifikasi bukti (data baru) diprioritaskan
    if ($proofStatus === 'pending') {
        return ['Menunggu Keputusan Admin', 'badge-warning'];
    }
    if ($proofStatus === 'rejected') {
        return ['Pembayaran Ditolak', 'badge-danger'];
    }
    if ($proofStatus === 'approved') {
        return ['Pembayaran Berhasil', 'badge-success'];
    }

    // Pembayaran non-simulasi selalu memakai label standar Midtrans.
    if (!$isSimulasi) {
        return null;
    }

    // Perilaku lama (data simulasi lama / kolom proof_status belum terseleksi)
    if ($status === 'paid') {
        return ['Pembayaran Berhasil', 'badge-success'];
    }
    if ($status === 'pending' && $hasProof) {
        return ['Menunggu Keputusan Admin', 'badge-warning'];
    }
    if ($status === 'failed' && $hasProof) {
        return ['Pembayaran Ditolak', 'badge-danger'];
    }
    if ($status === 'unpaid') {
        return ['Menunggu Bukti Pembayaran', 'badge-warning'];
    }

    return null;
}

// =====================================================================
// VALIDASI & PENYIMPANAN FILE BUKTI
// =====================================================================
function simulasiSimpanBuktiUpload($file, array &$errors)
{
    if (!is_array($file) || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Unggah gambar bukti pembayaran terlebih dahulu.';
        return null;
    }

    $errorCode = (int) $file['error'];
    if ($errorCode !== UPLOAD_ERR_OK) {
        $errors[] = uploadErrorMessage($errorCode);
        return null;
    }

    $tmpPath = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $errors[] = 'File upload tidak valid.';
        return null;
    }

    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size <= 0) {
        $errors[] = 'File gambar kosong atau tidak terbaca.';
        return null;
    }
    if ($size > 3 * 1024 * 1024) {
        $errors[] = 'Ukuran gambar maksimal 3 MB.';
        return null;
    }

    $originalName = isset($file['name']) ? (string) $file['name'] : '';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png'];
    if (!in_array($extension, $allowedExtensions, true)) {
        $errors[] = 'Format gambar harus JPG, JPEG, atau PNG.';
        return null;
    }

    $allowedMimes = ['image/jpeg', 'image/png'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath);
    if (!in_array($mime, $allowedMimes, true)) {
        $errors[] = 'Tipe file tidak diizinkan. Gunakan gambar JPG, JPEG, atau PNG.';
        return null;
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false || empty($imageInfo['mime']) || !in_array($imageInfo['mime'], $allowedMimes, true)) {
        $errors[] = 'File bukan gambar yang valid.';
        return null;
    }

    $extensionMimeMap = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];
    if ($extensionMimeMap[$extension] !== $imageInfo['mime']) {
        $errors[] = 'Isi file tidak sesuai dengan ekstensi filenya.';
        return null;
    }

    $uploadDir = simulasiBuktiDir();
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        $errors[] = 'Folder penyimpanan bukti belum siap. Hubungi pengelola server.';
        return null;
    }

    $uniqueName = 'bukti-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $uniqueName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        $errors[] = 'Gambar gagal disimpan. Coba unggah ulang.';
        return null;
    }

    @chmod($targetPath, 0644);

    return $uniqueName;
}

// =====================================================================
// KIRIM BUKTI PEMBAYARAN (server-side, transactional)
// =====================================================================
/**
 * Versi UMUM: menyimpan bukti pembayaran untuk alur apapun (simulasi maupun
 * bukti setelah popup Midtrans ditutup). Booking TIDAK otomatis dikonfirmasi;
 * status pembayaran menjadi 'pending' (Menunggu Keputusan Admin) sampai
 * admin menerima atau menolak bukti tersebut.
 *
 * - $tandaiSimulasi = true  : perilaku lama halaman simulasi (is_simulated=1,
 *                             payment_type simulasi_*, kode SIM, VA sim-bca).
 * - $tandaiSimulasi = false : data Midtrans (payment_type/payment_code/bank)
 *                             dipertahankan, is_simulated tidak diubah.
 *
 * @param int    $bookingId id booking dari form
 * @param string $metode    'qris' | 'transfer_bank' | 'e_wallet'
 * @param string $channel   kanal e-wallet (hanya untuk simulasi)
 * @param array  $file      elemen $_FILES['bukti']
 * @param int    $userId    id user yang sedang login
 * @param bool   $tandaiSimulasi tandai transaksi sebagai simulasi
 * @return array ['ok'=>bool, 'error'=>string|null, 'redirect'=>string|null,
 *                'idempotent'=>bool, 'message'=>string|null]
 */
function pembayaranKirimBukti($bookingId, $metode, $channel, $file, $userId, $tandaiSimulasi = false)
{
    global $koneksi;

    $metodeValid = ['qris', 'transfer_bank', 'e_wallet'];

    if (!in_array($metode, $metodeValid, true)) {
        return ['ok' => false, 'error' => 'Metode pembayaran tidak valid.'];
    }

    if ($tandaiSimulasi) {
        $channelValid = array_keys(simulasiEwalletProviders());
        if ($metode === 'e_wallet') {
            if (!in_array($channel, $channelValid, true)) {
                return ['ok' => false, 'error' => 'Pilih provider e-wallet terlebih dahulu.'];
            }
        } else {
            $channel = '';
        }
    } else {
        $channel = '';
    }

    $fileTersimpan = null;

    try {
        $koneksi->beginTransaction();

        $stmt = $koneksi->prepare(
            "SELECT bookings.*, payments.id AS payment_id, payments.payment_status,
                    payments.is_simulated, payments.payment_proof, payments.proof_status,
                    payments.midtrans_order_id, payments.payment_type,
                    payments.payment_code, payments.bank, payments.amount
             FROM bookings
             JOIN payments ON payments.booking_id = bookings.id
             WHERE bookings.id = ? AND bookings.user_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$bookingId, $userId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Booking tidak ditemukan atau bukan milik kamu.'];
        }

        // Sudah lunas (mis. keputusan admin sudah diterima) -> tidak perlu kirim ulang.
        if ($booking['payment_status'] === 'paid') {
            $koneksi->rollBack();
            return [
                'ok'         => true,
                'idempotent' => true,
                'redirect'   => null,
                'message'    => 'Pembayaran sudah berhasil diverifikasi admin.',
            ];
        }

        if ($booking['booking_status'] !== 'pending_payment') {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Booking ini sudah tidak bisa dibayar (status: ' . $booking['booking_status'] . ').'];
        }

        // Bukti yang sudah dikirim tidak boleh ditimpa sebelum admin memutuskan.
        // (mencegah pengiriman bukti berulang saat status masih menunggu)
        if ($booking['proof_status'] === 'pending') {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Bukti pembayaran sudah dikirim dan sedang menunggu keputusan admin.'];
        }

        // Kompatibilitas data lama tanpa proof_status
        if ($booking['payment_status'] === 'pending' && !empty($booking['payment_proof'])) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Bukti pembayaran sudah dikirim dan sedang menunggu keputusan admin.'];
        }

        if (!in_array($booking['payment_status'], ['unpaid', 'pending', 'failed', 'expired'], true)) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Status pembayaran saat ini tidak bisa mengirim bukti.'];
        }

        // Nominal harus cocok dengan total harga booking di DATABASE.
        $expected = (float) ($booking['total_after_discount'] !== null
            ? $booking['total_after_discount']
            : $booking['total_price']);
        if (abs((float) $booking['amount'] - $expected) > 0.01) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Nominal pembayaran tidak sesuai dengan booking.'];
        }

        $errors = [];
        $fileTersimpan = simulasiSimpanBuktiUpload($file, $errors);
        if ($fileTersimpan === null) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }

        $oldProof = (string) ($booking['payment_proof'] ?? '');

        if ($tandaiSimulasi) {
            $kodePembayaran = 'SIM-' . strtoupper(bin2hex(random_bytes(4)));
            $tipeSimulasi = ($metode === 'e_wallet')
                ? 'simulasi_' . $channel
                : 'simulasi_' . $metode;

            $stmtUpdate = $koneksi->prepare(
                "UPDATE payments
                 SET payment_status = 'pending',
                     payment_method = ?,
                     payment_type   = ?,
                     payment_code   = ?,
                     bank           = ?,
                     payment_proof  = ?,
                     payment_proof_uploaded_at = NOW(),
                     proof_status   = 'pending',
                     rejection_reason = NULL,
                     status_detail  = 'Menunggu keputusan admin',
                     verified_by    = NULL,
                     verified_at    = NULL,
                     paid_at        = NULL,
                     expire_time    = NULL,
                     is_simulated   = 1
                 WHERE id = ? AND payment_status != 'paid'"
            );
            $stmtUpdate->execute([
                $metode,
                $tipeSimulasi,
                $kodePembayaran,
                $metode === 'transfer_bank' ? 'sim-bca' : null,
                $fileTersimpan,
                $booking['payment_id'],
            ]);
        } else {
            // Alur bukti setelah popup Midtrans ditutup: data Midtrans
            // dipertahankan apa adanya, hanya bukti & status verifikasi yang diubah.
            $stmtUpdate = $koneksi->prepare(
                "UPDATE payments
                 SET payment_status = 'pending',
                     payment_method = ?,
                     payment_proof  = ?,
                     payment_proof_uploaded_at = NOW(),
                     proof_status   = 'pending',
                     rejection_reason = NULL,
                     status_detail  = 'Menunggu keputusan admin',
                     verified_by    = NULL,
                     verified_at    = NULL,
                     paid_at        = NULL
                 WHERE id = ? AND payment_status != 'paid'"
            );
            $stmtUpdate->execute([
                $metode,
                $fileTersimpan,
                $booking['payment_id'],
            ]);
        }

        if ($stmtUpdate->rowCount() === 0) {
            $koneksi->rollBack();
            if ($fileTersimpan !== null) {
                simulasiHapusBuktiFile($fileTersimpan);
            }
            return [
                'ok'         => true,
                'idempotent' => true,
                'redirect'   => null,
                'message'    => 'Pembayaran sudah berhasil diverifikasi admin.',
            ];
        }

        $koneksi->commit();

        // Ganti file lama setelah data baru benar-benar tersimpan.
        if ($oldProof !== '' && $oldProof !== $fileTersimpan) {
            simulasiHapusBuktiFile($oldProof);
        }

        return [
            'ok'         => true,
            'idempotent' => false,
            'redirect'   => null,
            'message'    => 'Bukti pembayaran berhasil dikirim. Menunggu keputusan admin.',
        ];

    } catch (Throwable $e) {
        if ($koneksi->inTransaction()) {
            $koneksi->rollBack();
        }
        if ($fileTersimpan !== null) {
            simulasiHapusBuktiFile($fileTersimpan);
        }
        error_log('[Bukti pembayaran] Gagal mengirim bukti: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Terjadi kesalahan saat mengirim bukti. Silakan coba lagi.'];
    }
}

/**
 * Wrapper alur simulasi (perilaku lama dipertahankan): menandai is_simulated=1
 * dan mengarahkan ke hasil pembayaran setelah bukti dikirim.
 */
function simulasiKirimBuktiPembayaran($bookingId, $metode, $channel, $file, $userId)
{
    $hasil = pembayaranKirimBukti($bookingId, $metode, $channel, $file, $userId, true);

    if ($hasil['ok']) {
        $hasil['redirect'] = '/arenaku/hasil_pembayaran.php?booking_id=' . (int) $bookingId;
    }

    return $hasil;
}

// =====================================================================
// KEPUTUSAN ADMIN ATAS BUKTI PEMBAYARAN
// =====================================================================
/**
 * Versi UMUM: berlaku untuk bukti simulasi maupun bukti dari alur Midtrans
 * yang ditutup. Hanya admin (via dashboard) yang memanggil fungsi ini.
 *
 * - terima : proof_status='approved', payment_status='paid', booking confirmed.
 * - tolak  : proof_status='rejected' + alasan wajib, payment_status='failed',
 *            booking tetap pending_payment agar pengguna bisa unggah ulang bukti.
 *
 * @param int    $paymentId id payments
 * @param int    $adminId   id admin yang memutuskan
 * @param string $keputusan 'terima' | 'tolak'
 * @param string $alasanTolak alasan penolakan (wajib untuk 'tolak')
 * @return array ['ok'=>bool, 'error'=>string|null, 'message'=>string|null]
 */
function pembayaranAdminPutusan($paymentId, $adminId, $keputusan, $alasanTolak = '')
{
    global $koneksi;

    if (!in_array($keputusan, ['terima', 'tolak'], true)) {
        return ['ok' => false, 'error' => 'Keputusan tidak valid.'];
    }

    $alasanTolak = trim((string) $alasanTolak);
    if ($keputusan === 'tolak' && $alasanTolak === '') {
        return ['ok' => false, 'error' => 'Alasan penolakan wajib diisi.'];
    }
    if (mb_strlen($alasanTolak) > 500) {
        return ['ok' => false, 'error' => 'Alasan penolakan maksimal 500 karakter.'];
    }

    try {
        $koneksi->beginTransaction();

        $stmt = $koneksi->prepare(
            "SELECT payments.id, payments.payment_status, payments.payment_proof,
                    payments.proof_status,
                    bookings.id AS booking_id, bookings.booking_status, bookings.booking_code
             FROM payments
             JOIN bookings ON bookings.id = payments.booking_id
             WHERE payments.id = ?
               AND payments.payment_proof IS NOT NULL
             FOR UPDATE"
        );
        $stmt->execute([(int) $paymentId]);
        $payment = $stmt->fetch();

        if (!$payment) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Bukti pembayaran tidak ditemukan.'];
        }

        if ($payment['payment_status'] === 'paid' || $payment['proof_status'] === 'approved') {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Pembayaran ini sudah diterima.'];
        }

        if ($payment['proof_status'] !== 'pending') {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Bukti pembayaran ini tidak sedang menunggu keputusan admin.'];
        }

        if ($keputusan === 'terima') {
            if (!in_array($payment['booking_status'], ['pending_payment', 'confirmed'], true)) {
                $koneksi->rollBack();
                return ['ok' => false, 'error' => 'Booking sudah tidak aktif, pembayaran tidak bisa diterima.'];
            }

            $stmt = $koneksi->prepare(
                "UPDATE payments
                 SET payment_status = 'paid',
                     proof_status   = 'approved',
                     status_detail  = 'Bukti diterima admin',
                     rejection_reason = NULL,
                     verified_by    = ?,
                     verified_at    = NOW(),
                     paid_at        = NOW(),
                     expire_time    = NULL
                 WHERE id = ? AND proof_status = 'pending'"
            );
            $stmt->execute([(int) $adminId, (int) $paymentId]);

            if ($stmt->rowCount() === 0) {
                $koneksi->rollBack();
                return ['ok' => false, 'error' => 'Status pembayaran berubah saat diproses. Coba lagi.'];
            }

            $stmt = $koneksi->prepare(
                "UPDATE bookings SET booking_status = 'confirmed'
                 WHERE id = ? AND booking_status = 'pending_payment'"
            );
            $stmt->execute([(int) $payment['booking_id']]);

            $koneksi->commit();
            return ['ok' => true, 'message' => 'Pembayaran diterima. Booking dikonfirmasi.'];
        }

        // Tolak: bukti ditandai ditolak + alasan wajib, booking tetap
        // pending_payment agar pengguna bisa unggah ulang bukti.
        $stmt = $koneksi->prepare(
            "UPDATE payments
             SET payment_status = 'failed',
                 proof_status   = 'rejected',
                 rejection_reason = ?,
                 status_detail  = 'Bukti ditolak admin',
                 verified_by    = ?,
                 verified_at    = NOW(),
                 paid_at        = NULL
             WHERE id = ? AND proof_status = 'pending'"
        );
        $stmt->execute([$alasanTolak, (int) $adminId, (int) $paymentId]);

        if ($stmt->rowCount() === 0) {
            $koneksi->rollBack();
            return ['ok' => false, 'error' => 'Status pembayaran berubah saat diproses. Coba lagi.'];
        }

        $koneksi->commit();
        return ['ok' => true, 'message' => 'Bukti pembayaran ditolak. Pengguna dapat mengunggah ulang bukti.'];

    } catch (Throwable $e) {
        if ($koneksi->inTransaction()) {
            $koneksi->rollBack();
        }
        error_log('[Bukti pembayaran] Gagal memproses keputusan admin: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Terjadi kesalahan saat menyimpan keputusan admin.'];
    }
}

/**
 * Wrapper alur simulasi (perilaku lama dipertahankan).
 */
function simulasiAdminPutusan($paymentId, $adminId, $keputusan)
{
    return pembayaranAdminPutusan($paymentId, $adminId, $keputusan, '');
}
