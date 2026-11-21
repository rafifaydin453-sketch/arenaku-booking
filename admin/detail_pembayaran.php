<?php
session_start();
require_once '../config/koneksi.php';
require_once '../includes/functions.php';
require_once '../includes/admin_auth.php';
require_once '../includes/simulasi_helper.php';

$pageTitle = 'Detail Pembayaran';
$adminActive = 'pembayaran';

$paymentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($paymentId <= 0) {
    setFlashError('Pembayaran tidak ditemukan.');
    redirect('kelola_pembayaran.php');
}

$stmt = $koneksi->prepare(
    "SELECT payments.*, bookings.booking_code, bookings.booking_status,
            bookings.booking_date, bookings.start_time, bookings.end_time,
            bookings.duration, bookings.total_price, bookings.total_after_discount,
            bookings.discount_amount, bookings.promo_code,
            users.name AS user_name, users.email AS user_email, users.phone AS user_phone,
            courts.name AS court_name, courts.location AS court_location,
            verifier.name AS verifier_name
     FROM payments
     JOIN bookings ON payments.booking_id = bookings.id
     JOIN users ON bookings.user_id = users.id
     JOIN courts ON bookings.court_id = courts.id
     LEFT JOIN users verifier ON verifier.id = payments.verified_by
     WHERE payments.id = ?"
);
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    setFlashError('Pembayaran tidak ditemukan.');
    redirect('kelola_pembayaran.php');
}

$metodeMap = [
    'qris'          => 'QRIS',
    'transfer_bank' => 'Virtual Account',
    'e_wallet'      => 'E-Wallet',
];
$metodeLabel = $metodeMap[$payment['payment_method']] ?? ucwords(str_replace('_', ' ', (string) $payment['payment_method']));
if (!empty($payment['is_simulated']) && $metodeLabel !== '') {
    $metodeLabel .= ' Simulasi';
}

$providerChannel = simulasiEwalletChannelDariPayment($payment);
$providerLabel = $providerChannel !== '' ? simulasiEwalletLabel($providerChannel) : '';

$simLabel = simulasiStatusPembayaran($payment);
list($paymentLabel, $paymentClass) = $simLabel !== null ? $simLabel : labelStatusPembayaran($payment['payment_status']);
list($bookingLabel, $bookingClass) = labelStatusBooking($payment['booking_status']);

$buktiUrl = !empty($payment['payment_proof']) ? simulasiBuktiUrl($payment['payment_proof']) : '';
// Bukti menunggu keputusan admin (simulasi maupun alur Midtrans yang ditutup).
$proofStatus = !empty($payment['proof_status']) ? $payment['proof_status'] : 'none';
$proofPending = $proofStatus === 'pending';
list($verifLabel, $verifClass) = labelStatusVerifikasi($proofStatus);
$returnTo = 'detail_pembayaran.php?id=' . $paymentId;

