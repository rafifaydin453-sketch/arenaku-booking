<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';

$courtId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $koneksi->prepare(
    "SELECT courts.*, sports.name AS sport_name,
            (SELECT AVG(stars) FROM ratings WHERE ratings.court_id = courts.id) AS avg_stars,
            (SELECT COUNT(*) FROM ratings WHERE ratings.court_id = courts.id) AS total_ratings
     FROM courts
     JOIN sports ON courts.sport_id = sports.id
     WHERE courts.id = ? AND courts.status = 'active'"
);
$stmt->execute([$courtId]);
$court = $stmt->fetch();

if (!$court) {
    $pageTitle = 'Lapangan tidak ditemukan';
    require_once 'includes/header.php';
    require_once 'includes/navbar.php';
    ?>
    <main id="main-content" class="section">
        <div class="container-narrow">
            <div class="empty-state">
                <span class="empty-state-icon"><?= svgIcon('field', 'icon') ?></span>
                <h3>Lapangan tidak ditemukan</h3>
                <p>Lapangan yang kamu cari sudah tidak tersedia atau sedang tidak aktif.</p>
                <a href="/arenaku/lapangan.php" class="btn btn-primary">Kembali ke katalog</a>
            </div>
        </div>
    </main>
    <?php
    require_once 'includes/footer.php';
    exit;
}

$pageTitle = $court['name'];
$pageDescription = mb_substr(strip_tags((string) $court['description']), 0, 150);

// ===== TANGGAL =====
$tanggalDipilih = isset($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalDipilih)) {
    $tanggalDipilih = date('Y-m-d');
}
if ($tanggalDipilih < date('Y-m-d')) {
    $tanggalDipilih = date('Y-m-d');
}

// ===== BOOKING AKTIF =====
$stmtBooked = $koneksi->prepare(
    "SELECT start_time, end_time
     FROM bookings
     WHERE court_id = ?
       AND booking_date = ?
       AND booking_status IN ('pending_payment', 'confirmed')"
);
$stmtBooked->execute([$courtId, $tanggalDipilih]);
$bookedRanges = $stmtBooked->fetchAll();

$slots = generateTimeSlots($court['opening_time'], $court['closing_time'], $bookedRanges, $tanggalDipilih);
$slotTersedia = 0;
foreach ($slots as $slot) {
    if ($slot['available']) {
        $slotTersedia++;
    }
}

// ===== 7 TANGGAL KE DEPAN =====
$pilihanTanggal = [];
for ($i = 0; $i < 7; $i++) {
    $pilihanTanggal[] = date('Y-m-d', strtotime("+$i day"));
}

// ===== ULASAN =====
$stmtReviews = $koneksi->prepare(
    "SELECT ratings.*, users.name AS user_name
     FROM ratings
     JOIN users ON ratings.user_id = users.id
     WHERE ratings.court_id = ?
     ORDER BY ratings.created_at DESC
     LIMIT 6"
);
$stmtReviews->execute([$courtId]);
$reviews = $stmtReviews->fetchAll();

