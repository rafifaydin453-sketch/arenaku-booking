<?php
/**
 * CONTOH konfigurasi koneksi database lokal ArenaKu.
 *
 * Salin file ini menjadi config/koneksi.local.php HANYA jika kredensial
 * database kamu berbeda dari default Laragon (root tanpa password).
 * File config/koneksi.local.php sudah diabaikan oleh .gitignore.
 */

return [
    'host'   => 'localhost',
    'dbname' => 'arenaku',
    'user'   => 'root',
    'pass'   => '',
];