require_once '../includes/admin_header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
    <div>
        <h1 style="margin:0;">Detail Pembayaran</h1>
        <p style="color:var(--color-text-light); margin-top:6px;">
            Booking <strong><?= clean($payment['booking_code']) ?></strong> &middot;
            <?= clean($payment['user_name']) ?> &middot;
            <?= clean($payment['court_name']) ?>
        </p>
    </div>
    <a href="kelola_pembayaran.php" class="btn btn-outline">Kembali</a>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:18px; margin-top:18px; align-items:start;">
    <div class="card">
        <div class="card-body">
            <h2 style="margin-top:0; font-size:1.1rem;">Bukti Pembayaran</h2>

            <?php if ($buktiUrl): ?>
                <a href="<?= clean($buktiUrl) ?>" target="_blank" rel="noopener"
                   style="display:block; margin-top:12px; border:1px solid var(--line); border-radius:var(--radius-md); overflow:hidden; background:var(--paper-2);">
                    <img src="<?= clean($buktiUrl) ?>" alt="Bukti pembayaran <?= clean($payment['booking_code']) ?>"
                         style="display:block; width:100%; max-height:560px; object-fit:contain; background:var(--white);">
                </a>
                <p style="font-size:12px; color:var(--color-text-light); margin-top:8px;">
                    Klik gambar untuk membuka ukuran penuh.
                    <?php if (!empty($payment['payment_proof_uploaded_at'])): ?>
                        Diunggah <?= date('d/m/Y H:i', strtotime($payment['payment_proof_uploaded_at'])) ?>.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <div class="empty-state" style="padding:32px 16px;">
                    <span class="empty-state-icon"><?= svgIcon('receipt', 'icon') ?></span>
                    <h3>Belum ada bukti pembayaran</h3>
                    <p>Pengguna belum mengunggah bukti untuk transaksi ini.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:grid; gap:18px;">
        <div class="card">
            <div class="card-body">
                <h2 style="margin-top:0; font-size:1.1rem;">Status Pembayaran</h2>
                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;">
                    <span class="badge <?= $paymentClass ?>"><?= clean($paymentLabel) ?></span>
                    <span class="badge <?= $bookingClass ?>"><?= clean($bookingLabel) ?></span>
                    <?php if ($buktiUrl): ?>
                        <span class="badge <?= $verifClass ?>">Bukti: <?= clean($verifLabel) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($payment['is_simulated'])): ?>
                        <span class="badge badge-warning">Simulasi</span>
                    <?php endif; ?>
                </div>

                <div class="info-block" style="margin-top:14px;">
                    <div class="info-row"><span>Metode</span><span><?= clean($metodeLabel) ?></span></div>
                    <?php if ($providerLabel !== ''): ?>
                        <div class="info-row">
                            <span>Provider</span>
                            <span style="display:flex; align-items:center; gap:8px; justify-content:flex-end;">
                                <img src="<?= clean(simulasiEwalletLogoUrl($providerChannel)) ?>" alt="Logo <?= clean($providerLabel) ?>"
                                     style="display:block; height:18px; width:auto;">
                                <?= clean($providerLabel) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="info-row"><span>Nominal</span><span><?= formatRupiah($payment['amount']) ?></span></div>
                    <div class="info-row"><span>Dibuat</span><span><?= date('d/m/Y H:i', strtotime($payment['created_at'])) ?></span></div>
                    <?php if (!empty($payment['payment_proof_uploaded_at'])): ?>
                        <div class="info-row"><span>Bukti diunggah</span><span><?= date('d/m/Y H:i', strtotime($payment['payment_proof_uploaded_at'])) ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($payment['verified_at'])): ?>
                        <div class="info-row"><span>Diverifikasi</span><span><?= date('d/m/Y H:i', strtotime($payment['verified_at'])) ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($payment['verifier_name'])): ?>
                        <div class="info-row"><span>Oleh admin</span><span><?= clean($payment['verifier_name']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($proofStatus === 'rejected' && !empty($payment['rejection_reason'])): ?>
                        <div class="info-row"><span>Alasan penolakan</span><span class="discount-text"><?= clean($payment['rejection_reason']) ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($payment['paid_at'])): ?>
                        <div class="info-row"><span>Dibayar pada</span><span><?= date('d/m/Y H:i', strtotime($payment['paid_at'])) ?></span></div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($payment['status_detail'])): ?>
                    <p style="font-size:13px; color:var(--color-text-light); margin-top:12px;"><?= clean($payment['status_detail']) ?></p>
                <?php endif; ?>

                <?php if ($proofPending): ?>
                    <div style="display:grid; gap:8px; margin-top:14px;">
                        <form method="POST" action="kelola_pembayaran.php" onsubmit="return confirm('Terima bukti pembayaran ini? Booking akan dikonfirmasi.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="aksi" value="terima_bukti">
                            <input type="hidden" name="payment_id" value="<?= $paymentId ?>">
                            <input type="hidden" name="return_to" value="<?= clean($returnTo) ?>">
                            <button type="submit" class="btn btn-primary btn-block">Terima Bukti</button>
                        </form>
                        <form method="POST" action="kelola_pembayaran.php" onsubmit="var a = this.alasan_penolakan.value.trim(); if (!a) { alert('Alasan penolakan wajib diisi.'); return false; } return confirm('Tolak bukti pembayaran ini? Pengguna dapat mengunggah ulang bukti.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="aksi" value="tolak_bukti">
                            <input type="hidden" name="payment_id" value="<?= $paymentId ?>">
                            <input type="hidden" name="return_to" value="<?= clean($returnTo) ?>">
                            <label style="font-size:13px; font-weight:600;">Alasan penolakan <span style="color:var(--danger, #b91c1c);">*</span></label>
                            <textarea name="alasan_penolakan" rows="3" maxlength="500" required
                                      placeholder="Contoh: Nominal bukti tidak sesuai. Maksimal 500 karakter."
                                      style="width:100%; resize:vertical;"></textarea>
                            <button type="submit" class="btn btn-danger btn-block">Tolak Bukti</button>
                        </form>
                    </div>
                <?php elseif ($buktiUrl && ($proofStatus === 'approved' || $proofStatus === 'rejected')): ?>
                    <div class="alert <?= $proofStatus === 'approved' ? 'alert-success' : 'alert-danger' ?>" style="margin-top:14px;">
                        <?php if ($proofStatus === 'approved'): ?>
                            Bukti sudah diterima dan pembayaran dianggap lunas.
                        <?php else: ?>
                            <?php if (!empty($payment['rejection_reason'])): ?>
                                <p><strong>Alasan admin:</strong> <?= clean($payment['rejection_reason']) ?></p>
                            <?php endif; ?>
                            Bukti sudah ditolak. Pengguna dapat mengunggah ulang bukti.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 style="margin-top:0; font-size:1.1rem;">Rincian Booking</h2>
                <div class="info-block" style="margin-top:12px;">
                    <div class="info-row"><span>Kode</span><span><?= clean($payment['booking_code']) ?></span></div>
                    <div class="info-row"><span>Pemesan</span><span><?= clean($payment['user_name']) ?></span></div>
                    <div class="info-row"><span>Email</span><span><?= clean($payment['user_email']) ?></span></div>
                    <?php if (!empty($payment['user_phone'])): ?>
                        <div class="info-row"><span>Telepon</span><span><?= clean($payment['user_phone']) ?></span></div>
                    <?php endif; ?>
                    <div class="info-row"><span>Lapangan</span><span><?= clean($payment['court_name']) ?></span></div>
                    <div class="info-row"><span>Lokasi</span><span><?= clean($payment['court_location']) ?></span></div>
                    <div class="info-row"><span>Tanggal</span><span><?= formatTanggalId($payment['booking_date'], 'panjang') ?></span></div>
                    <div class="info-row"><span>Jam</span><span><?= substr($payment['start_time'], 0, 5) ?>-<?= substr($payment['end_time'], 0, 5) ?> (<?= (int) $payment['duration'] ?> jam)</span></div>
                    <div class="info-row"><span>Harga</span><span><?= formatRupiah($payment['total_price']) ?></span></div>
                    <?php if ((float) $payment['discount_amount'] > 0): ?>
                        <div class="info-row">
                            <span>Diskon<?= $payment['promo_code'] ? ' · ' . clean($payment['promo_code']) : '' ?></span>
                            <span>- <?= formatRupiah($payment['discount_amount']) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="info-row"><span>Total bayar</span><span><?= formatRupiah($payment['total_after_discount'] !== null ? $payment['total_after_discount'] : $payment['total_price']) ?></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
