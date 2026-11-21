<?php
/**
 * File koneksi database ArenaKu
 * Menggunakan PDO (PHP Data Objects) supaya:
 * - Bisa pakai prepared statement (aman dari SQL Injection)
 * - Error handling lebih jelas lewat Exception
 */

// Set zona waktu default aplikasi ke Asia/Jakarta (WIB)
date_default_timezone_set('Asia/Jakarta');

// Data koneksi database
// Default Laragon: host localhost, user root, password kosong.
// Untuk password/kredensial khusus, salin config/koneksi.local.example.php
// menjadi config/koneksi.local.php lalu isi di sana. File local tersebut
// sudah masuk .gitignore sehingga tidak ikut ter-upload ke GitHub.
$dbConfig = [
    'host'   => 'localhost',
    'dbname' => 'arenaku',
    'user'   => 'root',
    'pass'   => '',
];

$localConfigFile = __DIR__ . '/koneksi.local.php';
if (is_readable($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (is_array($localConfig)) {
        foreach (['host', 'dbname', 'user', 'pass'] as $key) {
            if (array_key_exists($key, $localConfig)) {
                $dbConfig[$key] = $localConfig[$key];
            }
        }
    }
}

$host   = $dbConfig['host'];
$dbname = $dbConfig['dbname'];
$dbuser = $dbConfig['user'];
$dbpass = $dbConfig['pass'];

// DSN (Data Source Name) - format alamat koneksi untuk MySQL
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

// Opsi PDO
$options = [
    // Kalau ada error SQL, PDO akan melempar Exception (bukan diam-diam gagal)
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

    // Hasil query default berupa associative array, contoh: $row['name']
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

    // Matikan emulasi prepared statement, supaya benar-benar pakai
    // prepared statement asli dari MySQL (lebih aman)
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    // Buat koneksi PDO
    $koneksi = new PDO($dsn, $dbuser, $dbpass, $options);
    // Samakan zona waktu sesi MySQL ke Asia/Jakarta (+07:00)
    $koneksi->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    // Kalau koneksi gagal, hentikan eksekusi dan tampilkan pesan yang jelas
    // (di production nanti pesan error sebaiknya tidak menampilkan detail teknis ke user)
    die('Koneksi database gagal: ' . $e->getMessage());
}

// Variabel $koneksi ini yang akan dipakai di semua file lain,
// caranya nanti dengan: require_once 'config/koneksi.php';