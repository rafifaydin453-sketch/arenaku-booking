<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';   // WAJIB LOGIN
require_once 'includes/pembatalan_helper.php';
require_once 'includes/simulasi_helper.php';

$pageTitle = 'Booking Saya';

pembatalanExpireDue($koneksi);

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'semua';
$tabValid = ['semua', 'akan_datang', 'selesai', 'dibatalkan'];
if (!in_array($tab, $tabValid)) {
    $tab = 'semua';
}

$where  = ["bookings.user_id = ?"];
$params = [$_SESSION['user_id']];

if ($tab === 'akan_datang') {
    $where[] = "bookings.booking_status IN ('pending_payment', 'confirmed')";
    $where[] = "bookings.booking_date >= CURDATE()";
} elseif ($tab === 'selesai') {
    $where[] = "bookings.booking_status = 'completed'";
} elseif ($tab === 'dibatalkan') {
    $where[] = "bookings.booking_status = 'cancelled'";
}

$whereSql = implode(' AND ', $where);

$stmt = $koneksi->prepare(
    "SELECT bookings.*, courts.name AS court_name, courts.location,
            payments.payment_status, payments.payment_method, payments.payment_type,
            payments.payment_proof, payments.is_simulated, payments.proof_status
     FROM bookings
     JOIN courts ON bookings.court_id = courts.id
     JOIN payments ON payments.booking_id = bookings.id
     WHERE $whereSql
     ORDER BY bookings.booking_date DESC, bookings.start_time DESC"
);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

$cancellations = pembatalanAmbilTerakhirBanyak($koneksi, array_column($bookings, 'id'));

$ratedBookingIds = [];
$stmtRated = $koneksi->prepare("SELECT booking_id FROM ratings WHERE user_id = ?");
$stmtRated->execute([$_SESSION['user_id']]);
foreach ($stmtRated->fetchAll() as $rRow) {
    $ratedBookingIds[(int) $rRow['booking_id']] = true;
}

function statusBadge($status)
{
    $map = [
        'pending_payment' => ['label' => 'Menunggu Pembayaran', 'class' => 'badge-warning'],
        'confirmed'       => ['label' => 'Akan Datang', 'class' => 'badge-success'],
        'completed'       => ['label' => 'Selesai', 'class' => 'badge-neutral'],
        'cancelled'       => ['label' => 'Dibatalkan', 'class' => 'badge-danger'],
    ];
    return $map[$status] ?? ['label' => $status, 'class' => 'badge-neutral'];
}

function paymentBadge($status, $isSimulated = false, $paymentProof = null, $proofStatus = 'none')
{
    $simLabel = simulasiStatusPembayaran([
        'payment_status' => $status,
        'is_simulated'   => $isSimulated,
        'payment_proof'  => $paymentProof,
        'proof_status'   => $proofStatus,
    ]);
    if ($simLabel !== null) {
        return ['label' => $simLabel[0], 'class' => $simLabel[1]];
    }

    list($label, $class) = labelStatusPembayaran($status);
    return ['label' => $label, 'class' => $class];
}

