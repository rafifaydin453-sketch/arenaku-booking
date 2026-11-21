<?php
/**
 * CONTOH konfigurasi environment ArenaKu.
 * Salin file ini menjadi config/environment.php lalu sesuaikan.
 *
 * DI PRODUCTION:
 * - JANGAN upload config/environment.php sama sekali, ATAU isi seperti contoh
 *   di bawah ini (simulation_enabled = false). Dengan begitu seluruh endpoint
 *   simulasi otomatis ditolak di sisi server (respon 404).
 */

return [
    // 'development' | 'production'
    'environment' => 'production',

    // false di production. true HANYA untuk development/testing.
    'simulation_enabled' => false,
];
