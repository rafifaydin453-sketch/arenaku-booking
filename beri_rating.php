<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';   // WAJIB LOGIN

$pageTitle = 'Beri Rating';
$userId    = (int) $_SESSION['user_id'];

$bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;

// ===== AMBIL DATA BOOKING MILIK USER =====
$stmt = $koneksi->prepare(
    "SELECT bookings.*, courts.name AS court_name, courts.location
     FROM bookings
     JOIN courts ON bookings.court_id = courts.id
     WHERE bookings.id = ? AND bookings.user_id = ?
     LIMIT 1"
);
$stmt->execute([$bookingId, $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlashError('Booking tidak ditemukan.');
    redirect('booking_saya.php');
}

// Booking harus sudah selesai & belum pernah dirating
if ($booking['booking_status'] !== 'completed') {
    setFlashError('Rating hanya bisa diberikan untuk booking yang sudah selesai.');
    redirect('detail_booking.php?id=' . $bookingId);
}

$ratingLama = getRatingBooking($koneksi, $bookingId);
if ($ratingLama) {
    setFlashError('Booking ini sudah pernah kamu beri rating.');
    redirect('detail_booking.php?id=' . $bookingId);
}

// ===== PROSES SUBMIT RATING =====
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stars  = isset($_POST['stars']) ? (int) $_POST['stars'] : 0;
    $review = trim($_POST['review'] ?? '');

    if ($stars < 1 || $stars > 5) {
        $errors[] = 'Silakan pilih jumlah bintang (1-5).';
    }
    if (mb_strlen($review) > 1000) {
        $errors[] = 'Ulasan maksimal 1000 karakter.';
    }

    if (empty($errors)) {
        $ins = $koneksi->prepare(
            "INSERT INTO ratings (booking_id, court_id, user_id, stars, review)
             VALUES (?, ?, ?, ?, ?)"
        );
        $ins->execute([
            $bookingId,
            (int) $booking['court_id'],
            $userId,
            $stars,
            $review !== '' ? $review : null,
        ]);

        setFlashSuccess('Terima kasih! Rating kamu sudah tersimpan.');
        redirect('detail_booking.php?id=' . $bookingId);
    }
}

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<section class="section">
    <div class="container" style="max-width:640px;">

        <div class="page-head" style="justify-content:center; text-align:center;">
            <div>
                <span class="eyebrow">Ulasan Pengalaman</span>
                <h2 style="margin-top:12px;">Beri Rating</h2>
                <p>Bagaimana pengalaman bermainmu di lapangan ini?</p>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" style="margin-bottom:16px;">
                <?= implode('<br>', array_map('clean', $errors)) ?>
            </div>
        <?php endif; ?>

        <!-- INFO BOOKING -->
        <div class="card card-soft" style="margin-bottom:16px;">
            <div class="card-body list-row">
                <div class="list-row-main">
                    <div class="thumb">🏟️</div>
                    <div>
                        <strong><?= clean($booking['court_name']) ?></strong>
                        <p>📅 <?= date('d F Y', strtotime($booking['booking_date'])) ?> &bull; 🕒 <?= date('H:i', strtotime($booking['start_time'])) ?> - <?= date('H:i', strtotime($booking['end_time'])) ?></p>
                        <p>📍 <?= clean($booking['location']) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- FORM RATING -->
        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <label style="display:block; font-weight:700; margin-bottom:8px;">Rating Bintang</label>
                    <div class="rating-input" style="font-size:40px; line-height:1; letter-spacing:6px; user-select:none;">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span class="star" data-value="<?= $i ?>" style="cursor:pointer; color:var(--color-border);">★</span>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="stars" id="starsField" value="">

                    <div class="form-group" style="margin-top:20px;">
                        <label class="form-label" for="review">Ulasan (opsional)</label>
                        <textarea name="review" id="review" class="form-input" rows="4"
                            placeholder="Ceritakan pengalamanmu bermain di sini..."><?= isset($_POST['review']) ? clean($_POST['review']) : '' ?></textarea>
                    </div>

                    <div style="display:flex; gap:12px; margin-top:16px;">
                        <a href="detail_booking.php?id=<?= $bookingId ?>" class="btn btn-outline" style="flex:1;">Batal</a>
                        <button type="submit" class="btn btn-primary" style="flex:1;">Kirim Rating</button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</section>

<script>
(function () {
    var stars = document.querySelectorAll('.rating-input .star');
    var field = document.getElementById('starsField');

    function paint(value) {
        stars.forEach(function (s) {
            s.style.color = parseInt(s.dataset.value, 10) <= value
                ? 'var(--color-warning, #f59e0b)'
                : 'var(--color-border)';
        });
    }

    stars.forEach(function (s) {
        s.addEventListener('mouseenter', function () { paint(parseInt(s.dataset.value, 10)); });
        s.addEventListener('click', function () {
            var v = parseInt(s.dataset.value, 10);
            field.value = v;
            paint(v);
        });
    });

    document.querySelector('.rating-input').addEventListener('mouseleave', function () {
        paint(parseInt(field.value || 0, 10));
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
