<?php
/**
 * ENDPOINT KIRIM BUKTI PEMBAYARAN SIMULASI (POST, AJAX)
 * ---------------------------------------------------
 * Hanya aktif di lingkungan development/testing yang dikonfigurasi eksplisit
 * lewat config/environment.php. Di production endpoint ini DIBALAS 404
 * apa pun yang dikirimkan (simulasi tidak bisa dipanggil, langsung dari
 * browser maupun curl).
 *
 * Endpoint ini TIDAK melunasi booking. Bukti pembayaran disimpan, lalu
 * status pembayaran menjadi 'pending' (Menunggu Keputusan Admin) sampai
 * admin menerima atau menolak bukti tersebut.
 *
 * Semua validasi dilakukan di server oleh simulasiKirimBuktiPembayaran():
 * kepemilikan booking, status, nominal, format/ukuran gambar, CSRF.
 * Tidak ada data transaksi yang dikirim ke Midtrans dari sini.
 */

session_start();
require_once 'config/koneksi.php';
require_once 'includes/simulasi_helper.php';

header('Content-Type: application/json');

// ===== GUARD ENVIRONMENT: tanpa konfigurasi eksplisit -> seolah 404 =====
// Ditaruh TERAKHIR sekali sebelum auth, supaya di production endpoint ini
// dibalas 404 bahkan sebelum mengecek sesi / apapun lainnya.
if (!simulasiAktif()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Endpoint tidak ditemukan.']);
    exit;
}

require_once 'includes/functions.php';
require_once 'includes/auth.php';               // WAJIB LOGIN

// ===== HANYA POST =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ===== PROTEKSI CSRF =====
$csrf = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!csrfVerifikasi($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token keamanan tidak valid. Muat ulang halaman.']);
    exit;
}

$bookingId = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
$metode    = isset($_POST['metode']) ? $_POST['metode'] : '';
$channel   = isset($_POST['channel']) ? trim($_POST['channel']) : '';
$bukti     = isset($_FILES['bukti']) ? $_FILES['bukti'] : null;

if ($bookingId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Booking tidak ditemukan.']);
    exit;
}

// ===== PROSES PENYIMPANAN BUKTI DI SERVER =====
$hasil = simulasiKirimBuktiPembayaran($bookingId, $metode, $channel, $bukti, (int) ($_SESSION['user_id'] ?? 0));

if ($hasil['ok']) {
    echo json_encode([
        'ok'         => true,
        'idempotent' => !empty($hasil['idempotent']),
        'redirect'   => $hasil['redirect'],
        'message'    => $hasil['message'] ?? null,
    ]);
} else {
    echo json_encode([
        'ok'    => false,
        'error' => $hasil['error'],
    ]);
}
