<?php
/**
 * Konfigurasi Midtrans untuk ArenaKu.
 *
 * - Key diambil dari config/midtrans_keys.php (file lokal, tidak dipublikasikan).
 *   Salin midtrans_keys.example.php -> midtrans_keys.php lalu isi key Sandbox-mu
 *   dari https://dashboard.sandbox.midtrans.com/ -> Settings -> Access Keys.
 * - File ini TIDAK boleh diakses langsung dari browser (dilindungi .htaccess).
 */

$keysFile = __DIR__ . '/midtrans_keys.php';

if (!is_readable($keysFile)) {
    // Belum dikonfigurasi -> semua fitur Midtrans dinonaktifkan dengan pesan jelas
    return [
        'configured'  => false,
        'server_key'  => '',
        'client_key'  => '',
        'is_production' => false,
    ];
}

$keys = require $keysFile;

$serverKey = isset($keys['server_key']) ? trim($keys['server_key']) : '';
$clientKey = isset($keys['client_key']) ? trim($keys['client_key']) : '';

// Anggap belum dikonfigurasi kalau key masih placeholder
$configured = $serverKey !== ''
    && $clientKey !== ''
    && strpos($serverKey, 'XXXX') === false
    && strpos($clientKey, 'XXXX') === false;

return [
    'configured'    => $configured,
    'server_key'    => $serverKey,
    'client_key'    => $clientKey,
    'is_production' => !empty($keys['is_production']),
];
