<?php
/**
 * Layout header untuk semua halaman admin.
 * WAJIB di-include setelah config/koneksi.php, functions.php, admin_auth.php.
 * Variabel opsional: $pageTitle, $adminActive (kunci menu aktif)
 */
require_once __DIR__ . '/icons.php';

$adminActive = isset($adminActive) ? $adminActive : '';
$adminMenu = [
    'dashboard'  => ['index.php',            'Dashboard',            'grid'],
    'user'       => ['kelola_user.php',      'Kelola User',          'users'],
    'lapangan'   => ['kelola_lapangan.php',  'Kelola Lapangan',      'field'],
    'booking'    => ['kelola_booking.php',   'Kelola Booking',       'calendar'],
    'pembatalan' => ['kelola_pembatalan.php','Pengajuan Pembatalan', 'x-circle'],
    'pembayaran' => ['kelola_pembayaran.php','Verifikasi Pembayaran','card'],
    'promo'      => ['kelola_promo.php',     'Kelola Promo',         'ticket'],
    'laporan'    => ['laporan.php',          'Laporan',              'chart'],
];
$adminTitle = isset($pageTitle) ? $pageTitle : 'Dashboard';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0d2a1d">
    <meta name="robots" content="noindex, nofollow">
    <title>ArenaKu | Booking Lapangan Olahraga</title>
    <link rel="icon" href="<?= assetUrl('img/favicon.ico') ?>?v=1" sizes="any">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= assetUrl('img/favicon-16x16.png') ?>?v=1">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= assetUrl('img/favicon-32x32.png') ?>?v=1">
    <link rel="icon" type="image/png" sizes="48x48" href="<?= assetUrl('img/favicon-48x48.png') ?>?v=1">
    <link rel="apple-touch-icon" href="<?= assetUrl('img/apple-touch-icon.png') ?>?v=1">
    <link rel="preload" href="<?= assetUrl('fonts/bricolage-grotesque-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= assetUrl('fonts/instrument-sans-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>?v=5">
    <link rel="stylesheet" href="<?= assetUrl('css/admin.css') ?>?v=5">
    <script src="<?= assetUrl('js/app.js') ?>?v=4" defer></script>
</head>
<body class="admin-body">
<a href="#main-content" class="skip-link">Lewati ke konten utama</a>

<div class="admin-shell">
    <aside class="admin-sidebar" id="admin-sidebar" aria-label="Navigasi admin">
        <div>
            <?= brandLogo('../index.php', true) ?>
            <p class="admin-side-label" style="margin-top:16px;">Konsol Pengelola</p>
            <nav class="admin-nav">
                <?php foreach ($adminMenu as $key => $item): ?>
                    <a href="<?= $item[0] ?>" class="<?= $adminActive === $key ? 'active' : '' ?>">
                        <?= svgIcon($item[2], 'icon') ?>
                        <span><?= $item[1] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="admin-side-foot">
            <div class="admin-side-user">
                <span class="avatar"><?= clean(getInitials($_SESSION['name'] ?? 'Admin')) ?></span>
                <div>
                    <strong><?= clean($_SESSION['name'] ?? 'Admin') ?></strong>
                    <small>Administrator</small>
                </div>
            </div>
            <div class="admin-side-links">
                <a href="../index.php"><?= svgIcon('external', 'icon') ?> Lihat Website</a>
                <a href="../logout.php" class="is-danger"><?= svgIcon('logout', 'icon') ?> Keluar</a>
            </div>
        </div>
    </aside>

    <div class="admin-main">
        <header class="admin-topbar">
            <div class="admin-topbar-left">
                <button type="button" class="admin-burger" aria-expanded="false" aria-controls="admin-sidebar" aria-label="Buka menu admin">
                    <?= svgIcon('menu', 'icon') ?>
                </button>
                <div>
                    <span class="admin-topbar-crumb">Admin /</span>
                    <h2 class="admin-topbar-title"><?= clean($adminTitle) ?></h2>
                </div>
            </div>
            <div class="admin-topbar-actions">
                <a href="../index.php" class="btn btn-ghost"><?= svgIcon('external', 'icon') ?> Website</a>
                <a href="../logout.php" class="btn btn-outline"><?= svgIcon('logout', 'icon') ?> Keluar</a>
            </div>
        </header>

        <main class="admin-main-inner" id="main-content">
            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="alert alert-success admin-flash">
                    <?= svgIcon('check-circle', 'icon') ?>
                    <div><?= clean($_SESSION['flash_success']) ?></div>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['flash_error'])): ?>
                <div class="alert alert-danger admin-flash">
                    <?= svgIcon('alert', 'icon') ?>
                    <div><?= clean($_SESSION['flash_error']) ?></div>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>
