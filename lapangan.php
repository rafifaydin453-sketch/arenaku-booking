<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';

$pageTitle = 'Daftar Lapangan';
$pageDescription = 'Jelajahi lapangan futsal, badminton, dan basket di Bekasi. Filter berdasarkan jenis olahraga, harga, dan lokasi.';

// ===== PARAMETER FILTER (GET) =====
$cari        = isset($_GET['cari']) ? trim($_GET['cari']) : '';
$jenisId     = isset($_GET['jenis']) ? (int) $_GET['jenis'] : 0;
$hargaFilter = isset($_GET['harga']) ? $_GET['harga'] : 'semua';
$page        = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

// ===== PAGINATION =====
$perPage = 6;
$offset  = ($page - 1) * $perPage;

// ===== WHERE DINAMIS (prepared statement) =====
$where  = ["courts.status = 'active'"];
$params = [];

if ($cari !== '') {
    $where[] = "(courts.name LIKE ? OR courts.location LIKE ?)";
    $params[] = "%$cari%";
    $params[] = "%$cari%";
}

if ($jenisId > 0) {
    $where[] = "courts.sport_id = ?";
    $params[] = $jenisId;
}

if ($hargaFilter === 'murah') {
    $where[] = "courts.price_per_hour < 75000";
} elseif ($hargaFilter === 'sedang') {
    $where[] = "courts.price_per_hour BETWEEN 75000 AND 100000";
} elseif ($hargaFilter === 'mahal') {
    $where[] = "courts.price_per_hour > 100000";
}

$whereSql = implode(' AND ', $where);

// ===== TOTAL DATA =====
$stmtCount = $koneksi->prepare("SELECT COUNT(*) AS total FROM courts WHERE $whereSql");
$stmtCount->execute($params);
$totalData = (int) $stmtCount->fetch()['total'];
$totalPage = (int) ceil($totalData / $perPage);

// ===== DATA LAPANGAN =====
$sql = "SELECT courts.*, sports.name AS sport_name,
               (SELECT AVG(stars) FROM ratings WHERE ratings.court_id = courts.id) AS avg_stars,
               (SELECT COUNT(*) FROM ratings WHERE ratings.court_id = courts.id) AS total_ratings
        FROM courts
        JOIN sports ON courts.sport_id = sports.id
        WHERE $whereSql
        ORDER BY courts.id ASC
        LIMIT $perPage OFFSET $offset";

$stmtCourts = $koneksi->prepare($sql);
$stmtCourts->execute($params);
$courts = $stmtCourts->fetchAll();

// ===== SPORTS + JUMLAH LAPANGAN =====
$sportsList = $koneksi->query(
    "SELECT sports.id, sports.name, sports.description,
            COUNT(courts.id) AS court_count,
            (SELECT cimg.image FROM courts cimg
             WHERE cimg.sport_id = sports.id AND cimg.image IS NOT NULL AND cimg.image <> ''
             ORDER BY cimg.id ASC LIMIT 1) AS sport_image
     FROM sports
     LEFT JOIN courts ON courts.sport_id = sports.id AND courts.status = 'active'
     GROUP BY sports.id, sports.name, sports.description
     ORDER BY sports.id ASC"
)->fetchAll();

$filterAktif = $cari !== '' || $jenisId > 0 || $hargaFilter !== 'semua';

$sportTerpilih = null;
foreach ($sportsList as $sport) {
    if ((int) $sport['id'] === $jenisId) {
        $sportTerpilih = $sport;
        break;
    }
}
$fotoKategori = $sportTerpilih !== null ? sportImageUrl($sportTerpilih) : '';

