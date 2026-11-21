<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';   // WAJIB LOGIN untuk booking

$errors = [];

// =====================================================================
// PARAMETER DASAR (court_id, tanggal, jam_mulai)
// =====================================================================
$courtId  = isset($_REQUEST['court_id']) ? (int) $_REQUEST['court_id'] : 0;
$tanggal  = isset($_REQUEST['tanggal']) ? $_REQUEST['tanggal'] : '';
$jamMulai = isset($_REQUEST['jam_mulai']) ? $_REQUEST['jam_mulai'] : '';

if ($courtId <= 0) {
    die('Lapangan tidak valid.');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || $tanggal < date('Y-m-d')) {
    die('Tanggal tidak valid.');
}
if (!preg_match('/^\d{2}:\d{2}$/', $jamMulai)) {
    die('Jam tidak valid.');
}

// ===== DATA LAPANGAN =====
$stmt = $koneksi->prepare(
    "SELECT courts.*, sports.name AS sport_name
     FROM courts JOIN sports ON courts.sport_id = sports.id
     WHERE courts.id = ? AND courts.status = 'active'"
);
$stmt->execute([$courtId]);
$court = $stmt->fetch();

if (!$court) {
    die('Lapangan tidak ditemukan.');
}

if ($jamMulai < date('H:i', strtotime($court['opening_time'])) ||
    $jamMulai >= date('H:i', strtotime($court['closing_time']))) {
    die('Jam yang dipilih di luar jam operasional lapangan.');
}

// =====================================================================
// VALIDASI WAKTU SLOT (ZONA ASIA/JAKARTA)
// Slot hari ini yang sudah mulai (terlewat / sedang berlangsung) ditolak.
// Cek dijalankan untuk GET maupun POST di sisi server, sehingga request
// yang dimanipulasi lewat form/API tetap ditolak.
// =====================================================================
$sekarangWib = waktuSekarangJakarta();
if ($tanggal === $sekarangWib->format('Y-m-d')) {
    $nowMenit = (int) $sekarangWib->format('G') * 60 + (int) $sekarangWib->format('i');
    if (menitDariJam($jamMulai) <= $nowMenit) {
        die('Slot yang dipilih sudah terlewat atau sedang berlangsung. Silakan pilih slot mendatang.');
    }
}

// =====================================================================
// DURASI MAKSIMAL DARI JAM MULAI
// =====================================================================
$stmtBooked = $koneksi->prepare(
    "SELECT start_time, end_time FROM bookings
     WHERE court_id = ? AND booking_date = ?
       AND booking_status IN ('pending_payment', 'confirmed')"
);
$stmtBooked->execute([$courtId, $tanggal]);
$bookedRanges = $stmtBooked->fetchAll();

$maxDurasi = 0;
for ($d = 1; $d <= 3; $d++) {   // batas maksimal 3 jam per booking
    $cekEnd = date('H:i', strtotime($jamMulai) + ($d * 3600));

    if ($cekEnd > date('H:i', strtotime($court['closing_time']))) {
        break;
    }

    $bentrok = false;
    foreach ($bookedRanges as $b) {
        $bStart = strtotime($b['start_time']);
        $bEnd   = strtotime($b['end_time']);
        $reqStart = strtotime($jamMulai);
        $reqEnd   = strtotime($jamMulai) + ($d * 3600);

        if ($reqStart < $bEnd && $bStart < $reqEnd) {
            $bentrok = true;
            break;
        }
    }
    if ($bentrok) break;

    $maxDurasi = $d;
}

if ($maxDurasi === 0) {
    die('Maaf, slot jam yang kamu pilih sudah tidak tersedia. Silakan pilih jam lain.');
}

