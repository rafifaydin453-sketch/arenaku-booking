<?php
/**
 * ADMIN - KELOLA BOOKING
 * List booking, filter status/tanggal/pencarian, dan ubah status booking.
 */
session_start();
require_once '../config/koneksi.php';
require_once '../includes/functions.php';
require_once '../includes/admin_auth.php';

$pageTitle = 'Kelola Booking';
$adminActive = 'booking';

// =====================================================================
// PROSES AKSI
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = isset($_POST['aksi']) ? $_POST['aksi'] : '';

    if ($aksi === 'ubah_status') {
        $id     = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        $status = isset($_POST['status']) ? $_POST['status'] : '';

        $valid = ['pending_payment', 'confirmed', 'completed', 'cancelled'];
        if (!in_array($status, $valid, true)) {
            setFlashError('Status booking tidak valid.');
        } elseif ($id > 0) {
            $stmt = $koneksi->prepare("UPDATE bookings SET booking_status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            setFlashSuccess('Status booking #' . $id . ' berhasil diubah.');
        }
        redirect('kelola_booking.php' . (isset($_POST['redirect_query']) ? '?' . $_POST['redirect_query'] : ''));
    }
}

// =====================================================================
// DATA + FILTER
// =====================================================================
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterTanggal= isset($_GET['tanggal']) ? $_GET['tanggal'] : '';
$cari         = isset($_GET['cari']) ? trim($_GET['cari']) : '';

$perHalaman = 15;
$halaman = isset($_GET['hal']) ? max(1, (int) $_GET['hal']) : 1;
$offset = ($halaman - 1) * $perHalaman;

$where = [];
$params = [];

if (in_array($filterStatus, ['pending_payment', 'confirmed', 'completed', 'cancelled'], true)) {
    $where[] = "bookings.booking_status = ?";
    $params[] = $filterStatus;
}
if ($filterTanggal !== '') {
    $where[] = "bookings.booking_date = ?";
    $params[] = $filterTanggal;
}
if ($cari !== '') {
    $where[] = "(bookings.booking_code LIKE ? OR users.name LIKE ? OR courts.name LIKE ?)";
    $like = '%' . $cari . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sqlBase = "FROM bookings
            JOIN users ON bookings.user_id = users.id
            JOIN courts ON bookings.court_id = courts.id
            LEFT JOIN payments ON payments.booking_id = bookings.id
            $whereSql";

$stmtCount = $koneksi->prepare("SELECT COUNT(*) $sqlBase");
$stmtCount->execute($params);
$totalData = $stmtCount->fetchColumn();
$totalHalaman = max(1, (int) ceil($totalData / $perHalaman));

$stmtList = $koneksi->prepare(
    "SELECT bookings.*, users.name AS user_name, courts.name AS court_name,
            payments.payment_status
     $sqlBase
     ORDER BY bookings.created_at DESC
     LIMIT $perHalaman OFFSET $offset"
);
$stmtList->execute($params);
$bookings = $stmtList->fetchAll();

// Query string untuk pertahankan filter saat ganti status
$queryString = http_build_query(array_filter([
    'status'  => $filterStatus,
    'tanggal' => $filterTanggal,
    'cari'    => $cari,
    'hal'     => $halaman,
]));

require_once '../includes/admin_header.php';
?>

<div>
    <h1 style="margin:0;">Kelola Booking</h1>
    <p style="color:var(--color-text-light);">Total <?= number_format($totalData) ?> booking (sesuai filter)</p>
</div>

<!-- ============ FILTER ============ -->
<form method="GET" action="kelola_booking.php" class="admin-toolbar" style="margin-top:24px;">
    <div class="field form-group" style="margin:0;">
        <label>Status</label>
        <select name="status">
            <option value="">Semua</option>
            <option value="pending_payment" <?= $filterStatus === 'pending_payment' ? 'selected' : '' ?>>Menunggu Pembayaran</option>
            <option value="confirmed" <?= $filterStatus === 'confirmed' ? 'selected' : '' ?>>Terkonfirmasi</option>
            <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Selesai</option>
            <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
        </select>
    </div>
    <div class="field form-group" style="margin:0;">
        <label>Tanggal</label>
        <input type="date" name="tanggal" value="<?= clean($filterTanggal) ?>">
    </div>
    <div class="field form-group" style="margin:0;">
        <label>Cari</label>
        <input type="text" name="cari" placeholder="Kode / user / lapangan" value="<?= clean($cari) ?>">
    </div>
    <button type="submit" class="btn btn-outline">Terapkan</button>
    <a href="kelola_booking.php" class="btn btn-outline">Reset</a>
</form>

<!-- ============ TABEL ============ -->
<div class="card">
    <div class="card-body" style="overflow-x:auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>User</th>
                    <th>Lapangan</th>
                    <th>Tanggal</th>
                    <th>Jam</th>
                    <th>Total</th>
                    <th>Pembayaran</th>
                    <th>Status</th>
                    <th>Ubah Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bookings)): ?>
                    <tr><td colspan="9" style="text-align:center; color:var(--color-text-light);">Tidak ada booking.</td></tr>
                <?php else: ?>
                    <?php foreach ($bookings as $b): ?>
                        <?php $lb = labelStatusBooking($b['booking_status']); ?>
                        <tr>
                            <td><strong><?= clean($b['booking_code']) ?></strong></td>
                            <td><?= clean($b['user_name']) ?></td>
                            <td><?= clean($b['court_name']) ?></td>
                            <td><?= date('d/m/Y', strtotime($b['booking_date'])) ?></td>
                            <td><?= substr($b['start_time'], 0, 5) ?>-<?= substr($b['end_time'], 0, 5) ?></td>
                            <td><?= formatRupiah($b['total_price']) ?></td>
                            <td>
                                <?php $lp = labelStatusPembayaran($b['payment_status'] ?: 'unpaid'); ?>
                                <span class="badge <?= $lp[1] ?>"><?= clean($lp[0]) ?></span>
                            </td>
                            <td><span class="badge <?= $lb[1] ?>"><?= clean($lb[0]) ?></span></td>
                            <td>
                                <form method="POST" action="kelola_booking.php" style="display:flex; gap:6px; align-items:center;">
                                    <input type="hidden" name="aksi" value="ubah_status">
                                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                    <input type="hidden" name="redirect_query" value="<?= clean($queryString) ?>">
                                    <select name="status" style="padding:6px; border:1px solid var(--color-border); border-radius:6px; font-size:13px;">
                                        <option value="pending_payment" <?= $b['booking_status'] === 'pending_payment' ? 'selected' : '' ?>>Menunggu Bayar</option>
                                        <option value="confirmed" <?= $b['booking_status'] === 'confirmed' ? 'selected' : '' ?>>Terkonfirmasi</option>
                                        <option value="completed" <?= $b['booking_status'] === 'completed' ? 'selected' : '' ?>>Selesai</option>
                                        <option value="cancelled" <?= $b['booking_status'] === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
                                    </select>
                                    <button type="submit" class="btn btn-outline btn-sm">Simpan</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalHalaman > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalHalaman; $i++): ?>
                    <?php $q = http_build_query(array_filter(['status'=>$filterStatus,'tanggal'=>$filterTanggal,'cari'=>$cari,'hal'=>$i])); ?>
                    <?php if ($i === $halaman): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= $q ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
