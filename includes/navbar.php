<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$navItems = [
    'index.php'       => ['Beranda', '/arenaku/index.php'],
    'lapangan.php'    => ['Lapangan', '/arenaku/lapangan.php'],
    'detail_lapangan.php' => ['Lapangan', '/arenaku/lapangan.php'],
    'promo.php'       => ['Promo', '/arenaku/promo.php'],
    'tentang_kami.php'=> ['Tentang Kami', '/arenaku/tentang_kami.php'],
];
$activeNav = isset($navItems[$currentPage]) ? $navItems[$currentPage][1] : '';
?>
<div class="topbar">
    <div class="container topbar-inner">
        <div class="topbar-group">
            <span class="topbar-item"><?= svgIcon('pin', 'icon') ?> Bekasi, Jawa Barat</span>
            <span class="topbar-item topbar-hide-sm"><?= svgIcon('clock', 'icon') ?> Jadwal bervariasi per lapangan</span>
        </div>
        <div class="topbar-group">
            <span class="topbar-item topbar-hide-sm topbar-dot" aria-hidden="true"></span>
            <span class="topbar-item topbar-hide-sm">Butuh bantuan? <a href="https://wa.me/6285283019449" target="_blank" rel="noopener">085283019449</a></span>
        </div>
    </div>
</div>

<header class="site-header">
    <div class="container navbar-inner">
        <?= brandLogo('/arenaku/index.php') ?>

        <button type="button" class="nav-toggle" aria-expanded="false" aria-controls="nav-collapse" aria-label="Buka menu navigasi">
            <?= svgIcon('menu', 'icon') ?>
        </button>

        <div class="nav-collapse" id="nav-collapse">
            <nav class="nav-links" aria-label="Navigasi utama">
                <?php foreach ($navItems as $file => $item): ?>
                    <?php if ($file === 'detail_lapangan.php') { continue; } ?>
                    <a href="<?= $item[1] ?>" class="<?= $activeNav === $item[1] ? 'active' : '' ?>"><?= $item[0] ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="nav-actions">
                <?php if (isLoggedIn()): ?>
                    <details class="account-menu">
                        <summary>
                            <span class="avatar"><?= clean(getInitials($_SESSION['name'] ?? 'ArenaKu')) ?></span>
                            <span class="account-name"><?= clean($_SESSION['name'] ?? 'Pengguna') ?></span>
                            <?= svgIcon('chevron-down', 'icon') ?>
                        </summary>
                        <div class="account-dropdown">
                            <a href="/arenaku/booking_saya.php"><?= svgIcon('calendar', 'icon') ?> Booking Saya</a>
                            <a href="/arenaku/profil.php"><?= svgIcon('user', 'icon') ?> Profil Saya</a>
                            <?php if (isAdmin()): ?>
                                <a href="/arenaku/admin/index.php"><?= svgIcon('grid', 'icon') ?> Panel Admin</a>
                            <?php endif; ?>
                            <div class="account-dropdown-divider"></div>
                            <a href="/arenaku/logout.php" class="is-danger"><?= svgIcon('logout', 'icon') ?> Keluar</a>
                        </div>
                    </details>
                <?php else: ?>
                    <a href="/arenaku/login.php" class="nav-text-link">Masuk</a>
                    <a href="/arenaku/register.php" class="btn btn-primary">Daftar</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>
