<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';   // WAJIB LOGIN
require_once 'includes/midtrans_helper.php';
require_once 'includes/simulasi_helper.php';

$pageTitle = 'Pembayaran';
$errors = [];

$bookingId = isset($_REQUEST['booking_id']) ? (int) $_REQUEST['booking_id'] : 0;

if ($bookingId <= 0) {
    die('Booking tidak ditemukan.');
}

// ===== DATA BOOKING + LAPANGAN + PAYMENT =====
$stmt = $koneksi->prepare(
    "SELECT bookings.*, courts.name AS court_name, courts.location, payments.id AS payment_id,
            payments.payment_status, payments.payment_method, payments.midtrans_order_id,
            payments.snap_token, payments.payment_code, payments.bank, payments.expire_time,
            payments.status_detail
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

if ($booking['payment_status'] === 'paid') {
    redirect('/arenaku/detail_booking.php?id=' . $bookingId);
}

if ($booking['booking_status'] !== 'pending_payment') {
    redirect('/arenaku/detail_booking.php?id=' . $bookingId);
}

$snapToken = null;
$snapError = null;
$midtransConfigured = midtransReady();

// =====================================================================
// BUAT TRANSAKSI MIDTRANS (POST)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $metode = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';

    if (!csrfVerifikasi(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
        $errors[] = 'Sesi form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    }

    $metodeValid = ['qris', 'transfer_bank', 'e_wallet'];
    if (!in_array($metode, $metodeValid, true)) {
        $errors[] = 'Silakan pilih metode pembayaran.';
    }

    if (empty($errors)) {
        if (!$midtransConfigured) {
            $errors[] = 'Midtrans belum aktif. Hubungi pengelola.';
        } else {
            $result = midtransCreateSnapTransaction($booking, $metode);

            if ($result['ok']) {
                $snapToken = $result['snap_token'];
                $booking['payment_method'] = $metode;
            } else {
                $errors[] = $result['error'];
            }
        }
    }
}

$clientKey = $midtransConfigured ? midtransConfig()['client_key'] : '';
$snapScriptUrl = ($midtransConfigured && midtransConfig()['is_production'])
    ? 'https://app.midtrans.com/snap/snap.js'
    : 'https://app.sandbox.midtrans.com/snap/snap.js';

$metodeDipilih = in_array($booking['payment_method'], ['qris', 'transfer_bank', 'e_wallet'], true)
    ? $booking['payment_method']
    : 'qris';

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<main id="main-content" class="section">
    <div class="container" style="max-width:1040px;">
        <div class="stepper" aria-label="Langkah booking">
            <div class="stepper-item done">
                <span class="step-dot"><?= svgIcon('check', 'icon') ?></span>
                <p>Lapangan</p>
            </div>
            <div class="stepper-item done">
                <span class="step-dot"><?= svgIcon('check', 'icon') ?></span>
                <p>Konfirmasi</p>
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

        <div class="page-head" style="justify-content:center; text-align:center;">
            <div>
                <span class="eyebrow">Langkah 3 dari 4</span>
                <h1 style="font-size:clamp(2rem,3.6vw,3rem); margin-top:12px;">Selesaikan pembayaran</h1>
                <p style="margin-inline:auto;">Pilih metode pembayaran Midtrans untuk menyelesaikan booking.</p>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-warning" style="margin-bottom:18px;">
                <?= svgIcon('alert', 'icon') ?>
                <div>
                    <?php foreach ($errors as $err): ?>
                        <p><?= clean($err) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$midtransConfigured): ?>
            <div class="alert alert-warning" style="margin-bottom:18px;">
                <?= svgIcon('alert', 'icon') ?>
                <div>
                    <strong>Konfigurasi Midtrans belum lengkap.</strong>
                    <p>Salin <code>config/midtrans_keys.example.php</code> menjadi <code>config/midtrans_keys.php</code>, lalu isi Server Key dan Client Key Sandbox dari dashboard Midtrans.</p>
                </div>
            </div>
        <?php endif; ?>

        <div class="checkout-grid">
            <div class="checkout-main">
                <form method="POST" action="pembayaran.php" id="formPembayaran" class="panel">
                    <input type="hidden" name="booking_id" value="<?= (int) $bookingId ?>">
                    <?= csrfField() ?>

                    <div class="panel-head">
                        <div>
                            <h3>Metode pembayaran</h3>
                            <p>Midtrans memproses pembayaran otomatis dan aman.</p>
                        </div>
                    </div>

                    <div class="panel-body">
                        <div class="payment-methods">
                            <label class="pay-option" <?= $midtransConfigured ? '' : 'style="opacity:.55;"' ?>>
                                <input class="pay-option-input" type="radio" name="payment_method" value="qris" required <?= $metodeDipilih === 'qris' ? 'checked' : '' ?> <?= $midtransConfigured ? '' : 'disabled' ?>>
                                <span class="pay-radio" aria-hidden="true"></span>
                                <span class="pay-option-icon"><?= svgIcon('qr', 'icon') ?></span>
                                <span class="pay-option-copy">
                                    <strong>QRIS</strong>
                                    <span>Scan satu QR dari aplikasi bank atau e-wallet apa pun.</span>
                                </span>
                            </label>

                            <label class="pay-option" <?= $midtransConfigured ? '' : 'style="opacity:.55;"' ?>>
                                <input class="pay-option-input" type="radio" name="payment_method" value="transfer_bank" required <?= $metodeDipilih === 'transfer_bank' ? 'checked' : '' ?> <?= $midtransConfigured ? '' : 'disabled' ?>>
                                <span class="pay-radio" aria-hidden="true"></span>
                                <span class="pay-option-icon"><?= svgIcon('bank', 'icon') ?></span>
                                <span class="pay-option-copy">
                                    <strong>Virtual Account</strong>
                                    <span>BCA, BNI, BRI, atau Mandiri. Nomor VA muncul di halaman pembayaran.</span>
                                </span>
                            </label>

                            <label class="pay-option" <?= $midtransConfigured ? '' : 'style="opacity:.55;"' ?>>
                                <input class="pay-option-input" type="radio" name="payment_method" value="e_wallet" required <?= $metodeDipilih === 'e_wallet' ? 'checked' : '' ?> <?= $midtransConfigured ? '' : 'disabled' ?>>
                                <span class="pay-radio" aria-hidden="true"></span>
                                <span class="pay-option-icon"><?= svgIcon('wallet', 'icon') ?></span>
                                <span class="pay-option-copy">
                                    <strong>E-Wallet</strong>
                                    <span>GoPay atau ShopeePay, ikuti instruksi pada halaman pembayaran.</span>
                                </span>
                            </label>

                        </div>

                        <div class="alert alert-info payment-note">
                            <?= svgIcon('shield', 'icon') ?>
                            <div>Pembayaran diproses oleh <strong>Midtrans</strong>. ArenaKu tidak menyimpan data kartu atau kredensial pembayaran kamu.</div>
                        </div>

                        <div class="checkout-actions">
                            <a href="detail_lapangan.php?id=<?= (int) $booking['court_id'] ?>" class="btn btn-outline">Kembali</a>
                            <button type="submit" class="btn btn-primary">
                                Lanjutkan pembayaran <?= svgIcon('arrow-right', 'icon') ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <aside class="checkout-aside">
                <div class="summary-card">
                    <div class="summary-head">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                            <h3>Ringkasan pesanan</h3>
                            <span class="badge badge-warning">Menunggu bayar</span>
                        </div>
                        <p>Kode booking <strong><?= clean($booking['booking_code']) ?></strong></p>
                    </div>
                    <div class="summary-body">
                        <div class="summary-list">
                            <div class="summary-row"><span>Lapangan</span><span><?= clean($booking['court_name']) ?></span></div>
                            <div class="summary-row"><span>Lokasi</span><span><?= clean($booking['location']) ?></span></div>
                            <div class="summary-row"><span>Tanggal</span><span><?= formatTanggalId($booking['booking_date']) ?></span></div>
                            <div class="summary-row"><span>Jam</span><span><?= date('H:i', strtotime($booking['start_time'])) ?> – <?= date('H:i', strtotime($booking['end_time'])) ?></span></div>
                            <div class="summary-row"><span>Durasi</span><span><?= (int) $booking['duration'] ?> jam × <?= formatRupiah($booking['price_per_hour']) ?></span></div>
                            <div class="summary-row"><span>Subtotal</span><span><?= formatRupiah($booking['total_price']) ?></span></div>
                            <?php if ((float) $booking['discount_amount'] > 0): ?>
                                <div class="summary-row">
                                    <span>Diskon<?= $booking['promo_code'] ? ' · ' . clean($booking['promo_code']) : '' ?></span>
                                    <span class="discount-text">− <?= formatRupiah($booking['discount_amount']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="summary-total">
                            <span>Total bayar</span>
                            <strong><?= formatRupiah($booking['total_after_discount'] !== null ? $booking['total_after_discount'] : $booking['total_price']) ?></strong>
                        </div>
                        <p class="summary-note">Slot kamu ditahan sementara. Selesaikan pembayaran agar booking dikonfirmasi.</p>
                        <a href="cek_status_pembayaran.php?booking_id=<?= (int) $bookingId ?>" class="btn btn-outline btn-block" style="margin-top:14px;">Cek status pembayaran</a>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

<?php if ($snapToken): ?>
<script src="<?= clean($snapScriptUrl) ?>"
        data-client-key="<?= clean($clientKey) ?>"></script>
<script>
(function () {
    // Status LUNAS hanya ditentukan backend (keputusan admin atas bukti
    // dan/atau notifikasi Midtrans), bukan oleh popup.
    // Semua akhir popup diarahkan ke halaman konfirmasi untuk unggah bukti.
    snap.pay('<?= clean($snapToken) ?>', {
        onSuccess: function () {
            window.location.href = 'konfirmasi_pembayaran.php?booking_id=<?= (int) $bookingId ?>';
        },
        onPending: function () {
            window.location.href = 'konfirmasi_pembayaran.php?booking_id=<?= (int) $bookingId ?>';
        },
        onClose: function () {
            window.location.href = 'konfirmasi_pembayaran.php?booking_id=<?= (int) $bookingId ?>';
        },
        onError: function () {
            window.location.href = 'konfirmasi_pembayaran.php?booking_id=<?= (int) $bookingId ?>';
        }
    });
})();
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
