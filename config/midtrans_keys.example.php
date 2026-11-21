<?php
/**
 * File SECRET KEY Midtrans (Sandbox).
 * Salin dari midtrans_keys.example.php lalu isi key milikmu dari
 * https://dashboard.sandbox.midtrans.com/ -> Settings -> Access Keys
 *
 * JANGAN commit file ini ke repository publik.
 * JANGAN panggil file ini langsung dari browser (isinya rahasia).
 */
return [
    // Server Key: dipakai di backend untuk membuat transaksi & verifikasi notifikasi
    'server_key' => 'SB-Mid-server-XXXXXXXXXXXXXXXXXXXX',

    // Client Key: hanya untuk memuat Snap di browser (bukan rahasia mutlak,
    // tetapi tetap jangan gabung dengan Server Key)
    'client_key' => 'SB-Mid-client-XXXXXXXXXXXXXXXXXXXX',

    // true = production, false = sandbox
    'is_production' => false,
];
