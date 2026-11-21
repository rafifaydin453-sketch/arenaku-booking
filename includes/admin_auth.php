<?php
/**
 * Proteksi halaman: WAJIB LOGIN SEBAGAI ADMIN
 * Cara pakai: taruh require_once ini di baris paling atas setiap halaman di folder admin/
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    // Belum login sama sekali -> ke halaman login
    redirect('/arenaku/login.php');
}

if (!isAdmin()) {
    // Sudah login tapi role-nya BUKAN admin -> tolak akses, jangan biarkan masuk
    die('Akses ditolak. Halaman ini khusus admin.');
}