// Bangun URL filter tanpa halaman
function urlKatalog($overrides = [])
{
    $params = $_GET;
    unset($params['page']);
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '' || $value === 0 || $value === 'semua') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    $query = http_build_query($params);
    return 'lapangan.php' . ($query !== '' ? '?' . $query : '');
}

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<main id="main-content">
    <section class="catalog-hero">
        <div class="container">
            <div class="catalog-hero-inner">
                <div>
                    <span class="eyebrow">Katalog lapangan</span>
                    <h1>Cari arena,<br>kunci jadwalmu.</h1>
                    <p>Enam lapangan aktif dengan jam operasional, harga, dan fasilitas yang bisa kamu bandingkan sebelum memesan.</p>
                </div>
                <?php if ($fotoKategori !== ''): ?>
                    <span class="catalog-index has-photo" title="<?= clean($sportTerpilih['name']) ?>">
                        <img src="<?= clean($fotoKategori) ?>" alt="<?= clean($sportTerpilih['name']) ?>" loading="lazy">
                    </span>
                <?php else: ?>
                    <span class="catalog-index"><?= str_pad((string) $totalData, 2, '0', STR_PAD_LEFT) ?> arena</span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="section" style="padding-top:0;">
        <div class="container">
            <form method="GET" action="lapangan.php" class="filter-deck" role="search">
                <div class="filter-row">
                    <div class="filter-field">
                        <label for="filter-cari">Kata kunci</label>
                        <input id="filter-cari" type="text" name="cari" class="form-input" placeholder="Nama lapangan atau lokasi..." value="<?= clean($cari) ?>">
                    </div>
                    <div class="filter-field">
                        <label for="filter-jenis">Cabang olahraga</label>
                        <select id="filter-jenis" name="jenis" class="form-select">
                            <option value="0">Semua cabang</option>
                            <?php foreach ($sportsList as $sport): ?>
                                <option value="<?= (int) $sport['id'] ?>" <?= $jenisId === (int) $sport['id'] ? 'selected' : '' ?>>
                                    <?= clean($sport['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-field">
                        <label for="filter-harga">Rentang harga</label>
                        <select id="filter-harga" name="harga" class="form-select">
                            <option value="semua" <?= $hargaFilter === 'semua' ? 'selected' : '' ?>>Semua harga</option>
                            <option value="murah" <?= $hargaFilter === 'murah' ? 'selected' : '' ?>>Di bawah Rp 75.000</option>
                            <option value="sedang" <?= $hargaFilter === 'sedang' ? 'selected' : '' ?>>Rp 75.000 – Rp 100.000</option>
                            <option value="mahal" <?= $hargaFilter === 'mahal' ? 'selected' : '' ?>>Di atas Rp 100.000</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= svgIcon('search', 'icon') ?> Terapkan</button>
                </div>

                <div class="filter-bottom">
                    <div class="sport-chips">
                        <a href="<?= urlKatalog(['jenis' => null]) ?>" class="chip <?= $jenisId === 0 ? 'is-active' : '' ?>">
                            <?= svgIcon('field', 'icon') ?> Semua
                            <span class="chip-count"><?= array_sum(array_column($sportsList, 'court_count')) ?></span>
                        </a>
                        <?php foreach ($sportsList as $sport): ?>
                            <a href="<?= urlKatalog(['jenis' => (int) $sport['id']]) ?>" class="chip <?= $jenisId === (int) $sport['id'] ? 'is-active' : '' ?>">
                                <?= sportIcon($sport['name'], 'icon') ?> <?= clean($sport['name']) ?>
                                <span class="chip-count"><?= (int) $sport['court_count'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($filterAktif): ?>
                        <a href="lapangan.php" class="filter-reset"><?= svgIcon('x', 'icon') ?> Reset filter</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="results-head">
                <p class="results-count">
                    <strong><?= $totalData ?></strong> lapangan ditemukan
                    <?php if ($cari !== ''): ?> untuk “<?= clean($cari) ?>”<?php endif; ?>
                </p>
                <?php if ($totalPage > 1): ?>
                    <span class="text-muted" style="font-size:0.78rem;">Halaman <?= $page ?> dari <?= $totalPage ?></span>
                <?php endif; ?>
            </div>

            <?php if (empty($courts)): ?>
                <div class="empty-state">
                    <span class="empty-state-icon"><?= svgIcon('search', 'icon') ?></span>
                    <h3>Tidak ada yang cocok</h3>
                    <p>Coba kata kunci lain atau longgarkan filter harga dan cabang olahraga.</p>
                    <a href="lapangan.php" class="btn btn-outline">Reset semua filter</a>
                </div>
            <?php else: ?>
                <div class="catalog-grid">
                    <?php foreach ($courts as $court): ?>
                        <article class="court-card">
                            <a href="detail_lapangan.php?id=<?= (int) $court['id'] ?>" aria-label="Lihat <?= clean($court['name']) ?>">
                                <?= courtCover($court) ?>
                            </a>
                            <div class="court-card-body">
                                <div class="court-card-meta">
                                    <span class="badge badge-neutral"><?= clean($court['sport_name']) ?></span>
                                    <?php if ((int) $court['total_ratings'] > 0): ?>
                                        <span class="rating-inline">
                                            <?= svgIcon('star-filled', 'icon') ?>
                                            <?= number_format((float) $court['avg_stars'], 1, ',', '.') ?>
                                            <small>(<?= (int) $court['total_ratings'] ?>)</small>
                                        </span>
                                    <?php else: ?>
                                        <span class="rating-empty">Belum ada ulasan</span>
                                    <?php endif; ?>
                                </div>
                                <h3><a href="detail_lapangan.php?id=<?= (int) $court['id'] ?>"><?= clean($court['name']) ?></a></h3>
                                <p class="court-card-location"><?= svgIcon('pin', 'icon') ?> <?= clean($court['location']) ?></p>
                                <div class="court-card-foot">
                                    <p class="court-card-price">
                                        Mulai dari
                                        <strong><?= formatRupiah($court['price_per_hour']) ?> <small>/ jam</small></strong>
                                    </p>
                                    <a href="detail_lapangan.php?id=<?= (int) $court['id'] ?>" class="btn btn-outline btn-sm">
                                        Pilih jadwal <?= svgIcon('arrow-right', 'icon') ?>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPage > 1): ?>
                    <nav class="pagination" aria-label="Navigasi halaman">
                        <?php for ($i = 1; $i <= $totalPage; $i++): ?>
                            <?php
                                $queryParams = $_GET;
                                $queryParams['page'] = $i;
                                $link = '?' . http_build_query($queryParams);
                            ?>
                            <?php if ($i === $page): ?>
                                <span class="current" aria-current="page"><?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= clean($link) ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php require_once 'includes/footer.php'; ?>
