<?php
// File ini HARUS di-include SETELAH session_start() dan koneksi.php dipanggil
// di halaman yang memakainya.
require_once __DIR__ . '/icons.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173f2d">
    <meta name="description" content="<?= isset($pageDescription) ? clean($pageDescription) : 'ArenaKu — booking lapangan futsal, badminton, dan basket di Bekasi dengan jadwal real-time dan pembayaran aman.' ?>">
    <title>ArenaKu | Booking Lapangan Olahraga</title>
    <link rel="icon" href="<?= assetUrl('img/favicon.ico') ?>?v=1" sizes="any">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= assetUrl('img/favicon-16x16.png') ?>?v=1">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= assetUrl('img/favicon-32x32.png') ?>?v=1">
    <link rel="icon" type="image/png" sizes="48x48" href="<?= assetUrl('img/favicon-48x48.png') ?>?v=1">
    <link rel="apple-touch-icon" href="<?= assetUrl('img/apple-touch-icon.png') ?>?v=1">
    <link rel="preload" href="<?= assetUrl('fonts/bricolage-grotesque-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= assetUrl('fonts/instrument-sans-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>?v=5">
    <link rel="stylesheet" href="<?= assetUrl('css/pages.css') ?>?v=4">
    <script src="<?= assetUrl('js/app.js') ?>?v=4" defer></script>
</head>
<body class="<?= isset($bodyClass) ? clean($bodyClass) : '' ?>">
<a href="#main-content" class="skip-link">Lewati ke konten utama</a>