$facilities = array_filter(array_map('trim', explode(',', (string) $court['facilities'])), function ($item) {
    return $item !== '';
});

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<main id="main-content">
    <section class="section">
        <div class="container">
            <a href="/arenaku/lapangan.php" class="back-link"><?= svgIcon('arrow-left', 'icon') ?> Kembali ke katalog</a>

            <div class="detail-top">
                <div class="detail-visual">
                    <?= courtCover($court) ?>
                </div>

                <div class="detail-intro">
                    <div class="detail-tags">
                        <span class="badge badge-lime"><?= clean($court['sport_name']) ?></span>
                        <span class="badge badge-success">Tersedia</span>
                    </div>
                    <h1><?= clean($court['name']) ?></h1>

                    <div class="detail-rating">
                        <?php if ((int) $court['total_ratings'] > 0): ?>
                            <span class="rating-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <?= svgIcon($i <= round((float) $court['avg_stars']) ? 'star-filled' : 'star', 'icon') ?>
                                <?php endfor; ?>
                            </span>
                            <span><?= number_format((float) $court['avg_stars'], 1, ',', '.') ?>/5 · <?= (int) $court['total_ratings'] ?> ulasan</span>
                        <?php else: ?>
                            <span class="rating-empty">Belum ada ulasan dari pemain</span>
                        <?php endif; ?>
                    </div>

                    <p class="detail-location"><?= svgIcon('pin', 'icon') ?> <?= clean($court['location']) ?></p>

                    <div class="detail-price">
                        <strong><?= formatRupiah($court['price_per_hour']) ?></strong>
                        <span>/ jam · sewa per slot 1 jam</span>
                    </div>

                    <div class="detail-quickfacts">
                        <span class="quickfact"><?= svgIcon('clock', 'icon') ?> <?= substr($court['opening_time'], 0, 5) ?>–<?= substr($court['closing_time'], 0, 5) ?></span>
                        <span class="quickfact"><?= svgIcon('calendar', 'icon') ?> <?= $slotTersedia ?> slot kosong</span>
                        <span class="quickfact"><?= svgIcon('shield', 'icon') ?> Pembayaran online</span>
                    </div>
                </div>
            </div>

            <div class="detail-grid">
                <div>
                    <div class="detail-block">
                        <h2>Tentang lapangan</h2>
                        <?php if (trim((string) $court['description']) !== ''): ?>
                            <p><?= nl2br(clean($court['description'])) ?></p>
                        <?php else: ?>
                            <p>Pengelola belum menambahkan deskripsi untuk lapangan ini.</p>
                        <?php endif; ?>
                    </div>

                    <div class="detail-block">
                        <h2>Fasilitas</h2>
                        <?php if (!empty($facilities)): ?>
                            <div class="facility-list">
                                <?php foreach ($facilities as $facility): ?>
                                    <span class="facility-chip"><?= svgIcon('check', 'icon') ?> <?= clean($facility) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted" style="margin-top:10px;">Belum ada data fasilitas.</p>
                        <?php endif; ?>

                        <div class="hours-table" style="margin-top:20px;">
                            <span>Jam operasional</span>
                            <span><strong><?= substr($court['opening_time'], 0, 5) ?>–<?= substr($court['closing_time'], 0, 5) ?></strong></span>
                            <span>Durasi per slot</span>
                            <span><strong>1 jam</strong></span>
                        </div>
                    </div>

                    <div class="detail-block">
                        <h2>Ulasan pemain</h2>
                        <?php if (!empty($reviews)): ?>
                            <div class="review-list">
                                <?php foreach ($reviews as $review): ?>
                                    <article class="review-card">
                                        <div class="review-head">
                                            <div class="review-author">
                                                <span class="avatar"><?= clean(getInitials($review['user_name'])) ?></span>
                                                <div>
                                                    <strong><?= clean($review['user_name']) ?></strong>
                                                    <small><?= formatTanggalId($review['created_at']) ?></small>
                                                </div>
                                            </div>
                                            <span class="rating-stars">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <?= svgIcon($i <= (int) $review['stars'] ? 'star-filled' : 'star', 'icon') ?>
                                                <?php endfor; ?>
                                            </span>
                                        </div>
                                        <?php if (trim((string) $review['review']) !== ''): ?>
                                            <p><?= nl2br(clean($review['review'])) ?></p>
                                        <?php else: ?>
                                            <p class="text-muted">Pemain memberi rating tanpa catatan.</p>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted" style="margin-top:10px;">Belum ada ulasan. Ulasan dapat ditulis setelah booking berstatus selesai.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <aside class="schedule-card" aria-label="Pilih jadwal">
                    <div class="schedule-head">
                        <?= courtDiagram() ?>
                        <span class="eyebrow">Pilih jadwal</span>
                        <h3><?= formatHariId($tanggalDipilih) ?>, <?= formatTanggalId($tanggalDipilih) ?></h3>
                        <p><span id="slotTersediaCount"><?= $slotTersedia ?></span> dari <?= count($slots) ?> slot masih kosong</p>
                    </div>

                    <div class="schedule-body">
                        <div class="date-strip">
                            <?php foreach ($pilihanTanggal as $tgl): ?>
                                <?php $isActive = $tgl === $tanggalDipilih; ?>
                                <a href="?id=<?= (int) $courtId ?>&tanggal=<?= $tgl ?>" class="date-chip <?= $isActive ? 'active' : '' ?>" aria-label="<?= formatHariId($tgl) ?>, <?= formatTanggalId($tgl) ?>" <?= $isActive ? 'aria-current="date"' : '' ?>>
                                    <span class="day"><?= substr(formatHariId($tgl), 0, 3) ?></span>
                                    <span class="date"><?= date('d/m', strtotime($tgl)) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <div class="schedule-section-label">
                            <span>Slot jam</span>
                            <small>1 jam / slot</small>
                        </div>

                        <form method="GET" action="booking.php" id="formJadwal">
                            <input type="hidden" name="court_id" value="<?= (int) $courtId ?>">
                            <input type="hidden" name="tanggal" value="<?= clean($tanggalDipilih) ?>">

                            <?php $labelSlotState = ['tersedia' => 'Tersedia', 'terisi' => 'Terisi', 'berlangsung' => 'Berlangsung', 'terlewat' => 'Terlewat']; ?>
                            <div class="slot-grid">
                                <?php foreach ($slots as $slot): ?>
                                    <label class="slot-option">
                                        <input type="radio" name="jam_mulai" value="<?= $slot['start'] ?>"
                                               data-mulai="<?= $slot['start'] ?>" data-selesai="<?= $slot['end'] ?>"
                                               data-state="<?= $slot['state'] ?>"
                                               <?= $slot['available'] ? '' : 'disabled' ?> required>
                                        <span class="slot-box">
                                            <span class="slot-hours"><?= $slot['start'] ?> – <?= $slot['end'] ?></span>
                                            <span class="slot-state"><?= $labelSlotState[$slot['state']] ?? 'Terisi' ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <?php if (empty($slots)): ?>
                                <p class="text-muted" style="margin-top:10px;">Lapangan ini belum memiliki slot jam.</p>
                            <?php endif; ?>

                            <div class="slot-legend">
                                <span><i class="legend-dot"></i> Kosong</span>
                                <span><i class="legend-dot is-selected"></i> Dipilih</span>
                                <span><i class="legend-dot is-full"></i> Terisi</span>
                            </div>

                            <div class="schedule-cta">
                                <button type="submit" class="btn btn-primary" id="btnLanjutBooking" <?= $slotTersedia === 0 ? 'disabled' : '' ?>>
                                    Lanjut ke booking <?= svgIcon('arrow-right', 'icon') ?>
                                </button>
                                <p>Harga <?= formatRupiah($court['price_per_hour']) ?> per jam · promo dihitung di langkah berikutnya.</p>
                            </div>
                        </form>
                    </div>
                </aside>
            </div>
        </div>
    </section>
