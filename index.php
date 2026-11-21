<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';

$pageTitle = 'Beranda';
$pageDescription = 'Booking lapangan futsal, badminton, dan basket di Bekasi. Cek jadwal real-time, pilih slot, dan bayar aman lewat ArenaKu.';

// ===== KATEGORI OLAHRAGA + RINGKASAN LAPANGAN =====
$sportsList = $koneksi->query(
    "SELECT sports.id, sports.name, sports.description,
            COUNT(courts.id) AS court_count,
            MIN(courts.price_per_hour) AS min_price,
            (SELECT cimg.image FROM courts cimg
             WHERE cimg.sport_id = sports.id AND cimg.image IS NOT NULL AND cimg.image <> ''
             ORDER BY cimg.id ASC LIMIT 1) AS sport_image
     FROM sports
     LEFT JOIN courts ON courts.sport_id = sports.id AND courts.status = 'active'
     GROUP BY sports.id, sports.name, sports.description
     ORDER BY sports.id ASC"
)->fetchAll();

// ===== LAPANGAN POPULER =====
$popularCourts = $koneksi->query(
    "SELECT courts.*, sports.name AS sport_name
     FROM courts
     JOIN sports ON courts.sport_id = sports.id
     WHERE courts.status = 'active'
     ORDER BY courts.id ASC
     LIMIT 3"
)->fetchAll();

// ===== STATISTIK NYATA =====
$totalLapangan = (int) $koneksi->query("SELECT COUNT(*) FROM courts WHERE status = 'active'")->fetchColumn();
$totalBooking  = (int) $koneksi->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$totalCabang   = count($sportsList);

$ratingRow = $koneksi->query("SELECT AVG(stars) AS avg_stars, COUNT(*) AS total FROM ratings")->fetch();
$totalUlasan = (int) ($ratingRow['total'] ?? 0);
$rataRating = ($totalUlasan > 0 && $ratingRow['avg_stars'] !== null) ? round((float) $ratingRow['avg_stars'], 1) : null;

// ===== PROMO AKTIF PERTAMA (jika ada) =====
$promoAktif = $koneksi->query(
    "SELECT * FROM promos
     WHERE status = 'aktif'
       AND (tanggal_mulai IS NULL OR tanggal_mulai <= CURDATE())
       AND (tanggal_akhir IS NULL OR tanggal_akhir >= CURDATE())
       AND (kuota IS NULL OR terpakai < kuota)
     ORDER BY id ASC
     LIMIT 1"
)->fetch();

$promoNilai = '';
if ($promoAktif) {
    if ($promoAktif['tipe'] === 'persen') {
        $promoNilai = rtrim(rtrim(number_format((float) $promoAktif['nilai'], 1, ',', '.'), '0'), ',') . '%';
    } else {
        $promoNilai = formatRupiah($promoAktif['nilai']);
    }
}

