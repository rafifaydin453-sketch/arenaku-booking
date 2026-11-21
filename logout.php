<?php
session_start();

// Hapus semua data session
$_SESSION = [];
session_destroy();

// Arahkan kembali ke halaman login
header('Location: /arenaku/login.php');
exit;