// =====================================================================
// PROSES BOOKING (POST)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $durasi = isset($_POST['durasi']) ? (int) $_POST['durasi'] : 0;
    $kodePromo = isset($_POST['kode_promo']) ? trim($_POST['kode_promo']) : '';

    if ($durasi < 1 || $durasi > $maxDurasi) {
        $errors[] = 'Durasi tidak valid.';
    }

    $promo = null;
    $diskon = 0;
    $totalHargaTmp = $court['price_per_hour'] * max(0, $durasi);

    if ($kodePromo !== '') {
        $hasilPromo = validasiPromo($koneksi, $kodePromo, $totalHargaTmp);
        if ($hasilPromo['ok']) {
            $promo  = $hasilPromo['promo'];
            $diskon = $hasilPromo['diskon'];
        } else {
            $errors[] = 'Promo: ' . $hasilPromo['error'];
        }
    }

    if (empty($errors)) {
        $jamSelesai = date('H:i:s', strtotime($jamMulai) + ($durasi * 3600));
        $jamMulaiFull = $jamMulai . ':00';
        $totalHarga = $court['price_per_hour'] * $durasi;
        $totalSetelahDiskon = $totalHarga - $diskon;

        try {
            $koneksi->beginTransaction();

            // Kunci baris lapangan: dua request bersamaan untuk lapangan yang sama
            // diurutkan satu per satu. Ini menutup celah double booking saat tabel
            // bookings masih kosong untuk tanggal tersebut (FOR UPDATE tanpa baris
            // tidak mengunci apa pun).
            $stmtLockCourt = $koneksi->prepare("SELECT id FROM courts WHERE id = ? FOR UPDATE");
            $stmtLockCourt->execute([$courtId]);
            $courtLocked = $stmtLockCourt->fetch();

            if (!$courtLocked) {
                // Lapangan hilang di tengah request (sangat jarang); lempar ke catch
                throw new Exception('Lapangan tidak ditemukan saat memproses booking.');
            }

            $stmtLock = $koneksi->prepare(
                "SELECT id, start_time, end_time FROM bookings
                 WHERE court_id = ? AND booking_date = ?
                   AND booking_status IN ('pending_payment', 'confirmed')
                 FOR UPDATE"
            );
            $stmtLock->execute([$courtId, $tanggal]);
            $existingBookings = $stmtLock->fetchAll();

            $reqStart = strtotime($jamMulaiFull);
            $reqEnd   = strtotime($jamSelesai);
            $bentrokFinal = false;

            foreach ($existingBookings as $b) {
                $bStart = strtotime($b['start_time']);
                $bEnd   = strtotime($b['end_time']);
                if ($reqStart < $bEnd && $bStart < $reqEnd) {
                    $bentrokFinal = true;
                    break;
                }
            }

            if ($bentrokFinal) {
                $koneksi->rollBack();
                $errors[] = 'Maaf, slot ini baru saja dibooking user lain. Silakan pilih jam lain.';
            } else {
                $bookingCode = 'ARK-' . date('Ymd', strtotime($tanggal)) . '-' . strtoupper(substr(uniqid(), -4));

                $stmtInsert = $koneksi->prepare(
                    "INSERT INTO bookings
                        (booking_code, user_id, court_id, booking_date, start_time, end_time,
                         duration, price_per_hour, total_price, promo_id, promo_code,
                         discount_amount, total_after_discount, booking_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment')"
                );
                $stmtInsert->execute([
                    $bookingCode,
                    $_SESSION['user_id'],
                    $courtId,
                    $tanggal,
                    $jamMulaiFull,
                    $jamSelesai,
                    $durasi,
                    $court['price_per_hour'],
                    $totalHarga,
                    $promo ? (int) $promo['id'] : null,
                    $promo ? $promo['kode'] : null,
                    $diskon,
                    $totalSetelahDiskon,
                ]);

                $bookingId = $koneksi->lastInsertId();

                if ($promo) {
                    $stmtPakai = $koneksi->prepare("UPDATE promos SET terpakai = terpakai + 1 WHERE id = ?");
                    $stmtPakai->execute([(int) $promo['id']]);
                }

                $stmtPayment = $koneksi->prepare(
                    "INSERT INTO payments (booking_id, payment_method, amount, payment_status)
                     VALUES (?, 'qris', ?, 'unpaid')"
                );
                $stmtPayment->execute([$bookingId, $totalSetelahDiskon]);

                $koneksi->commit();

                redirect('/arenaku/pembayaran.php?booking_id=' . $bookingId);
            }
        } catch (Exception $e) {
            $koneksi->rollBack();
            $errors[] = 'Terjadi kesalahan saat memproses booking. Silakan coba lagi.';
        }
    }
}

