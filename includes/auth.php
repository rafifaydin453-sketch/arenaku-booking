<?php
/**
 * Proteksi halaman: WAJIB LOGIN (role apapun boleh, tapi harus login)
 * Cara pakai: taruh require_once ini di baris paling atas halaman yang butuh login
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    // Belum login -> lempar ke halaman login
    redirect('/arenaku/login.php');
}