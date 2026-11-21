<?php
/**
 * HALAMAN KONFIRMASI PEMBAYARAN
 * -----------------------------
 * Dituju ketika pengguna menutup popup Midtrans (tombol X) atau transaksi
 * belum selesai. Menampilkan informasi booking + nominal, lalu formulir
 * unggah bukti pembayaran (foto, dengan pratinjau).
 *
 * Mengirim bukti TIDAK otomatis melunasi booking. Status menjadi
 * "Menunggu Keputusan Admin" sampai admin menerima / menolak bukti.
 * Saat menunggu keputusan, unggah ulang diblokir (tombol nonaktif).
 */

session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/simulasi_helper.php';

$pageTitle = 'Konfirmasi Pembayaran';

// booking_id bisa datang dari URL (GET) maupun dari form upload (POST)
$bookingId = isset($_REQUEST['booking_id']) ? (int) $_REQUEST['booking_id'] : 0;
if ($bookingId <= 0) {
    die('Booking tidak ditemukan.');
}

$errors = [];

// ===== DATA BOOKING + PAYMENT (hanya milik user yang login) =====
$stmt = $koneksi->prepare(
    "SELECT bookings.*, courts.name AS court_name, courts.location,
            payments.id AS payment_id, payments.payment_status, payments.payment_method,
            payments.payment_type, payments.payment_code, payments.bank,
            payments.status_detail, payments.expire_time, payments.midtrans_order_id,
            payments.is_simulated, payments.payment_proof, payments.proof_status,
            payments.payment_proof_uploaded_at, payments.rejection_reason,
            payments.verified_at, payments.paid_at, payments.amount
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

$paymentStatus = $booking['payment_status'];
$proofStatus   = $booking['proof_status'];

// Booking lunas / tidak aktif -> tampilkan halaman hasil (status final)
if ($paymentStatus === 'paid' || $booking['booking_status'] !== 'pending_payment') {
    redirect('/arenaku/hasil_pembayaran.php?booking_id=' . $bookingId);
}

$totalBayar = (float) ($booking['total_after_discount'] !== null
    ? $booking['total_after_discount']
    : $booking['total_price']);

// Metode dipilih pengguna sebelum popup Midtrans dibuka; dibaca dari database.
$metodeLabel = [
    'qris'          => 'QRIS',
    'transfer_bank' => 'Virtual Account',
    'e_wallet'      => 'E-Wallet',
];
$metodeTampil = $metodeLabel[$booking['payment_method']] ?? 'Midtrans';

$bisaUnggah = in_array($paymentStatus, ['unpaid', 'pending', 'failed', 'expired'], true)
    && $proofStatus !== 'pending';

// =====================================================================
// PROSES UNGGAH BUKTI (POST, pola PRG)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerifikasi(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
        $errors[] = 'Sesi form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } else {
        $hasil = pembayaranKirimBukti(
            $bookingId,
            $booking['payment_method'],
            '',
            isset($_FILES['bukti']) ? $_FILES['bukti'] : null,
            (int) $_SESSION['user_id'],
            false
        );

        if ($hasil['ok']) {
            // Arahkan ke halaman status pembayaran: state "Menunggu keputusan admin".
            redirect('/arenaku/hasil_pembayaran.php?booking_id=' . $bookingId);
        }
        $errors[] = $hasil['error'];
    }
}

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<style>
/* Gaya kotak unggah bukti (konsisten dengan halaman simulasi) */
.sim-upload {
    display: grid;
    grid-template-columns: 170px minmax(0, 1fr);
    gap: 16px;
    align-items: center;
    padding: 14px;
    border: 1px dashed var(--line-strong);
    border-radius: var(--radius-md);
    background: var(--paper-2);
    text-align: left;
}
.sim-upload-preview {
    position: relative;
    display: grid;
    place-items: center;
    width: 100%;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    background: var(--white);
    color: var(--muted);
}
.sim-upload-preview img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: var(--white);
}
.sim-upload-placeholder {
    display: grid;
    justify-items: center;
    gap: 5px;
    padding: 12px;
    text-align: center;
}
.sim-upload-placeholder .icon {
    width: 26px;
    height: 26px;
    color: var(--forest);
    opacity: 0.5;
}
.sim-upload-placeholder small {
    font-size: 0.7rem;
    font-weight: 600;
}
.sim-upload-title {
    display: block;
    font-size: 0.9rem;
    color: var(--ink);
}
.sim-upload-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}
.sim-upload-input {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
@media (max-width: 560px) {
    .sim-upload {
        grid-template-columns: 1fr;
    }
    .sim-upload-preview {
        max-width: 220px;
    }
}
</style>

<main id="main-content" class="section">
    <div class="container">
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

        <div class="result-shell" style="margin-top:20px;">
            <div class="result-head">
                <?php if ($proofStatus === 'pending'): ?>
                    <span class="result-mark is-pending"><?= svgIcon('clock', 'icon') ?></span>
                    <h1>Menunggu keputusan admin</h1>
                    <p>Bukti pembayaran sudah dikirim dan sedang diperiksa admin. Booking akan dikonfirmasi setelah bukti diterima.</p>
                <?php elseif ($proofStatus === 'rejected'): ?>
                    <span class="result-mark is-danger"><?= svgIcon('x-circle', 'icon') ?></span>
                    <h1>Bukti pembayaran ditolak</h1>
                    <p>Silakan periksa kembali nominal dan tujuan pembayaran, lalu unggah bukti pembayaran yang baru.</p>
                <?php else: ?>
                    <span class="result-mark is-pending"><?= svgIcon('receipt', 'icon') ?></span>
                    <h1>Konfirmasi pembayaran</h1>
                    <p>Popup pembayaran ditutup sebelum transaksi selesai. Lakukan pembayaran sesuai metode yang kamu pilih, lalu unggah foto bukti pembayaran untuk diperiksa admin.</p>
                <?php endif; ?>

                <div class="result-badges">
                    <?php
                        $simLabel = simulasiStatusPembayaran($booking);
                        $label = $simLabel !== null ? $simLabel : labelStatusPembayaran($paymentStatus);
                    ?>
                    <span class="badge <?= $label[1] ?>"><?= clean($label[0]) ?></span>
                    <?php if (!empty($booking['is_simulated'])): ?>
                        <span class="badge badge-warning">Mode simulasi · tanpa uang sungguhan</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" style="margin-bottom:18px;">
                    <?= svgIcon('alert', 'icon') ?>
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <p><?= clean($err) ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="result-summary">
                <div class="result-summary-head">
                    <span>Kode <?= clean($booking['booking_code']) ?></span>
                    <span><?= formatTanggalId($booking['booking_date']) ?></span>
                </div>
                <div class="result-summary-body">
                    <div class="summary-list">
                        <div class="summary-row"><span>Lapangan</span><span><?= clean($booking['court_name']) ?></span></div>
                        <div class="summary-row"><span>Lokasi</span><span><?= clean($booking['location']) ?></span></div>
                        <div class="summary-row"><span>Tanggal</span><span><?= formatHariId($booking['booking_date']) ?>, <?= formatTanggalId($booking['booking_date']) ?></span></div>
                        <div class="summary-row"><span>Jam</span><span><?= date('H:i', strtotime($booking['start_time'])) ?> – <?= date('H:i', strtotime($booking['end_time'])) ?></span></div>
                        <div class="summary-row"><span>Durasi</span><span><?= (int) $booking['duration'] ?> jam × <?= formatRupiah($booking['price_per_hour']) ?></span></div>
                        <div class="summary-row"><span>Metode</span><span><?= clean($metodeTampil) ?></span></div>
                        <?php if ($proofStatus === 'rejected' && !empty($booking['rejection_reason'])): ?>
                            <div class="summary-row">
                                <span>Alasan penolakan</span>
                                <span class="discount-text"><?= clean($booking['rejection_reason']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="summary-total">
                        <span>Total bayar</span>
                        <strong><?= formatRupiah($totalBayar) ?></strong>
                    </div>

                    <?php if ($proofStatus === 'pending'): ?>
                        <div class="alert alert-info" style="margin-top:16px;">
                            <?= svgIcon('clock', 'icon') ?>
                            <div>
                                <strong>Bukti sedang diperiksa admin.</strong>
                                <p>Kamu tidak perlu mengirim bukti lagi. Status akan berubah setelah admin memutuskan.</p>
                                <?php if (!empty($booking['payment_proof_uploaded_at'])): ?>
                                    <p>Dikirim pada <?= formatTanggalId($booking['payment_proof_uploaded_at'], 'panjang') ?> · <?= date('H:i', strtotime($booking['payment_proof_uploaded_at'])) ?> WIB.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary btn-block" style="margin-top:14px;" disabled>
                            Menunggu Keputusan Admin
                        </button>
                    <?php elseif ($bisaUnggah): ?>
                        <form method="POST" action="konfirmasi_pembayaran.php" enctype="multipart/form-data" id="formBukti" novalidate>
                            <?= csrfField() ?>
                            <input type="hidden" name="booking_id" value="<?= (int) $bookingId ?>">

                            <div class="sim-upload" style="margin-top:16px;">
                                <div class="sim-upload-preview">
                                    <img id="buktiPreview" alt="Pratinjau bukti pembayaran" hidden>
                                    <span class="sim-upload-placeholder" id="buktiPlaceholder">
                                        <?= svgIcon('receipt', 'icon') ?>
                                        <small>Belum ada bukti dipilih</small>
                                    </span>
                                </div>
                                <div>
                                    <strong class="sim-upload-title">Unggah bukti pembayaran</strong>
                                    <p class="form-hint">JPG, JPEG, atau PNG. Maksimal 3 MB. Pastikan nominal dan tujuan pembayaran terlihat jelas.</p>
                                    <div class="sim-upload-actions">
                                        <label class="btn btn-outline btn-sm" for="buktiPembayaran"><?= svgIcon('plus', 'icon') ?> Pilih Gambar</label>
                                        <input class="sim-upload-input" type="file" id="buktiPembayaran" name="bukti"
                                               accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
                                        <button type="button" class="btn btn-ghost btn-sm" id="btnGantiBukti" hidden>Ganti Gambar</button>
                                    </div>
                                    <p class="form-error" id="buktiError" hidden></p>
                                </div>
                            </div>

                            <button type="submit" id="btnKirimBukti" class="btn btn-primary btn-block" style="margin-top:16px;" disabled>
                                Kirim Bukti Pembayaran
                            </button>
                            <p style="font-size:12px; color:var(--color-text-light); text-align:center; margin-top:8px;">
                                Bukti akan diperiksa admin. Status berubah menjadi <strong>Menunggu Keputusan Admin</strong>.
                            </p>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="result-actions">
                <?php if ($proofStatus !== 'pending'): ?>
                    <a href="pembayaran.php?booking_id=<?= (int) $bookingId ?>" class="btn btn-outline">Buka halaman pembayaran</a>
                <?php endif; ?>
                <a href="detail_booking.php?id=<?= (int) $bookingId ?>" class="btn btn-primary">Lihat detail booking</a>
                <div class="result-actions-row">
                    <a href="booking_saya.php" class="btn btn-outline">Booking saya</a>
                    <a href="lapangan.php" class="btn btn-outline">Cari lapangan</a>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    var form = document.getElementById('formBukti');
    if (!form) return;
    var input = document.getElementById('buktiPembayaran');
    var preview = document.getElementById('buktiPreview');
    var placeholder = document.getElementById('buktiPlaceholder');
    var btnGanti = document.getElementById('btnGantiBukti');
    var btn = document.getElementById('btnKirimBukti');
    var error = document.getElementById('buktiError');
    var objectUrl = '';

    function showError(pesan) {
        error.textContent = pesan || '';
        error.hidden = !pesan;
    }

    function resetPreview() {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = '';
        }
        preview.removeAttribute('src');
        preview.hidden = true;
        placeholder.hidden = false;
        btnGanti.hidden = true;
        btn.disabled = true;
    }

    function validasiFile(file) {
        if (!file) {
            return 'Unggah gambar bukti pembayaran terlebih dahulu.';
        }
        if (!/\.(jpe?g|png)$/i.test(file.name) || !/^image\/(jpeg|png)$/i.test(file.type)) {
            return 'Format gambar harus JPG, JPEG, atau PNG.';
        }
        if (file.size > 3 * 1024 * 1024) {
            return 'Ukuran gambar maksimal 3 MB.';
        }
        return '';
    }

    // Pratinjau gambar sebelum dikirim
    input.addEventListener('change', function () {
        showError('');
        var file = input.files && input.files[0];
        var pesan = validasiFile(file);

        if (pesan) {
            input.value = '';
            resetPreview();
            showError(pesan);
            return;
        }

        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
        }
        objectUrl = URL.createObjectURL(file);
        preview.src = objectUrl;
        preview.hidden = false;
        placeholder.hidden = true;
        btnGanti.hidden = false;
        btn.disabled = false;
    });

    btnGanti.addEventListener('click', function () {
        input.click();
    });

    // Validasi frontend sebelum kirim (validasi final tetap di server)
    form.addEventListener('submit', function (e) {
        var file = input.files && input.files[0];
        var pesan = validasiFile(file);
        if (pesan) {
            e.preventDefault();
            showError(pesan);
            btn.disabled = true;
            return;
        }
        btn.disabled = true;
        btn.innerText = 'Mengirim bukti...';
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