$pageTitle = 'Konfirmasi Booking';
require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<main id="main-content" class="section">
    <div class="container">
        <div class="stepper" aria-label="Langkah booking">
            <div class="stepper-item done">
                <span class="step-dot"><?= svgIcon('check', 'icon') ?></span>
                <p>Pilih lapangan</p>
            </div>
            <div class="stepper-item current">
                <span class="step-dot">2</span>
                <p>Konfirmasi</p>
            </div>
            <div class="stepper-item">
                <span class="step-dot">3</span>
                <p>Pembayaran</p>
            </div>
            <div class="stepper-item">
                <span class="step-dot">4</span>
                <p>Selesai</p>
            </div>
        </div>

        <div class="page-head" style="justify-content:center; text-align:center;">
            <div>
                <span class="eyebrow">Langkah 2 dari 4</span>
                <h1 style="font-size:clamp(2rem,3.6vw,3rem); margin-top:12px;">Konfirmasi booking</h1>
                <p style="margin-inline:auto;">Periksa jadwal, tentukan durasi, dan pakai kode promo bila ada.</p>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-warning" style="margin-bottom:20px;">
                <?= svgIcon('alert', 'icon') ?>
                <div>
                    <?php foreach ($errors as $err): ?>
                        <p><?= clean($err) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="booking.php">
            <input type="hidden" name="court_id" value="<?= (int) $courtId ?>">
            <input type="hidden" name="tanggal" value="<?= clean($tanggal) ?>">
            <input type="hidden" name="jam_mulai" value="<?= clean($jamMulai) ?>">

            <div class="checkout-grid">
                <div class="checkout-main">
                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h3>Rincian lapangan</h3>
                                <p>Pastikan nama dan jadwalnya sudah sesuai.</p>
                            </div>
                            <span class="badge badge-lime"><?= clean($court['sport_name']) ?></span>
                        </div>
                        <div class="panel-body">
                            <div class="order-court">
                                <?= courtCover($court, 'is-compact') ?>
                                <div>
                                    <h3><?= clean($court['name']) ?></h3>
                                    <p><?= svgIcon('pin', 'icon') ?> <?= clean($court['location']) ?></p>
                                    <div class="order-meta">
                                        <span class="badge badge-neutral"><?= formatHariId($tanggal) ?>, <?= formatTanggalId($tanggal) ?></span>
                                        <span class="badge badge-neutral">Mulai <?= clean($jamMulai) ?></span>
                                        <span class="badge badge-neutral"><?= formatRupiah($court['price_per_hour']) ?> / jam</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h3>Durasi &amp; promo</h3>
                                <p>Maksimal <?= $maxDurasi ?> jam mulai dari jam ini, agar tidak bentrok dengan jadwal lain.</p>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="durasiSelect">Durasi bermain</label>
                                    <select name="durasi" id="durasiSelect" class="form-select">
                                        <?php for ($d = 1; $d <= $maxDurasi; $d++): ?>
                                            <option value="<?= $d ?>" data-harga="<?= (float) $court['price_per_hour'] * $d ?>" data-selesai="<?= date('H:i', strtotime($jamMulai) + ($d * 3600)) ?>" <?= (isset($_POST['durasi']) && (int) $_POST['durasi'] === $d) ? 'selected' : '' ?>>
                                                <?= $d ?> jam · selesai <?= date('H:i', strtotime($jamMulai) + ($d * 3600)) ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="kodePromo">Kode promo (opsional)</label>
                                    <input type="text" name="kode_promo" id="kodePromo" class="form-input" placeholder="Contoh: ARENA20" value="<?= isset($kodePromo) ? clean($kodePromo) : '' ?>" style="text-transform:uppercase;">
                                    <p class="form-hint">Punya kode? <a href="promo.php" style="color:var(--forest); font-weight:650;">Lihat daftar promo</a></p>
                                </div>
                            </div>

                            <div class="price-preview" style="margin-top:4px;">
                                <span>Subtotal sebelum promo</span>
                                <strong data-subtotal><?= formatRupiah($court['price_per_hour']) ?></strong>
                            </div>
                            <p class="promo-link-note">Diskon promo dihitung ulang oleh server saat kamu menekan tombol konfirmasi.</p>
                        </div>
                    </div>
                </div>

                <aside class="checkout-aside">
                    <div class="summary-card">
                        <div class="summary-head">
                            <h3>Ringkasan</h3>
                            <p>Slot akan dikunci setelah booking dibuat.</p>
                        </div>
                        <div class="summary-body">
                            <div class="summary-list">
                                <div class="summary-row"><span>Lapangan</span><span><?= clean($court['name']) ?></span></div>
                                <div class="summary-row"><span>Tanggal</span><span><?= formatTanggalId($tanggal) ?></span></div>
                                <div class="summary-row"><span>Jam</span><span><?= clean($jamMulai) ?> – <span data-end-time><?= date('H:i', strtotime($jamMulai) + 3600) ?></span></span></div>
                                <div class="summary-row"><span>Durasi</span><span><span data-durasi-label>1</span> jam</span></div>
                                <div class="summary-row is-strong"><span>Harga per jam</span><span><?= formatRupiah($court['price_per_hour']) ?></span></div>
                            </div>

                            <div class="summary-total">
                                <span>Subtotal</span>
                                <strong data-total><?= formatRupiah($court['price_per_hour']) ?></strong>
                            </div>

                            <button type="submit" class="btn btn-primary btn-block" style="margin-top:16px;">
                                Konfirmasi booking <?= svgIcon('arrow-right', 'icon') ?>
                            </button>
                            <a href="detail_lapangan.php?id=<?= (int) $courtId ?>&tanggal=<?= clean($tanggal) ?>" class="btn btn-ghost btn-block" style="margin-top:8px;">Ganti jadwal</a>
                            <p class="summary-note">Dengan melanjutkan, kamu akan diarahkan ke halaman pembayaran. Booking berstatus menunggu pembayaran sampai transaksi berhasil.</p>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </div>
</main>

<script>
(function () {
    var select = document.getElementById('durasiSelect');
    var form = select ? select.closest('form') : null;
    if (!select || !form) return;

    var rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
    var subtotal = form.querySelector('[data-subtotal]');
    var total = form.querySelector('[data-total]');
    var durasiLabel = form.querySelector('[data-durasi-label]');
    var endTime = form.querySelector('[data-end-time]');

    function hitungTotal() {
        var option = select.options[select.selectedIndex];
        var harga = parseInt(option.getAttribute('data-harga'), 10) || 0;
        if (subtotal) subtotal.textContent = rupiah.format(harga);
        if (total) total.textContent = rupiah.format(harga);
        if (durasiLabel) durasiLabel.textContent = option.value;
        if (endTime) endTime.textContent = option.getAttribute('data-selesai') || '';
    }

    select.addEventListener('change', hitungTotal);
    hitungTotal();
})();
</script>

<?php require_once 'includes/footer.php'; ?>
