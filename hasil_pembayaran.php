<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/midtrans_helper.php';
require_once 'includes/simulasi_helper.php';

$pageTitle = 'Hasil Pembayaran';

$bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;

if ($bookingId <= 0) {
    die('Booking tidak ditemukan.');
}

$stmt = $koneksi->prepare(
    "SELECT bookings.*, courts.name AS court_name, courts.location,
            payments.id AS payment_id, payments.payment_status, payments.payment_method,
            payments.payment_type, payments.payment_code, payments.bank,
            payments.status_detail, payments.expire_time, payments.midtrans_order_id,
            payments.paid_at, payments.is_simulated, payments.payment_proof,
            payments.payment_proof_uploaded_at, payments.verified_at,
            payments.proof_status, payments.rejection_reason
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

// Status di DB hanya berubah lewat notifikasi Midtrans, cek status
// server-to-server, atau keputusan admin atas bukti simulasi — TIDAK dari
// callback JavaScript Snap.
$paymentStatus = $booking['payment_status'];
$isSimulasi = !empty($booking['is_simulated']);
$hasProof = !empty($booking['payment_proof']);
$proofStatus = !empty($booking['proof_status']) ? $booking['proof_status'] : 'none';
// Bukti (simulasi maupun alur Midtrans) tersimpan di folder yang sama.
$buktiUrl = $hasProof ? simulasiBuktiUrl($booking['payment_proof']) : '';
$providerLabel = simulasiEwalletProviderLabel($booking);

// Unggah/unggah-ulang bukti selalu lewat halaman konfirmasi pembayaran.
// Halaman simulasi diputus dari alur aktif (file tetap tersimpan sebagai cadangan).
$buktiUploadUrl = '/arenaku/konfirmasi_pembayaran.php?booking_id=' . (int) $bookingId;

$paymentLabel = [
    'unpaid'   => ['Belum Bayar', 'badge-neutral'],
    'pending'  => ['Menunggu Pembayaran', 'badge-warning'],
    'paid'     => ['Sudah Dibayar', 'badge-success'],
    'failed'   => ['Pembayaran Gagal', 'badge-danger'],
    'expired'  => ['Pembayaran Kedaluwarsa', 'badge-danger'],
    'refunded' => ['Sudah Direfund', 'badge-info'],
];
$label = isset($paymentLabel[$paymentStatus]) ? $paymentLabel[$paymentStatus] : [$paymentStatus, 'badge-neutral'];

$simLabel = simulasiStatusPembayaran($booking);
if ($simLabel !== null) {
    $label = $simLabel;
}

if ($hasProof && $proofStatus === 'pending') {
    $markIcon = 'clock';
    $markClass = 'is-pending';
    $judul = 'Menunggu keputusan admin';
    $deskripsi = 'Bukti pembayaran sudah dikirim dan sedang diperiksa admin. Booking akan dikonfirmasi setelah bukti diterima.';
} elseif ($hasProof && $proofStatus === 'rejected') {
    $markIcon = 'x-circle';
    $markClass = 'is-danger';
    $judul = 'Bukti pembayaran ditolak';
    $deskripsi = 'Bukti pembayaran ditolak admin. Periksa kembali nominal dan tujuan pembayaran, lalu unggah bukti baru.';
} elseif ($isSimulasi && in_array($paymentStatus, ['unpaid', 'expired'], true)) {
    $markIcon = 'receipt';
    $markClass = 'is-neutral';
    $judul = 'Menunggu bukti pembayaran';
    $deskripsi = 'Booking sudah dibuat. Lakukan pembayaran simulasi lalu unggah gambar bukti pembayaran.';
} elseif ($paymentStatus === 'paid') {
    $markIcon = 'check-circle';
    $markClass = 'is-success';
    $judul = 'Pembayaran berhasil';
    $deskripsi = 'Booking kamu sudah dikonfirmasi. Datang sesuai jadwal dan tunjukkan kode booking di lokasi.';
} elseif ($paymentStatus === 'pending') {
    $markIcon = 'clock';
    $markClass = 'is-pending';
    $judul = 'Menunggu pembayaran';
    $deskripsi = 'Selesaikan pembayaran sesuai instruksi, atau kirim bukti pembayaran di halaman konfirmasi agar diperiksa admin.';
} elseif ($paymentStatus === 'failed') {
    $markIcon = 'x-circle';
    $markClass = 'is-danger';
    $judul = 'Pembayaran gagal';
    $deskripsi = 'Transaksi tidak berhasil diproses. Kamu bisa mencoba membayar ulang.';
} elseif ($paymentStatus === 'expired') {
    $markIcon = 'x-circle';
    $markClass = 'is-danger';
    $judul = 'Pembayaran kedaluwarsa';
    $deskripsi = 'Batas waktu pembayaran sudah lewat. Silakan buat pembayaran baru dari halaman ini.';
} else {
    $markIcon = 'receipt';
    $markClass = 'is-neutral';
    $judul = 'Menunggu pembayaran';
    $deskripsi = 'Booking sudah dibuat, tetapi pembayaran belum diselesaikan.';
}

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<main id="main-content" class="section">
    <div class="container">
        <div class="result-shell">
            <div class="result-head">
                <span class="result-mark <?= $markClass ?>"><?= svgIcon($markIcon, 'icon') ?></span>
                <h1><?= clean($judul) ?></h1>
                <p><?= clean($deskripsi) ?></p>

                <div class="result-badges">
                    <span class="badge <?= $label[1] ?>"><?= clean($label[0]) ?></span>
                    <?php if (!empty($booking['is_simulated'])): ?>
                        <span class="badge badge-warning">Mode simulasi · tanpa uang sungguhan</span>
                    <?php endif; ?>
                </div>

                <?php if ($booking['status_detail']): ?>
                    <p style="margin-top:12px;"><?= clean($booking['status_detail']) ?></p>
                <?php endif; ?>
            </div>

            <div class="result-summary">
                <div class="result-summary-head">
                    <span>Kode <?= clean($booking['booking_code']) ?></span>
                    <span><?= formatTanggalId($booking['booking_date']) ?></span>
                </div>
                <div class="result-summary-body">
                    <div class="summary-list">
                        <div class="summary-row"><span>Lapangan</span><span><?= clean($booking['court_name']) ?></span></div>
                        <div class="summary-row"><span>Lokasi</span><span><?= clean($booking['location']) ?></span></div>
                        <div class="summary-row"><span>Jam</span><span><?= date('H:i', strtotime($booking['start_time'])) ?> – <?= date('H:i', strtotime($booking['end_time'])) ?></span></div>
                        <div class="summary-row"><span>Total</span><span><?= formatRupiah($booking['total_after_discount'] !== null ? $booking['total_after_discount'] : $booking['total_price']) ?></span></div>

                        <?php if ($providerLabel !== ''): ?>
                            <div class="summary-row"><span>Provider</span><span><?= clean($providerLabel) ?></span></div>
                        <?php endif; ?>

                        <?php if ($proofStatus === 'rejected' && !empty($booking['rejection_reason'])): ?>
                            <div class="summary-row">
                                <span>Alasan penolakan</span>
                                <span class="discount-text"><?= clean($booking['rejection_reason']) ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($paymentStatus === 'paid' && $booking['paid_at']): ?>
                            <div class="summary-row"><span>Dibayar pada</span><span><?= formatTanggalId($booking['paid_at'], 'panjang') ?> · <?= date('H:i', strtotime($booking['paid_at'])) ?></span></div>
                        <?php endif; ?>

                        <?php if (in_array($paymentStatus, ['pending', 'unpaid']) && $booking['expire_time']): ?>
                            <div class="summary-row"><span>Batas waktu</span><span><?= formatTanggalId($booking['expire_time'], 'panjang') ?> · <?= date('H:i', strtotime($booking['expire_time'])) ?></span></div>
                        <?php endif; ?>

                        <?php if ($paymentStatus === 'pending' && $booking['payment_code'] && !$isSimulasi): ?>
                            <div class="summary-row"><span>Metode</span><span><?= clean(strtoupper($booking['payment_type'] ?? 'Midtrans')) ?></span></div>
                            <?php if ($booking['bank']): ?>
                                <div class="summary-row"><span>Bank</span><span><?= clean(strtoupper($booking['bank'])) ?></span></div>
                            <?php endif; ?>
                            <div class="summary-row"><span>Kode / no. bayar</span><span><code><?= clean($booking['payment_code']) ?></code></span></div>
                        <?php endif; ?>
                    </div>

                    <?php if ($buktiUrl): ?>
                        <div class="summary-list" style="margin-top:16px;">
                            <div class="summary-row">
                                <span>Bukti pembayaran</span>
                                <span><?php if (!empty($booking['payment_proof_uploaded_at'])): ?><?= formatTanggalId($booking['payment_proof_uploaded_at'], 'panjang') ?> · <?= date('H:i', strtotime($booking['payment_proof_uploaded_at'])) ?><?php else: ?>Sudah diunggah<?php endif; ?></span>
                            </div>
                            <?php if ($paymentStatus === 'paid' && !empty($booking['verified_at'])): ?>
                                <div class="summary-row">
                                    <span>Diverifikasi admin</span>
                                    <span><?= formatTanggalId($booking['verified_at'], 'panjang') ?> · <?= date('H:i', strtotime($booking['verified_at'])) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <a href="<?= clean($buktiUrl) ?>" target="_blank" rel="noopener"
                           style="display:block; margin-top:12px; border:1px solid var(--line); border-radius:var(--radius-md); overflow:hidden; background:var(--paper-2);">
                            <img src="<?= clean($buktiUrl) ?>" alt="Bukti pembayaran booking <?= clean($booking['booking_code']) ?>"
                                 style="display:block; width:100%; max-height:360px; object-fit:contain; background:var(--white);">
                        </a>
                    <?php endif; ?>

                    <?php if ($paymentStatus === 'pending' && $booking['payment_code'] && !$isSimulasi): ?>
                        <p class="summary-note">Selesaikan pembayaran sesuai instruksi di halaman Midtrans, lalu tekan tombol di bawah untuk membuka kembali halaman pembayaran.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="result-actions">
                <?php if ($isSimulasi): ?>
                    <?php if (in_array($paymentStatus, ['unpaid', 'expired'], true) || ($paymentStatus === 'failed' && !$hasProof)): ?>
                        <a href="<?= clean($buktiUploadUrl) ?>" class="btn btn-primary">Kirim bukti pembayaran</a>
                    <?php elseif ($paymentStatus === 'failed' && $hasProof): ?>
                        <a href="<?= clean($buktiUploadUrl) ?>" class="btn btn-primary">Unggah ulang bukti</a>
                    <?php endif; ?>

                    <?php if (in_array($paymentStatus, ['pending', 'paid'], true)): ?>
                        <a href="/arenaku/detail_booking.php?id=<?= (int) $bookingId ?>" class="btn btn-primary">Lihat detail booking</a>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($hasProof && $proofStatus === 'pending'): ?>
                        <a href="/arenaku/detail_booking.php?id=<?= (int) $bookingId ?>" class="btn btn-primary">Lihat detail booking</a>
                        <a href="/arenaku/konfirmasi_pembayaran.php?booking_id=<?= (int) $bookingId ?>" class="btn btn-outline">Lihat konfirmasi pembayaran</a>
                    <?php elseif (in_array($paymentStatus, ['pending', 'unpaid'])): ?>
                        <a href="/arenaku/pembayaran.php?booking_id=<?= (int) $bookingId ?>" class="btn btn-primary">Buka halaman pembayaran</a>
                        <a href="/arenaku/konfirmasi_pembayaran.php?booking_id=<?= (int) $bookingId ?>" class="btn btn-outline">Kirim bukti pembayaran</a>
                    <?php elseif (in_array($paymentStatus, ['failed', 'expired'])): ?>
                        <a href="/arenaku/pembayaran.php?booking_id=<?= (int) $bookingId ?>" class="btn btn-primary">Bayar ulang</a>
                        <?php if ($hasProof): ?>
                            <a href="/arenaku/konfirmasi_pembayaran.php?booking_id=<?= (int) $bookingId ?>" class="btn btn-outline">Unggah ulang bukti</a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($paymentStatus === 'paid'): ?>
                        <a href="/arenaku/detail_booking.php?id=<?= (int) $bookingId ?>" class="btn btn-primary">Lihat detail booking</a>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="result-actions-row">
                    <?php if ($isSimulasi && !in_array($paymentStatus, ['pending', 'paid'], true)): ?>
                        <a href="/arenaku/detail_booking.php?id=<?= (int) $bookingId ?>" class="btn btn-outline">Lihat detail booking</a>
                    <?php endif; ?>
                    <a href="/arenaku/booking_saya.php" class="btn btn-outline">Booking saya</a>
                    <a href="/arenaku/lapangan.php" class="btn btn-outline">Cari lapangan</a>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>
