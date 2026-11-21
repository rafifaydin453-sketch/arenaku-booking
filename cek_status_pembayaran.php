<?php
/**
 * CEK STATUS PEMBAYARAN (server-to-server)
 * ----------------------------------------
 * Dipanggil via AJAX dari detail_booking.php.
 *
 * Backend memanggil API status Midtrans (bukan percaya callback browser),
 * lalu memproses hasilnya dengan aturan yang SAMA dengan notifikasi
 * (idempoten, validasi nominal, dsb.).
 *
 * Selain untuk user, endpoint ini juga menjadi jalur pengujian utama
 * untuk Laragon/localhost, karena webhook Midtrans tidak bisa menembus
 * localhost.
 */

session_start();
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/midtrans_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$bookingId = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;

// ===== CARI BOOKING + PASTIKAN MILIK USER =====
$stmt = $koneksi->prepare(
    "SELECT bookings.*, payments.payment_status, payments.midtrans_order_id
     FROM bookings
     JOIN payments ON payments.booking_id = bookings.id
     WHERE bookings.id = ? AND bookings.user_id = ?"
);
$stmt->execute([$bookingId, $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking) {
    echo json_encode(['ok' => false, 'error' => 'Booking tidak ditemukan atau bukan milik kamu.']);
    exit;
}

// ===== HARUS ADA ORDER MIDTRANS =====
if (empty($booking['midtrans_order_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Belum ada transaksi Midtrans untuk booking ini.']);
    exit;
}

if (!midtransReady()) {
    echo json_encode(['ok' => false, 'error' => 'Konfigurasi Midtrans belum diisi.']);
    exit;
}

// ===== TANYA STATUS KE API MIDTRANS (SOURCES OF TRUTH) =====
try {
    $hasil = midtransProcessStatus($booking['midtrans_order_id'], 'status_api');
} catch (Exception $e) {
    error_log('[Midtrans] cek_status exception: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Gagal menghubungi Midtrans. Coba lagi nanti.']);
    exit;
}

if (!$hasil['ok']) {
    echo json_encode(['ok' => false, 'error' => $hasil['error']]);
    exit;
}

// ===== KIRIM STATUS TERBARU (DARI DATABASE) =====
$stmt = $koneksi->prepare(
    "SELECT payments.payment_status, payments.payment_code, payments.bank,
            payments.payment_type, payments.status_detail, payments.paid_at,
            bookings.booking_status
     FROM payments
     JOIN bookings ON bookings.id = payments.booking_id
     WHERE payments.booking_id = ?"
);
$stmt->execute([$bookingId]);
$terbaru = $stmt->fetch();

echo json_encode([
    'ok'             => true,
    'payment_status' => $terbaru['payment_status'],
    'booking_status' => $terbaru['booking_status'],
    'label'          => $hasil['label'],
    'payment_code'   => $terbaru['payment_code'],
    'bank'           => $terbaru['bank'],
    'paid_at'        => $terbaru['paid_at'],
]);
