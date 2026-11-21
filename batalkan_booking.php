<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/pembatalan_helper.php';

$pageTitle = 'Ajukan Pembatalan';
$errors = [];

$bookingId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

$stmt = $koneksi->prepare(
    "SELECT bookings.*, courts.name AS court_name, courts.location,
            payments.payment_status, payments.id AS payment_id,
            payments.midtrans_order_id
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
$cek = pembatalanCekKelayakan($booking, $koneksi);
$adaPengajuanAktif = $cancellation && $cancellation['decision_status'] === 'pending';
$berhasilAjukan = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!csrfVerifikasi($csrf)) {
        $errors[] = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    }

    $alasan = isset($_POST['alasan']) ? trim($_POST['alasan']) : '';
    $alasanLain = isset($_POST['alasan_lainnya']) ? trim($_POST['alasan_lainnya']) : '';

    if ($alasan === '') {
        $errors[] = 'Silakan pilih alasan pembatalan.';
    } elseif ($alasan === 'Alasan lainnya') {
        if ($alasanLain === '') {
            $errors[] = 'Tuliskan alasan pembatalan pada kolom alasan lainnya.';
        } else {
            $alasan = $alasanLain;
        }
    }

    if ($adaPengajuanAktif) {
        $errors[] = 'Sudah ada pengajuan pembatalan yang sedang menunggu keputusan admin.';
    }

    if (!$cek['boleh']) {
        $errors[] = $cek['alasan'];
    }

    if (empty($errors)) {
        $hasil = pembatalanAjukan($koneksi, $bookingId, $_SESSION['user_id'], $alasan);
        if ($hasil['ok']) {
            $berhasilAjukan = $hasil;
            $cancellation = pembatalanAmbilTerakhir($koneksi, $bookingId);
            $adaPengajuanAktif = true;
        } else {
            $errors[] = $hasil['error'];
        }
    }
}

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<section class="section">
    <div class="container" style="max-width:560px;">

        <?php if ($berhasilAjukan): ?>
            <div class="card" style="text-align:center;">
                <div class="card-body">
                    <span class="badge badge-warning">Menunggu Persetujuan Admin</span>
                    <h3 style="margin-top:14px;">Pengajuan pembatalan terkirim</h3>
                    <p style="margin-top:8px;">
                        Booking belum dibatalkan. Admin punya waktu maksimal 24 jam untuk memutuskan.
                    </p>
                    <div class="summary-list" style="text-align:left; margin-top:18px;">
                        <div class="summary-row"><span>Diajukan pada</span><span><?= formatTanggalId($berhasilAjukan['requested_at'], 'panjang') ?> · <?= date('H:i', strtotime($berhasilAjukan['requested_at'])) ?></span></div>
                        <div class="summary-row"><span>Batas keputusan</span><span><?= formatTanggalId($berhasilAjukan['deadline_at'], 'panjang') ?> · <?= date('H:i', strtotime($berhasilAjukan['deadline_at'])) ?></span></div>
                        <div class="summary-row"><span>Sisa waktu</span><span><?= clean(pembatalanFormatSisa($berhasilAjukan['sisa_detik'])) ?></span></div>
                    </div>
                    <a href="booking_saya.php" class="btn btn-primary btn-block" style="margin-top:18px;">Lihat Booking Saya</a>
                    <a href="detail_booking.php?id=<?= (int) $bookingId ?>" class="btn btn-outline btn-block" style="margin-top:8px;">Detail booking</a>
                </div>
            </div>

        <?php elseif ($adaPengajuanAktif): ?>
            <a href="detail_booking.php?id=<?= (int) $bookingId ?>">&larr; Kembali ke Detail Booking</a>
            <div class="card" style="margin-top:16px;">
                <div class="card-body">
                    <span class="badge badge-warning">Menunggu Persetujuan Admin</span>
                    <h3 style="margin-top:12px;">Pengajuan sedang ditinjau</h3>
                    <p style="margin-top:8px;">Booking belum dibatalkan dan jadwal tetap tercatat sampai admin memberi keputusan.</p>

                    <div class="summary-list" style="margin-top:18px;">
                        <div class="summary-row"><span>Alasan</span><span><?= clean($cancellation['reason']) ?></span></div>
                        <div class="summary-row"><span>Diajukan pada</span><span><?= formatTanggalId($cancellation['requested_at'], 'panjang') ?> · <?= date('H:i', strtotime($cancellation['requested_at'])) ?></span></div>
                        <div class="summary-row"><span>Batas keputusan</span><span><?= formatTanggalId($cancellation['deadline_at'], 'panjang') ?> · <?= date('H:i', strtotime($cancellation['deadline_at'])) ?></span></div>
                        <div class="summary-row"><span>Sisa waktu</span><span><?= clean(pembatalanFormatSisa($cancellation['sisa_detik'])) ?></span></div>
                        <div class="summary-row"><span>Status pembayaran</span><span><?= clean(labelStatusPembayaran($booking['payment_status'])[0]) ?></span></div>
                    </div>

                    <div class="alert alert-info" style="margin-top:16px;">
                        Jika admin tidak memberi keputusan dalam 24 jam, pengajuan otomatis kedaluwarsa dan booking tetap aktif.
                    </div>
                </div>
            </div>

        <?php elseif ($cancellation && $cancellation['decision_status'] === 'approved'): ?>
            <a href="detail_booking.php?id=<?= (int) $bookingId ?>">&larr; Kembali ke Detail Booking</a>
            <?php $refund = pembatalanLabelRefund($cancellation['refund_status']); ?>
            <div class="card" style="margin-top:16px;">
                <div class="card-body">
                    <span class="badge badge-success">Pembatalan Disetujui</span>
                    <h3 style="margin-top:12px;">Booking sudah dibatalkan</h3>
                    <p style="margin-top:8px;">Pembatalan disetujui pada <?= formatTanggalId($cancellation['decided_at'], 'panjang') ?> · <?= date('H:i', strtotime($cancellation['decided_at'])) ?>.</p>

                    <div class="summary-list" style="margin-top:18px;">
                        <div class="summary-row"><span>Alasan pembatalan</span><span><?= clean($cancellation['reason']) ?></span></div>
                        <div class="summary-row"><span>Status refund</span><span><span class="badge <?= $refund[1] ?>"><?= clean($refund[0]) ?></span></span></div>
                        <?php if (!empty($cancellation['refund_note'])): ?>
                            <div class="summary-row"><span>Keterangan refund</span><span><?= clean($cancellation['refund_note']) ?></span></div>
                        <?php endif; ?>
                    </div>

                    <a href="booking_saya.php" class="btn btn-primary btn-block" style="margin-top:18px;">Lihat Booking Saya</a>
                </div>
            </div>

        <?php elseif (!$cek['boleh']): ?>
            <a href="detail_booking.php?id=<?= (int) $bookingId ?>">&larr; Kembali ke Detail Booking</a>
            <div class="alert alert-warning" style="margin-top:16px;">
                <?= clean($cek['alasan']) ?>
            </div>

        <?php else: ?>
            <a href="detail_booking.php?id=<?= (int) $bookingId ?>">&larr; Kembali</a>
            <h2 style="margin-top:12px;">Ajukan Pembatalan Booking</h2>

            <?php if ($cancellation && $cancellation['decision_status'] === 'rejected'): ?>
                <div class="alert alert-warning" style="margin-top:16px;">
                    Pengajuan sebelumnya <strong>ditolak</strong> pada <?= formatTanggalId($cancellation['decided_at'], 'panjang') ?>.
                    <?php if (!empty($cancellation['rejection_reason'])): ?>
                        Alasan admin: <?= clean($cancellation['rejection_reason']) ?>
                    <?php endif; ?>
                    Booking tetap aktif. Kamu dapat mengajukan pembatalan baru jika masih memenuhi syarat.
                </div>
            <?php elseif ($cancellation && $cancellation['decision_status'] === 'expired'): ?>
                <div class="alert alert-warning" style="margin-top:16px;">
                    Pengajuan sebelumnya <strong>kedaluwarsa</strong> karena tidak ada keputusan admin dalam 24 jam.
                    Booking tetap aktif. Kamu dapat mengajukan pembatalan baru jika masih memenuhi syarat.
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-warning" style="margin-top:16px;">
                    <?php foreach ($errors as $err): ?>
                        <p><?= clean($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="alert alert-info" style="margin-top:16px;">
                Pembatalan tidak langsung diproses. Pengajuan akan ditinjau admin maksimal 24 jam.
                Booking tetap aktif dan pembayaran tidak berubah selama menunggu keputusan.
            </div>

            <div class="card card-soft" style="margin-top:16px;">
                <div class="card-body">
                    <strong><?= clean($booking['court_name']) ?></strong>
                    <p style="margin-top:4px;"><?= date('d F Y', strtotime($booking['booking_date'])) ?> &bull; <?= date('H:i', strtotime($booking['start_time'])) ?> - <?= date('H:i', strtotime($booking['end_time'])) ?></p>
                    <p><?= clean($booking['location']) ?></p>
                </div>
            </div>

            <form method="POST" action="batalkan_booking.php" style="margin-top:16px;">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $bookingId ?>">

                <div class="form-group">
                    <label class="form-label" for="alasan">Alasan Pembatalan</label>
                    <select name="alasan" id="alasan" class="form-select" required>
                        <option value="">Pilih alasan</option>
                        <option value="Perubahan jadwal">Perubahan jadwal</option>
                        <option value="Kondisi tidak memungkinkan">Kondisi tidak memungkinkan</option>
                        <option value="Venue tidak sesuai">Venue tidak sesuai</option>
                        <option value="Alasan lainnya">Alasan lainnya</option>
                    </select>
                </div>

                <div class="form-group" id="wrapAlasanLain" style="display:none;">
                    <label class="form-label" for="alasan_lainnya">Tuliskan alasan</label>
                    <textarea name="alasan_lainnya" id="alasan_lainnya" class="form-input" rows="3" maxlength="500" placeholder="Jelaskan alasan pembatalanmu"></textarea>
                </div>

                <?php if ($booking['payment_status'] === 'paid'): ?>
                    <div class="alert alert-success">
                        Pembayaran sudah lunas. Jika pembatalan disetujui, refund akan diproses melalui Midtrans dan statusnya ditampilkan terpisah.
                    </div>
                <?php endif; ?>

                <div style="display:flex; gap:12px; margin-top:16px;">
                    <a href="detail_booking.php?id=<?= (int) $bookingId ?>" class="btn btn-outline" style="flex:1;">Batal</a>
                    <button type="submit" class="btn btn-danger" style="flex:1;">Kirim Pengajuan</button>
                </div>
            </form>

            <script>
            (function () {
                var pilih = document.getElementById('alasan');
                var wrap = document.getElementById('wrapAlasanLain');
                var lain = document.getElementById('alasan_lainnya');
                if (!pilih || !wrap || !lain) return;
                pilih.addEventListener('change', function () {
                    var tampil = pilih.value === 'Alasan lainnya';
                    wrap.style.display = tampil ? 'block' : 'none';
                    lain.required = tampil;
                });
            })();
            </script>

        <?php endif; ?>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