</main>

<script>
(function () {
    var form = document.getElementById('formJadwal');
    if (!form) return;
    var inputs = form.querySelectorAll('input[name="jam_mulai"]');
    var btn = document.getElementById('btnLanjutBooking');
    var counter = document.getElementById('slotTersediaCount');
    var labelState = { tersedia: 'Tersedia', terisi: 'Terisi', berlangsung: 'Berlangsung', terlewat: 'Terlewat' };

    // Waktu & tanggal sekarang di zona Asia/Jakarta (bukan jam perangkat pengguna)
    function nowJakarta() {
        var teks = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Asia/Jakarta', hour12: false,
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        }).format(new Date());
        var p = teks.split(':');
        return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0) + ((parseInt(p[2], 10) || 0) / 60);
    }

    function tanggalJakarta() {
        return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
    }

    function menitSlot(hhmm) {
        var p = (hhmm || '').split(':');
        return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0);
    }

    // Status jadwal diperbarui otomatis mengikuti waktu sekarang
    function perbaruiSlot() {
        var tanggal = form.querySelector('input[name="tanggal"]');
        var isHariIni = tanggal && tanggal.value === tanggalJakarta();
        var sekarang = isHariIni ? nowJakarta() : -1;
        var tersedia = 0;

        Array.prototype.forEach.call(inputs, function (input) {
            var mulai = menitSlot(input.getAttribute('data-mulai'));
            var selesai = menitSlot(input.getAttribute('data-selesai'));
            var state = input.getAttribute('data-state');

            if (state === 'tersedia' && isHariIni && mulai <= sekarang) {
                state = sekarang < selesai ? 'berlangsung' : 'terlewat';
                input.setAttribute('data-state', state);
                input.disabled = true;
                if (input.checked) input.checked = false;
            }

            var teksState = input.closest('.slot-box').querySelector('.slot-state');
            if (teksState) teksState.textContent = labelState[state] || state;

            if (!input.disabled) tersedia++;
        });

        if (counter) counter.textContent = tersedia;
        if (btn) btn.disabled = tersedia === 0;
    }

    // Validasi frontend: slot yang sudah mulai / sedang berlangsung tidak bisa dikirim
    form.addEventListener('submit', function (e) {
        var dipilih = form.querySelector('input[name="jam_mulai"]:checked');
        if (!dipilih) {
            e.preventDefault();
            return;
        }
        var tanggal = form.querySelector('input[name="tanggal"]');
        var isHariIni = tanggal && tanggal.value === tanggalJakarta();
        if (isHariIni && menitSlot(dipilih.getAttribute('data-mulai')) <= nowJakarta()) {
            e.preventDefault();
            alert('Slot yang kamu pilih sudah terlewat atau sedang berlangsung. Silakan pilih slot mendatang.');
            perbaruiSlot();
        }
    });

    perbaruiSlot();
    setInterval(perbaruiSlot, 15000);
})();
</script>

<?php require_once 'includes/footer.php'; ?>
