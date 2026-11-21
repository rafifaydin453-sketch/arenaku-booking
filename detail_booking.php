<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';   // WAJIB LOGIN
require_once 'includes/pembatalan_helper.php';
require_once 'includes/simulasi_helper.php';

$pageTitle = 'Detail Booking';

$bookingId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $koneksi->prepare(
    "SELECT bookings.*, courts.name AS court_name, courts.location, courts.image,
            payments.id AS payment_id, payments.payment_method, payments.payment_status, payments.paid_at,
            payments.payment_code, payments.bank, payments.payment_type,
            payments.status_detail, payments.midtrans_order_id, payments.is_simulated,
            payments.payment_proof, payments.payment_proof_uploaded_at, payments.verified_at,
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

pembatalanExpireDue($koneksi);
$cancellation = pembatalanAmbilTerakhir($koneksi, $bookingId);
$cancelLabel = $cancellation ? pembatalanLabelStatus($cancellation['decision_status']) : null;
$refundLabel = $cancellation ? pembatalanLabelRefund($cancellation['refund_status']) : null;

$ratingBooking = getRatingBooking($koneksi, $bookingId);

list($statusLabel, $statusClass) = labelStatusBooking($booking['booking_status']);
list($paymentLabel, $paymentClass) = labelStatusPembayaran($booking['payment_status']);

$simLabel = simulasiStatusPembayaran($booking);
if ($simLabel !== null) {
    list($paymentLabel, $paymentClass) = $simLabel;
}

$buktiUrl = !empty($booking['payment_proof']) ? simulasiBuktiUrl($booking['payment_proof']) : '';
$proofStatus = !empty($booking['proof_status']) ? $booking['proof_status'] : 'none';
list($verifLabel, $verifClass) = labelStatusVerifikasi($proofStatus);
$providerLabel = simulasiEwalletProviderLabel($booking);
// Unggah/unggah-ulang bukti selalu lewat halaman konfirmasi pembayaran.
// (Halaman simulasi diputus dari alur aktif; file tetap tersimpan sebagai cadangan.)
$buktiUlangUrl = '/arenaku/konfirmasi_pembayaran.php?booking_id=' . (int) $booking['id'];

$metodeLabel = [
    'qris'          => 'QRIS',
    'transfer_bank' => 'Virtual Account',
    'e_wallet'      => 'E-Wallet',
];

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<main id="main-content" class="section">
    <div class="container" style="max-width:820px;">
        <a href="/arenaku/booking_saya.php" class="back-link"><?= svgIcon('arrow-left', 'icon') ?> Kembali ke booking saya</a>

        <div class="summary-card">
            <div class="summary-head">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                    <div>
                        <span class="eyebrow">Invoice booking</span>
                        <h1 style="font-size:clamp(1.7rem,3vw,2.3rem); margin-top:8px;"><?= clean($booking['court_name']) ?></h1>
                        <p style="margin-top:6px;">Kode <strong><?= clean($booking['booking_code']) ?></strong></p>
                    </div>
                    <div class="booking-tags">
                        <span class="badge <?= $statusClass ?>"><?= clean($statusLabel) ?></span>
                        <span class="badge <?= $paymentClass ?>"><?= clean($paymentLabel) ?></span>
                        <?php if (!empty($booking['is_simulated'])): ?>
                            <span class="badge badge-warning">Simulasi</span>
                        <?php endif; ?>
                        <?php if ($cancellation): ?>
                            <span class="badge <?= $cancelLabel[1] ?>"><?= clean($cancelLabel[0]) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="summary-body">
                <div class="rating-court">
                    <span class="thumb"><?= svgIcon('field', 'icon') ?></span>
                    <div>
                        <strong><?= clean($booking['court_name']) ?></strong>
                        <p><?= clean($booking['location']) ?></p>
                    </div>
                </div>

                <p class="eyebrow" style="margin:22px 0 2px;">Jadwal</p>
                <div class="summary-list">
                    <div class="summary-row"><span>Tanggal</span><span><?= formatHariId($booking['booking_date']) ?>, <?= formatTanggalId($booking['booking_date'], 'panjang') ?></span></div>
                    <div class="summary-row"><span>Jam</span><span><?= date('H:i', strtotime($booking['start_time'])) ?> – <?= date('H:i', strtotime($booking['end_time'])) ?></span></div>
                    <div class="summary-row"><span>Durasi</span><span><?= (int) $booking['duration'] ?> jam</span></div>
                </div>

                <p class="eyebrow" style="margin:22px 0 2px;">Rincian biaya</p>
                <div class="summary-list">
                    <div class="summary-row"><span><?= (int) $booking['duration'] ?> jam × <?= formatRupiah($booking['price_per_hour']) ?></span><span><?= formatRupiah($booking['total_price']) ?></span></div>
                    <?php if ((float) $booking['discount_amount'] > 0): ?>
                        <div class="summary-row">
                            <span>Diskon<?= $booking['promo_code'] ? ' · ' . clean($booking['promo_code']) : '' ?></span>
                            <span class="discount-text">− <?= formatRupiah($booking['discount_amount']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="summary-total">
                    <span>Total</span>
                    <strong><?= formatRupiah($booking['total_after_discount'] !== null ? $booking['total_after_discount'] : $booking['total_price']) ?></strong>
                </div>

                <p class="eyebrow" style="margin:24px 0 2px;">Pembayaran</p>
                <div class="summary-list">
                    <div class="summary-row">
                        <span>Metode</span>
                        <span><?= $metodeLabel[$booking['payment_method']] ?? '—' ?></span>
                    </div>
                    <?php if ($providerLabel !== ''): ?>
                        <div class="summary-row">
                            <span>Provider</span>
                            <span><?= clean($providerLabel) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="summary-row">
                        <span>Status</span>
                        <span id="paymentStatusLabel"><?= clean($paymentLabel) ?></span>
                    </div>
                    <?php if ($booking['payment_code']): ?>
                        <div class="summary-row">
                            <span>Kode / no. bayar</span>
                            <span><code id="paymentCode"><?= clean($booking['payment_code']) ?></code><?= $booking['bank'] ? ' · ' . clean(strtoupper($booking['bank'])) : '' ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($booking['paid_at']): ?>
                        <div class="summary-row"><span>Dibayar pada</span><span><?= formatTanggalId($booking['paid_at'], 'panjang') ?> · <?= date('H:i', strtotime($booking['paid_at'])) ?></span></div>
                    <?php endif; ?>
                </div>

                <?php if ($buktiUrl): ?>
                    <p class="eyebrow" style="margin:24px 0 2px;">Bukti pembayaran</p>
                    <div class="summary-list">
                        <div class="summary-row">
                            <span>Status verifikasi</span>
                            <span><span class="badge <?= $verifClass ?>"><?= clean($verifLabel) ?></span></span>
                        </div>
                        <?php if (!empty($booking['payment_proof_uploaded_at'])): ?>
                            <div class="summary-row">
                                <span>Dikirim pada</span>
                                <span><?= formatTanggalId($booking['payment_proof_uploaded_at'], 'panjang') ?> · <?= date('H:i', strtotime($booking['payment_proof_uploaded_at'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($booking['payment_status'] === 'paid' && !empty($booking['verified_at'])): ?>
                            <div class="summary-row">
                                <span>Diverifikasi pada</span>
                                <span><?= formatTanggalId($booking['verified_at'], 'panjang') ?> · <?= date('H:i', strtotime($booking['verified_at'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($proofStatus === 'rejected' && !empty($booking['rejection_reason'])): ?>
                            <div class="summary-row">
                                <span>Alasan penolakan</span>
                                <span class="discount-text"><?= clean($booking['rejection_reason']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin:12px auto 0; max-width:440px; border:1px solid var(--line); border-radius:var(--radius-md); overflow:hidden; background:var(--paper-2);">
                        <a href="<?= clean($buktiUrl) ?>" target="_blank" rel="noopener" style="display:block;">
                            <img src="<?= clean($buktiUrl) ?>" alt="Bukti pembayaran booking <?= clean($booking['booking_code']) ?>"
                                 style="display:block; width:100%; height:auto; max-height:360px; object-fit:contain; background:var(--white);">
                        </a>
                    </div>

                    <?php if ($proofStatus === 'pending'): ?>
                        <div class="alert alert-info" style="margin-top:12px;">
                            Bukti sudah dikirim dan sedang diperiksa admin. Booking akan dikonfirmasi setelah bukti diterima.
                        </div>
                    <?php elseif ($proofStatus === 'rejected'): ?>
                        <div class="alert alert-danger" style="margin-top:12px;">
                            <?php if (!empty($booking['rejection_reason'])): ?>
                                <p><strong>Alasan admin:</strong> <?= clean($booking['rejection_reason']) ?></p>
                            <?php endif; ?>
                            Silakan periksa kembali nominal/tujuan pembayaran lalu unggah bukti baru.
                            <a href="<?= clean($buktiUlangUrl) ?>" class="btn btn-danger btn-sm" style="margin-top:8px;">Kirim ulang bukti</a>
                        </div>
                    <?php elseif ($booking['payment_status'] === 'paid'): ?>
                        <div class="alert alert-success" style="margin-top:12px;">
                            Bukti pembayaran diterima admin. Booking sudah dikonfirmasi.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div id="cekStatusNotif" class="alert alert-info" style="display:none; margin-top:16px;"></div>

                <?php if ($cancellation): ?>
                    <p class="eyebrow" style="margin:24px 0 2px;">Pengajuan pembatalan</p>
                    <div class="summary-list">
                        <div class="summary-row">
                            <span>Status pengajuan</span>
                            <span><span class="badge <?= $cancelLabel[1] ?>"><?= clean($cancelLabel[0]) ?></span></span>
                        </div>
                        <div class="summary-row"><span>Alasan</span><span><?= clean($cancellation['reason']) ?></span></div>
                        <div class="summary-row">
                            <span>Diajukan pada</span>
                            <span><?= formatTanggalId($cancellation['requested_at'], 'panjang') ?> · <?= date('H:i', strtotime($cancellation['requested_at'])) ?></span>
                        </div>
                        <?php if ($cancellation['decision_status'] === 'pending'): ?>
                            <div class="summary-row">
                                <span>Batas keputusan</span>
                                <span><?= formatTanggalId($cancellation['deadline_at'], 'panjang') ?> · <?= date('H:i', strtotime($cancellation['deadline_at'])) ?></span>
                            </div>
                            <div class="summary-row"><span>Sisa waktu</span><span><?= clean(pembatalanFormatSisa($cancellation['sisa_detik'])) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($cancellation['decided_at'])): ?>
                            <div class="summary-row">
                                <span>Diputuskan pada</span>
                                <span><?= formatTanggalId($cancellation['decided_at'], 'panjang') ?> · <?= date('H:i', strtotime($cancellation['decided_at'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($cancellation['decided_by_name'])): ?>
                            <div class="summary-row"><span>Diputuskan oleh</span><span><?= clean($cancellation['decided_by_name']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($cancellation['rejection_reason'])): ?>
                            <div class="summary-row"><span>Alasan penolakan</span><span><?= clean($cancellation['rejection_reason']) ?></span></div>
                        <?php endif; ?>
                        <?php if ($cancellation['decision_status'] === 'approved'): ?>
                            <div class="summary-row">
                                <span>Status refund</span>
                                <span><span class="badge <?= $refundLabel[1] ?>"><?= clean($refundLabel[0]) ?></span></span>
                            </div>
                            <?php if (!empty($cancellation['refund_note'])): ?>
                                <div class="summary-row"><span>Keterangan refund</span><span><?= clean($cancellation['refund_note']) ?></span></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($cancellation['decision_status'] === 'pending'): ?>
                        <div class="alert alert-warning" style="margin-top:16px;">
                            Booking belum dibatalkan. Admin punya waktu maksimal 24 jam untuk memutuskan.
                            Jika tidak ada keputusan, pengajuan otomatis kedaluwarsa dan booking tetap aktif.
                        </div>
                    <?php elseif ($cancellation['decision_status'] === 'expired'): ?>
                        <div class="alert alert-warning" style="margin-top:16px;">
                            Pengajuan kedaluwarsa karena tidak ada keputusan admin dalam 24 jam. Booking tetap aktif.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($booking['booking_status'] === 'completed'): ?>
                    <p class="eyebrow" style="margin:24px 0 8px;">Ulasan kamu</p>
                    <?php if ($ratingBooking): ?>
                        <div class="summary-list">
                            <div class="summary-row">
                                <span>Rating</span>
                                <span class="rating-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?= svgIcon($i <= (int) $ratingBooking['stars'] ? 'star-filled' : 'star', 'icon') ?>
                                    <?php endfor; ?>
                                </span>
                            </div>
                            <?php if (!empty($ratingBooking['review'])): ?>
                                <div class="summary-row"><span>Ulasan</span><span><?= clean($ratingBooking['review']) ?></span></div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-note">
                            <?= svgIcon('star', 'icon') ?>
                            <div>
                                <strong>Booking ini sudah selesai.</strong>
                                <p>Bagikan pengalamanmu supaya pemain lain bisa memilih lapangan dengan lebih yakin.</p>
                                <a href="/arenaku/beri_rating.php?booking_id=<?= (int) $booking['id'] ?>" class="btn btn-accent btn-sm" style="margin-top:10px;">Beri rating</a>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="checkout-actions" style="flex-wrap:wrap;">
                    <a href="/arenaku/booking_saya.php" class="btn btn-outline">Kembali</a>

                    <?php if ($booking['booking_status'] === 'pending_payment'): ?>
                        <?php if (in_array($booking['payment_status'], ['unpaid', 'failed', 'expired'], true)): ?>
                            <?php
                            $bayarUlangUrl = '/arenaku/pembayaran.php?booking_id=' . (int) $booking['id'];
                            $bayarUlangLabel = $booking['payment_status'] === 'unpaid' ? 'Bayar sekarang' : 'Bayar ulang';
                            if ($booking['payment_status'] === 'failed' && $buktiUrl) {
                                // Bukti sebelumnya ditolak admin -> unggah ulang lewat
                                // halaman konfirmasi pembayaran.
                                $bayarUlangUrl = $buktiUlangUrl;
                                $bayarUlangLabel = 'Kirim Ulang Bukti';
                            } elseif (empty($booking['is_simulated']) && !empty($booking['midtrans_order_id'])) {
                                // Alur Midtrans: popup sudah pernah dibuka, arahkan
                                // ke halaman konfirmasi untuk unggah bukti.
                                $bayarUlangUrl = $buktiUlangUrl;
                                $bayarUlangLabel = 'Kirim bukti pembayaran';
                            }
                            ?>
                            <a href="<?= clean($bayarUlangUrl) ?>" class="btn btn-primary">
                                <?= $bayarUlangLabel ?>
                            </a>
                            <?php if (empty($booking['is_simulated']) && !empty($booking['midtrans_order_id'])
                                && in_array($booking['payment_status'], ['failed', 'expired'], true)): ?>
                                <a href="/arenaku/pembayaran.php?booking_id=<?= (int) $booking['id'] ?>" class="btn btn-outline">Bayar ulang</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($booking['payment_status'] === 'pending' && $booking['midtrans_order_id']): ?>
                        <button type="button" id="btnCekStatus" class="btn btn-primary">Sudah bayar? Cek status</button>
                    <?php endif; ?>

                    <?php if (in_array($booking['booking_status'], ['pending_payment', 'confirmed'])): ?>
                        <?php if ($cancellation && $cancellation['decision_status'] === 'pending'): ?>
                            <span class="badge badge-warning">Menunggu persetujuan admin</span>
                        <?php else: ?>
                            <a href="/arenaku/batalkan_booking.php?id=<?= (int) $booking['id'] ?>" class="btn btn-danger">Ajukan pembatalan</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    var btn = document.getElementById('btnCekStatus');
    if (!btn) return;
    var notif = document.getElementById('cekStatusNotif');

    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.innerText = 'Mengecek...';
        notif.style.display = 'none';

        var fd = new FormData();
        fd.append('booking_id', <?= (int) $booking['id'] ?>);

        fetch('cek_status_pembayaran.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    window.location.reload();
                } else {
                    notif.innerText = data.error || 'Gagal mengecek status.';
                    notif.style.display = 'flex';
                    btn.disabled = false;
                    btn.innerText = 'Sudah bayar? Cek status';
                }
            })
            .catch(function () {
                notif.innerText = 'Terjadi kesalahan jaringan. Coba lagi.';
                notif.style.display = 'flex';
                btn.disabled = false;
                btn.innerText = 'Sudah bayar? Cek status';
            });
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