$tabs = [
    'semua'       => 'Semua',
    'akan_datang' => 'Akan Datang',
    'selesai'     => 'Selesai',
    'dibatalkan'  => 'Dibatalkan',
];

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<main id="main-content" class="section">
    <div class="container">
        <div class="page-head">
            <div>
                <span class="eyebrow">Akun saya</span>
                <h1 style="font-size:clamp(2rem,3.6vw,3rem); margin-top:12px;">Booking saya</h1>
                <p>Semua jadwal, pembayaran, dan ulasan lapanganmu tersimpan di sini.</p>
            </div>
            <a href="/arenaku/lapangan.php" class="btn btn-primary"><?= svgIcon('plus', 'icon') ?> Pesan lapangan</a>
        </div>

        <div class="segmented" aria-label="Filter status booking">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="?tab=<?= $key ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <div class="booking-list">
            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <span class="empty-state-icon"><?= svgIcon('calendar', 'icon') ?></span>
                    <h3>Belum ada booking di kategori ini</h3>
                    <p>Mulai dengan memilih lapangan dan slot yang cocok untuk jadwalmu.</p>
                    <a href="/arenaku/lapangan.php" class="btn btn-primary">Cari lapangan</a>
                </div>
            <?php endif; ?>

                    <?php foreach ($bookings as $b): ?>
                        <?php
                            $badge = statusBadge($b['booking_status']);
                            $pBadge = paymentBadge($b['payment_status'], !empty($b['is_simulated']), $b['payment_proof'], $b['proof_status'] ?? 'none');
                            $totalBayar = $b['total_after_discount'] !== null ? $b['total_after_discount'] : $b['total_price'];
                            $cancel = $cancellations[(int) $b['id']] ?? null;
                            $cancelBadge = $cancel ? pembatalanLabelStatus($cancel['decision_status']) : null;

                            $bayarUrl = '/arenaku/pembayaran.php?booking_id=' . (int) $b['id'];
                            $bayarLabel = $b['payment_status'] === 'unpaid' ? 'Bayar' : 'Bayar ulang';
                            if ($b['payment_status'] === 'failed' && !empty($b['payment_proof'])) {
                                // Bukti ditolak admin -> unggah ulang lewat halaman
                                // konfirmasi pembayaran.
                                $bayarUrl = '/arenaku/konfirmasi_pembayaran.php?booking_id=' . (int) $b['id'];
                                $bayarLabel = 'Kirim ulang bukti';
                            }
                        ?>
                <article class="booking-card">
                    <div class="booking-date">
                        <strong><?= date('d', strtotime($b['booking_date'])) ?></strong>
                        <span><?= date('M', strtotime($b['booking_date'])) ?></span>
                        <small><?= date('Y', strtotime($b['booking_date'])) ?></small>
                    </div>

                    <div class="booking-main">
                        <h3><a href="/arenaku/detail_booking.php?id=<?= (int) $b['id'] ?>"><?= clean($b['court_name']) ?></a></h3>
                        <div class="booking-lines">
                            <span class="booking-line"><?= svgIcon('calendar', 'icon') ?> <?= formatHariId($b['booking_date']) ?>, <?= formatTanggalId($b['booking_date']) ?></span>
                            <span class="booking-line"><?= svgIcon('clock', 'icon') ?> <?= date('H:i', strtotime($b['start_time'])) ?>–<?= date('H:i', strtotime($b['end_time'])) ?> · <?= (int) $b['duration'] ?> jam</span>
                            <span class="booking-line"><?= svgIcon('pin', 'icon') ?> <?= clean($b['location']) ?></span>
                        </div>
                        <div class="booking-tags">
                            <?php if ($cancel): ?>
                                <span class="badge <?= $cancelBadge[1] ?>">
                                    <?= clean($cancelBadge[0]) ?><?= $cancel['decision_status'] === 'pending' ? ' · sisa ' . clean(pembatalanFormatSisa($cancel['sisa_detik'])) : '' ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($b['promo_code'] && (float) $b['discount_amount'] > 0): ?>
                                <span class="badge badge-success"><?= clean($b['promo_code']) ?> · hemat <?= formatRupiah($b['discount_amount']) ?></span>
                            <?php endif; ?>
                            <?php if ($b['payment_status'] !== 'paid' && $b['booking_status'] === 'pending_payment'): ?>
                                <span class="badge <?= $pBadge['class'] ?>"><?= $pBadge['label'] ?></span>
                            <?php endif; ?>
                            <?php if (!empty($b['is_simulated']) && $b['payment_status'] === 'paid'): ?>
                                <span class="badge badge-warning">Simulasi</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="booking-side">
                        <div class="booking-side-top">
                            <span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
                            <span class="booking-total"><?= formatRupiah($totalBayar) ?></span>
                        </div>
                        <div class="booking-actions">
                            <a href="/arenaku/detail_booking.php?id=<?= (int) $b['id'] ?>" class="btn btn-outline btn-sm">Detail</a>
                            <?php if ($b['booking_status'] === 'pending_payment'): ?>
                                <?php if (in_array($b['payment_status'], ['unpaid', 'failed', 'expired'], true)): ?>
                                    <a href="<?= clean($bayarUrl) ?>" class="btn btn-primary btn-sm">
                                        <?= $bayarLabel ?>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($b['booking_status'] === 'completed'): ?>
                                <?php if (isset($ratedBookingIds[(int) $b['id']])): ?>
                                    <span class="badge badge-success"><?= svgIcon('star-filled', 'icon') ?> Sudah dinilai</span>
                                <?php else: ?>
                                    <a href="/arenaku/beri_rating.php?booking_id=<?= (int) $b['id'] ?>" class="btn btn-accent btn-sm"><?= svgIcon('star', 'icon') ?> Beri rating</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>
