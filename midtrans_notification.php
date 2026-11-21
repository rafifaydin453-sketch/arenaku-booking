<?php
/**
 * ENDPOINT NOTIFIKASI MIDTRANS (webhook)
 * --------------------------------------
 * Midtrans akan mengirim POST JSON ke sini setiap ada perubahan status
 * transaksi. URL publik yang didaftarkan di dashboard Midtrans:
 *
 *     https://domain-kamu.com/arenaku/midtrans_notification.php
 *
 * PENTING UNTUK LARAGON/LOCALHOST:
 * Server Midtrans TIDAK bisa mengakses http://localhost/... .
 * Untuk pengujian lokal gunakan salah satu:
 *   1. Panggil endpoint ini manual (curl/Postman) dengan payload + signature
 *      yang dihitung dengan Server Key Sandbox-mu (lihat CATATAN PENGUJIANAN
 *      di bagian bawah file ini), atau
 *   2. Pakai tunnel publik (ngrok / localtunnel) lalu daftarkan URL tunnel
 *      sebagai Notification URL di dashboard Midtrans Sandbox.
 *
 * Keamanan:
 * - Signature diverifikasi dengan Server Key (hash sha512).
 * - Nominal dicocokkan dengan database.
 * - Proses idempoten: notifikasi berulang tidak merusak data.
 * - Respons 200 hanya jika sudah diproses ATAU memang tidak bisa
 *   diproses secara permanen (mis. order_id asing); kegagalan sementara
 *   (database/API) dibalas 500 agar Midtrans mengulang kirim notifikasi.
 */

header('Content-Type: application/json');

// Endpoint ini tidak butuh session/login -> jangan load auth
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/midtrans_helper.php';

// Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Baca body JSON
$raw   = file_get_contents('php://input');
$notif = json_decode($raw, true);

if (!is_array($notif) || empty($notif['order_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload tidak valid']);
    exit;
}

$config = midtransConfig();

// ===== VERIFIKASI SIGNATURE =====
if (!$config['configured'] || !midtransVerifySignature($notif, $config['server_key'])) {
    http_response_code(403);
    error_log('[Midtrans] Notifikasi dengan signature tidak valid ditolak. order_id=' . $notif['order_id']);
    echo json_encode(['error' => 'Signature tidak valid']);
    exit;
}

// ===== PROSES (IDEMPOTEN) =====
try {
    $hasil = midtransProcessStatus($notif['order_id'], 'notification', $notif);

    if ($hasil['ok']) {
        echo json_encode(['status' => 'ok', 'payment_status' => $hasil['payment_status']]);
    } elseif (!empty($hasil['retryable'])) {
        // Kegagalan sementara (mis. API Midtrans tidak terjangkau) -> 500
        // supaya Midtrans mengulang kirim sampai berhasil diproses.
        error_log('[Midtrans] Kegagalan sementara memproses notifikasi: ' . $hasil['error']);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'reason' => $hasil['error']]);
    } else {
        // Data memang tidak bisa diproses (mis. order_id asing, nominal
        // salah) -> 200 agar Midtrans tidak retry tanpa henti; error dicatat.
        error_log('[Midtrans] Gagal memproses notifikasi: ' . $hasil['error']);
        echo json_encode(['status' => 'ignored', 'reason' => $hasil['error']]);
    }
} catch (Throwable $e) {
    // Termasuk error PHP yang tidak terduga / kegagalan database.
    error_log('[Midtrans] Exception memproses notifikasi: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error']);
}

/*
CATATAN PENGUJIANAN LOKAL (tanpa webhook publik)
------------------------------------------------
Contoh perintah PowerShell untuk mensimulasikan notifikasi settlement
(ganti SERVERKEY dengan Server Key Sandbox milikmu, dan sesuaikan
order_id/status_code/gross_amount dengan data di tabel payments):

  $orderId  = 'ARK-3-ABC12345'
  $statusCode = '200'
  $gross = '100000'
  $serverKey = 'SB-Mid-server-XXXX'
  $sig = (Get-FileHash -InputStream (
      [IO.MemoryStream]::new(
        [Text.Encoding]::UTF8.GetBytes($orderId + $statusCode + $gross + $serverKey)
      ) -Algorithm SHA512).Hash.ToLower()
  ... atau gunakan php:
  php -r "echo hash('sha512','ORDERID'.'200'.'100000'.'SERVERKEY');"

Lalu kirim POST JSON ke http://localhost/arenaku/midtrans_notification.php
dengan field: order_id, transaction_status='settlement', status_code,
gross_amount, signature_key, dll.
*/
