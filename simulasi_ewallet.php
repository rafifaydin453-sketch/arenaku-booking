<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/simulasi_helper.php';

if (!simulasiAktif()) {
    redirect('/arenaku/index.php');
}

require_once 'includes/functions.php';
require_once 'includes/auth.php';

$pageTitle = 'Pilih E-Wallet (Simulasi)';

$bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
if ($bookingId <= 0) {
    die('Permintaan simulasi tidak valid.');
}

$stmt = $koneksi->prepare(
    "SELECT bookings.*, courts.name AS court_name, courts.location,
            payments.id AS payment_id, payments.payment_status, payments.is_simulated,
            payments.payment_proof
     FROM bookings
     JOIN courts ON bookings.court_id = courts.id
     JOIN payments ON payments.booking_id = bookings.id
     WHERE bookings.id = ? AND bookings.user_id = ?"
);
$stmt->execute([$bookingId, $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking) {
    die('Booking tidak ditemukan atau bukan milik kamu.');
}

if ($booking['payment_status'] === 'paid' || $booking['booking_status'] !== 'pending_payment') {
    redirect('/arenaku/detail_booking.php?id=' . $bookingId);
}

if ($booking['payment_status'] === 'pending'
    && !empty($booking['payment_proof'])
    && !empty($booking['is_simulated'])) {
    redirect('/arenaku/hasil_pembayaran.php?booking_id=' . $bookingId);
}

$totalBayar = (float) ($booking['total_after_discount'] !== null
    ? $booking['total_after_discount']
    : $booking['total_price']);

$providers = simulasiEwalletProviders();

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<section class="section">
    <div class="container" style="max-width:640px;">

        <div class="stepper">
            <div class="stepper-item done">
                <span class="step-dot">1</span>
                <p>Lapangan</p>
            </div>
            <div class="stepper-item done">
                <span class="step-dot">2</span>
                <p>Data</p>
            </div>
            <div class="stepper-item current">
                <span class="step-dot">3</span>
                <p>Pembayaran</p>
            </div>
            <div class="stepper-item">
                <span class="step-dot">4</span>
                <p>Selesai</p>
            </div>
        </div>

        <div class="sim-banner" style="margin-top:20px;">
            MODE SIMULASI - TIDAK ADA UANG SUNGGUHAN
            <p style="font-weight:500; font-size:13px; margin-top:6px;">
                Pilih provider e-wallet untuk melanjutkan simulasi. Tidak ada aplikasi GoPay,
                ShopeePay, atau DANA yang dibuka dan tidak ada transaksi ke Midtrans.
            </p>
        </div>

        <div class="page-head" style="justify-content:center; text-align:center; margin-top:16px;">
            <div>
                <span class="eyebrow">E-Wallet Simulasi</span>
                <h1 style="font-size:clamp(1.6rem,3vw,2.2rem); margin-top:12px;">Pilih provider e-wallet</h1>
                <p style="margin-top:6px;">Pilih salah satu provider sebelum melanjutkan ke halaman pembayaran simulasi.</p>
                <p style="margin-top:10px;"><span class="badge badge-warning">Menunggu Bukti Pembayaran</span></p>
            </div>
        </div>

        <div class="card card-soft" style="margin-top:16px;">
            <div class="card-body">
                <form method="GET" action="simulasi_metode.php" id="formProvider">
                    <input type="hidden" name="booking_id" value="<?= (int) $bookingId ?>">
                    <input type="hidden" name="metode" value="e_wallet">

                    <div class="payment-methods">
                        <?php foreach ($providers as $channel => $provider): ?>
                            <label class="pay-option">
                                <input class="pay-option-input" type="radio" name="channel"
                                       value="<?= clean($channel) ?>" data-label="<?= clean($provider['label']) ?>" required>
                                <span class="pay-radio" aria-hidden="true"></span>
                                <span class="pay-option-icon" style="width:64px; height:40px; background:var(--white); border:1px solid var(--line);">
                                    <img src="<?= clean(simulasiEwalletLogoUrl($channel)) ?>" alt="Logo <?= clean($provider['label']) ?>"
                                         style="display:block; max-width:52px; max-height:28px;">
                                </span>
                                <span class="pay-option-copy">
                                    <strong><?= clean($provider['label']) ?></strong>
                                    <span><?= clean($provider['desc']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" id="btnLanjutProvider" class="btn btn-primary btn-block" style="margin-top:16px;" disabled>
                        Pilih provider untuk melanjutkan
                    </button>
                    <p style="font-size:12px; color:var(--color-text-light); text-align:center; margin-top:8px;">
                        Provider yang dipilih akan tersimpan saat bukti pembayaran dikirim.
                    </p>
                </form>
            </div>
        </div>

        <div class="card card-soft" style="margin-top:16px;">
            <div class="card-body">
                <h4>Ringkasan Pesanan</h4>
                <div class="info-block" style="margin-top:12px;">
                    <div class="info-row"><span>Kode</span><span><?= clean($booking['booking_code']) ?></span></div>
                    <div class="info-row"><span>Lapangan</span><span><?= clean($booking['court_name']) ?></span></div>
                    <div class="info-row"><span>Tanggal</span><span><?= date('d F Y', strtotime($booking['booking_date'])) ?></span></div>
                    <div class="info-row"><span>Jam</span><span><?= date('H:i', strtotime($booking['start_time'])) ?> - <?= date('H:i', strtotime($booking['end_time'])) ?></span></div>
                    <div class="info-row"><span>Total</span><span class="text-price" style="font-size:18px;"><?= formatRupiah($totalBayar) ?></span></div>
                </div>
            </div>
        </div>

        <div style="display:flex; gap:12px; margin-top:12px;">
            <a href="pembayaran.php?booking_id=<?= (int) $bookingId ?>" class="btn btn-outline btn-block">Kembali ke Pilih Metode</a>
            <a href="detail_booking.php?id=<?= (int) $bookingId ?>" class="btn btn-outline btn-block">Detail Booking</a>
        </div>
    </div>
</section>

<script>
(function () {
    var form = document.getElementById('formProvider');
    var btn = document.getElementById('btnLanjutProvider');
    var inputs = form.querySelectorAll('input[name="channel"]');

    function perbaruiTombol() {
        var dipilih = form.querySelector('input[name="channel"]:checked');
        btn.disabled = !dipilih;
        btn.innerText = dipilih
            ? 'Lanjut ke ' + dipilih.dataset.label + ' Simulasi'
            : 'Pilih provider untuk melanjutkan';
    }

    Array.prototype.forEach.call(inputs, function (input) {
        input.addEventListener('change', perbaruiTombol);
    });

    perbaruiTombol();
})();
</script>

<?php require_once 'includes/footer.php'; ?>