$today = date('Y-m-d');
require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<main id="main-content">
    <!-- ===================== HERO ===================== -->
    <section class="hero-home">
        <div class="container">
            <div class="hero-grid">
                <div class="hero-copy">
                    <span class="hero-kicker">Booking lapangan · Bekasi</span>
                    <h1 class="hero-title">Main bareng,<br>tanpa rebutan <em>jadwal.</em></h1>
                    <p class="hero-lede">
                        Futsal, badminton, atau basket — cek slot kosong secara real-time,
                        kunci jadwalmu, lalu bayar dengan aman. Semuanya dalam satu tempat.
                    </p>

                    <form action="/arenaku/lapangan.php" method="GET" class="hero-search" role="search">
                        <label for="hero-cari" class="hero-search-label">Cari lapangan</label>
                        <div class="hero-search-row">
                            <span class="hero-search-icon"><?= svgIcon('search', 'icon') ?></span>
                            <input id="hero-cari" type="text" name="cari" placeholder="Nama lapangan atau lokasi...">
                            <button type="submit" class="btn btn-primary">Cari</button>
                        </div>
                    </form>

                    <div class="hero-quick">
                        <span class="hero-quick-label">Populer</span>
                        <?php foreach ($sportsList as $sport): ?>
                            <a href="/arenaku/lapangan.php?jenis=<?= (int) $sport['id'] ?>" class="chip">
                                <?= sportIcon($sport['name'], 'icon') ?>
                                <?= clean($sport['name']) ?>
                                <span class="chip-count"><?= (int) $sport['court_count'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <aside class="hero-board" aria-label="Ringkasan jadwal dan lapangan">
                    <?php $heroFoto = !empty($popularCourts) ? courtImageUrl($popularCourts[0]) : ''; ?>
                    <?php if ($heroFoto !== ''): ?>
                        <img class="hero-board-photo" src="<?= clean($heroFoto) ?>" alt="" aria-hidden="true" loading="eager" decoding="async">
                        <span class="hero-board-veil" aria-hidden="true"></span>
                    <?php endif; ?>
                    <?= courtDiagram() ?>
                    <div class="board-top">
                        <span class="live-dot">Jadwal real-time</span>
                        <span>ArenaKu · <?= date('Y') ?></span>
                    </div>
                    <div class="board-date">
                        <strong><?= date('d') ?></strong>
                        <span>
                            <?= formatHariId($today) ?>
                            <strong><?= formatTanggalId($today, 'panjang') ?></strong>
                        </span>
                    </div>
                    <div class="board-rows">
                        <?php foreach ($sportsList as $sport): ?>
                            <a href="/arenaku/lapangan.php?jenis=<?= (int) $sport['id'] ?>" class="board-row">
                                <?= sportIcon($sport['name'], 'icon') ?>
                                <span class="board-row-name"><?= clean($sport['name']) ?></span>
                                <span class="board-row-count"><?= (int) $sport['court_count'] ?> lapangan</span>
                                <span class="board-row-price">
                                    <?= $sport['min_price'] !== null ? 'dari ' . formatRupiah($sport['min_price']) : '—' ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="board-foot">
                        <span><?= $totalLapangan ?> lapangan aktif siap dipesan</span>
                        <a href="/arenaku/lapangan.php">Lihat semua <?= svgIcon('arrow-right', 'icon') ?></a>
                    </div>
                </aside>
            </div>

            <div class="hero-foot">
                <div class="hero-stat">
                    <strong><?= $totalLapangan ?></strong>
                    <span>lapangan aktif</span>
                </div>
                <div class="hero-stat">
                    <strong><?= number_format($totalBooking, 0, ',', '.') ?></strong>
                    <span>booking tercatat</span>
                </div>
                <div class="hero-stat">
                    <?php if ($rataRating !== null): ?>
                        <strong><?= number_format($rataRating, 1, ',', '.') ?>/5</strong>
                        <span>dari <?= $totalUlasan ?> ulasan pemain</span>
                    <?php else: ?>
                        <strong><?= $totalCabang ?></strong>
                        <span>cabang olahraga tersedia</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- ===================== KATEGORI ===================== -->
    <section class="section">
        <div class="container">
            <div class="section-head">
                <div>
                    <span class="section-index">01 — Pilih permainan</span>
                    <h2>Satu tempat, tiga arena.</h2>
                    <p>Mulai dari lapangan futsal berumput sintetis sampai lapangan basket indoor. Pilih cabangnya, sisanya tinggal atur jadwal.</p>
                </div>
                <a href="/arenaku/lapangan.php" class="text-link">Semua lapangan <?= svgIcon('arrow-right', 'icon') ?></a>
            </div>

            <?php if (!empty($sportsList)): ?>
                <div class="sport-list">
                    <?php foreach ($sportsList as $sport): ?>
                        <?php $sportFoto = sportImageUrl($sport); ?>
                        <a href="/arenaku/lapangan.php?jenis=<?= (int) $sport['id'] ?>" class="sport-row">
                            <span class="sport-row-icon">
                                <?php if ($sportFoto !== ''): ?>
                                    <img src="<?= clean($sportFoto) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <?= sportIcon($sport['name'], 'icon') ?>
                                <?php endif; ?>
                            </span>
                            <span class="sport-row-name"><?= clean($sport['name']) ?></span>
                            <span class="sport-row-desc"><?= clean($sport['description']) ?></span>
                            <span class="sport-row-meta"><strong><?= (int) $sport['court_count'] ?></strong> lapangan</span>
                            <span class="sport-row-price">
                                Mulai dari
                                <strong><?= $sport['min_price'] !== null ? formatRupiah($sport['min_price']) : '—' ?></strong>
                            </span>
                            <span class="sport-row-arrow"><?= svgIcon('arrow-right', 'icon') ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="empty-state-icon"><?= svgIcon('field', 'icon') ?></span>
                    <h3>Belum ada cabang olahraga</h3>
                    <p>Data cabang olahraga akan muncul di sini setelah ditambahkan oleh pengelola.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ===================== LAPANGAN PILIHAN ===================== -->
    <section class="section section-alt">
        <div class="container">
            <div class="section-head">
                <div>
                    <span class="section-index">02 — Lapangan pilihan</span>
                    <h2>Arena yang paling sering dipesan.</h2>
                    <p>Jadwal, harga, dan fasilitas ditampilkan apa adanya supaya kamu bisa memilih tanpa ragu.</p>
                </div>
                <a href="/arenaku/lapangan.php" class="text-link">Jelajahi katalog <?= svgIcon('arrow-right', 'icon') ?></a>
            </div>

            <?php if (!empty($popularCourts)): ?>
                <div class="court-grid">
                    <?php foreach ($popularCourts as $court): ?>
                        <?php $rating = getRatingLapangan($koneksi, (int) $court['id']); ?>
                        <article class="court-card">
                            <a href="/arenaku/detail_lapangan.php?id=<?= (int) $court['id'] ?>" aria-label="Lihat <?= clean($court['name']) ?>">
                                <?= courtCover($court) ?>
                            </a>
                            <div class="court-card-body">
                                <div class="court-card-meta">
                                    <span class="badge badge-neutral"><?= clean($court['sport_name']) ?></span>
                                    <?php if ($rating['count'] > 0): ?>
                                        <span class="rating-inline">
                                            <?= svgIcon('star-filled', 'icon') ?>
                                            <?= number_format($rating['avg'], 1, ',', '.') ?>
                                            <small>(<?= $rating['count'] ?>)</small>
                                        </span>
                                    <?php else: ?>
                                        <span class="rating-empty">Belum ada ulasan</span>
                                    <?php endif; ?>
                                </div>
                                <h3><a href="/arenaku/detail_lapangan.php?id=<?= (int) $court['id'] ?>"><?= clean($court['name']) ?></a></h3>
                                <p class="court-card-location"><?= svgIcon('pin', 'icon') ?> <?= clean($court['location']) ?></p>
                                <div class="court-card-foot">
                                    <p class="court-card-price">
                                        Mulai dari
                                        <strong><?= formatRupiah($court['price_per_hour']) ?> <small>/ jam</small></strong>
                                    </p>
                                    <a href="/arenaku/detail_lapangan.php?id=<?= (int) $court['id'] ?>" class="btn btn-outline btn-sm">
                                        Pilih jadwal <?= svgIcon('arrow-right', 'icon') ?>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="empty-state-icon"><?= svgIcon('field', 'icon') ?></span>
                    <h3>Belum ada lapangan tersedia</h3>
                    <p>Lapangan baru akan tampil di sini begitu pengelola menambahkannya.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ===================== KEUNGGULAN ===================== -->
    <section class="section">
        <div class="container">
            <div class="feature-grid">
                <div class="feature-copy">
                    <span class="section-index">03 — Kenapa ArenaKu</span>
                    <h2>Dari cek jadwal sampai bayar, satu alur.</h2>
                    <p class="lede">Tidak perlu chat sana-sini atau datang untuk menanyakan slot kosong. Semua informasi lapangan sudah tersedia sebelum kamu memutuskan.</p>

                    <div class="feature-list">
                        <div class="feature-item">
                            <span class="feature-num">01</span>
                            <div>
                                <h3>Jadwal yang benar-benar terpakai</h3>
                                <p>Slot yang sudah dipesan langsung tertutup, jadi tidak ada jadwal bentrok saat kamu datang.</p>
                            </div>
                        </div>
                        <div class="feature-item">
                            <span class="feature-num">02</span>
                            <div>
                                <h3>Pembayaran terhubung Midtrans</h3>
                                <p>Bayar lewat QRIS, e-wallet, virtual account, atau kartu. Status pembayaran diperbarui otomatis.</p>
                            </div>
                        </div>
                        <div class="feature-item">
                            <span class="feature-num">03</span>
                            <div>
                                <h3>Riwayat booking yang rapi</h3>
                                <p>Semua pesanan, status, dan detail lapangan tersimpan di akunmu — siap dicek kapan saja.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($popularCourts)): ?>
                    <?php $passCourt = $popularCourts[0]; ?>
                    <div class="pass-card">
                        <div class="pass-top">
                            <span>Match pass</span>
                            <strong>ArenaKu</strong>
                        </div>
                        <div class="pass-cover">
                            <?= courtCover($passCourt) ?>
                        </div>
                        <div class="pass-body">
                            <span class="pass-sport"><?= clean($passCourt['sport_name']) ?></span>
                            <h3><?= clean($passCourt['name']) ?></h3>
                            <p class="pass-location"><?= svgIcon('pin', 'icon') ?> <?= clean($passCourt['location']) ?></p>
                            <div class="pass-meta">
                                <div>
                                    <span>Harga per jam</span>
                                    <strong><?= formatRupiah($passCourt['price_per_hour']) ?></strong>
                                </div>
                                <div>
                                    <span>Jam operasional</span>
                                    <strong><?= substr($passCourt['opening_time'], 0, 5) ?>–<?= substr($passCourt['closing_time'], 0, 5) ?></strong>
                                </div>
                            </div>
                            <div class="pass-stub">
                                <div>
                                    <small>Pilih tanggal &amp; slot</small>
                                    <a href="/arenaku/detail_lapangan.php?id=<?= (int) $passCourt['id'] ?>" class="text-link" style="margin-top:6px;">Buka jadwal <?= svgIcon('arrow-right', 'icon') ?></a>
                                </div>
                                <div class="barcode" aria-hidden="true">
                                    <?php for ($i = 0; $i < 22; $i++): ?><span></span><?php endfor; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ===================== CARA KERJA ===================== -->
    <section class="section-sm">
        <div class="container">
            <div class="section-head">
                <div>
                    <span class="section-index">04 — Alur booking</span>
                    <h2>Empat langkah, langsung main.</h2>
                </div>
            </div>
            <div class="route-grid">
                <div class="route-step">
                    <span class="route-num">Langkah 01</span>
                    <h3>Pilih lapangan</h3>
                    <p>Filter berdasarkan cabang olahraga, lokasi, atau kata kunci.</p>
                </div>
                <div class="route-step">
                    <span class="route-num">Langkah 02</span>
                    <h3>Tentukan slot</h3>
                    <p>Pilih tanggal, lalu centang jam yang masih kosong.</p>
                </div>
                <div class="route-step">
                    <span class="route-num">Langkah 03</span>
                    <h3>Konfirmasi &amp; bayar</h3>
                    <p>Isi data pemesan, gunakan promo bila ada, lalu bayar.</p>
                </div>
                <div class="route-step">
                    <span class="route-num">Langkah 04</span>
                    <h3>Datang &amp; main</h3>
                    <p>Tunjukkan detail booking di lokasi sesuai jadwalmu.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ===================== PROMO ===================== -->
    <?php if ($promoAktif): ?>
        <section class="section">
            <div class="container">
                <div class="promo-band">
                    <div>
                        <span class="eyebrow">Promo berjalan</span>
                        <h2><?= clean($promoAktif['nama']) ?></h2>
                        <p><?= clean($promoAktif['deskripsi']) ?></p>
                    </div>
                    <div class="promo-sticker">
                        <small>Potongan</small>
                        <strong><?= clean($promoNilai) ?></strong>
                        <span>Kode: <?= clean($promoAktif['kode']) ?></span>
                        <a href="/arenaku/promo.php" class="btn btn-sm btn-block">Lihat detail promo</a>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- ===================== CTA ===================== -->
    <section class="section-sm">
        <div class="container">
            <div class="cta-band">
                <?= courtDiagram() ?>
                <div class="cta-band-inner">
                    <h2>Lapangan sudah menunggu.</h2>
                    <p>Pilih jadwalmu sekarang, atau daftar dulu supaya riwayat booking tersimpan rapi di akun.</p>
                    <div class="cta-actions">
                        <a href="/arenaku/lapangan.php" class="btn btn-primary btn-lg">Cari lapangan</a>
                        <?php if (!isLoggedIn()): ?>
                            <a href="/arenaku/register.php" class="btn btn-outline btn-lg">Buat akun</a>
                        <?php else: ?>
                            <a href="/arenaku/booking_saya.php" class="btn btn-outline btn-lg">Booking saya</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require_once 'includes/footer.php'; ?>
