<?php
session_start();
require_once '../config/koneksi.php';
require_once '../includes/functions.php';
require_once '../includes/admin_auth.php';
require_once '../includes/pembatalan_helper.php';

$pageTitle = 'Pengajuan Pembatalan';
$adminActive = 'pembatalan';

pembatalanExpireDue($koneksi);

$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$cari = isset($_GET['cari']) ? trim($_GET['cari']) : '';
$halaman = isset($_GET['hal']) ? max(1, (int) $_GET['hal']) : 1;
$perHalaman = 15;
$offset = ($halaman - 1) * $perHalaman;

// =====================================================================
// PROSES AKSI ADMIN
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $redirectQuery = isset($_POST['redirect_query']) ? $_POST['redirect_query'] : '';
    $tujuan = 'kelola_pembatalan.php' . ($redirectQuery !== '' ? '?' . $redirectQuery : '');

    if (!csrfVerifikasi($csrf)) {
        setFlashError('Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.');
        redirect($tujuan);
    }

    $aksi = isset($_POST['aksi']) ? $_POST['aksi'] : '';
    $requestId = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;

    if ($aksi === 'setujui') {
        $hasil = pembatalanSetujui($koneksi, $requestId, $_SESSION['user_id']);

        if ($hasil['ok']) {
            $pesan = 'Pembatalan disetujui. Booking sudah berstatus dibatalkan.';

            if (!empty($hasil['paid'])) {
                if ($hasil['refund_state'] === 'success') {
                    $pesan .= ' Refund berhasil diproses.';
                } elseif ($hasil['refund_state'] === 'processing') {
                    $pesan .= ' ' . ($hasil['refund_message'] ?? 'Refund sedang diproses.');
                } else {
                    $pesan .= ' Refund belum berhasil: ' . ($hasil['refund_message'] ?? 'perlu tindakan admin.');
                }
            } else {
                $pesan .= ' Tidak ada refund karena pembayaran belum lunas.';
            }

            setFlashSuccess($pesan);
        } else {
            setFlashError($hasil['error']);
        }
        redirect($tujuan);
    }

    if ($aksi === 'tolak') {
        $alasan = isset($_POST['alasan_penolakan']) ? trim($_POST['alasan_penolakan']) : '';
        $hasil = pembatalanTolak($koneksi, $requestId, $_SESSION['user_id'], $alasan);

        if ($hasil['ok']) {
            setFlashSuccess('Pengajuan pembatalan ditolak. Booking tetap aktif sesuai status sebelumnya.');
        } else {
            setFlashError($hasil['error']);
        }
        redirect($tujuan);
    }

    if ($aksi === 'retry_refund') {
        $hasil = pembatalanProsesRefund($koneksi, $requestId);

        if (!empty($hasil['state']) && $hasil['state'] === 'success') {
            setFlashSuccess($hasil['message'] ?? 'Refund berhasil diproses.');
        } elseif (!empty($hasil['state']) && $hasil['state'] === 'processing') {
            setFlashSuccess($hasil['message'] ?? 'Permintaan refund sedang diproses Midtrans.');
        } else {
            setFlashError($hasil['error'] ?? 'Refund gagal diproses.');
        }
        redirect($tujuan);
    }

    setFlashError('Aksi tidak dikenali.');
    redirect($tujuan);
}

// =====================================================================
// DATA + FILTER
// =====================================================================
$where = [];
$params = [];

if (in_array($filterStatus, ['pending', 'approved', 'rejected', 'expired'], true)) {
    $where[] = 'cr.decision_status = ?';
    $params[] = $filterStatus;
}

if ($cari !== '') {
    $where[] = '(u.name LIKE ? OR courts.name LIKE ? OR bookings.booking_code LIKE ? OR cr.reason LIKE ?)';
    $like = '%' . $cari . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sqlBase = "FROM cancellation_requests cr
            JOIN bookings ON bookings.id = cr.booking_id
            JOIN users u ON u.id = cr.user_id
            JOIN courts ON courts.id = bookings.court_id
            LEFT JOIN payments ON payments.booking_id = bookings.id
            LEFT JOIN users admin ON admin.id = cr.decided_by
            $whereSql";

$stmtCount = $koneksi->prepare("SELECT COUNT(*) $sqlBase");
$stmtCount->execute($params);
$totalData = (int) $stmtCount->fetchColumn();
$totalHalaman = max(1, (int) ceil($totalData / $perHalaman));

$stmtList = $koneksi->prepare(
    "SELECT cr.*, bookings.booking_code, bookings.booking_date,
            bookings.start_time, bookings.end_time,
            bookings.total_price, bookings.total_after_discount,
            bookings.booking_status AS current_booking_status,
            u.name AS user_name, u.email AS user_email,
            courts.name AS court_name,
            payments.payment_status,
            payments.amount AS payment_amount, payments.midtrans_order_id,
            payments.is_simulated,
            admin.name AS decided_by_name,
            TIMESTAMPDIFF(SECOND, NOW(), cr.deadline_at) AS sisa_detik
     $sqlBase
     ORDER BY CASE WHEN cr.decision_status = 'pending' THEN 0 ELSE 1 END,
              cr.requested_at DESC
     LIMIT $perHalaman OFFSET $offset"
);
$stmtList->execute($params);
$requests = $stmtList->fetchAll();

$ringkasan = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'expired' => 0];
foreach ($koneksi->query(
    "SELECT decision_status, COUNT(*) AS jumlah
     FROM cancellation_requests GROUP BY decision_status"
) as $r) {
    $ringkasan[$r['decision_status']] = (int) $r['jumlah'];
}
$refundGagal = (int) $koneksi->query(
    "SELECT COUNT(*) FROM cancellation_requests
     WHERE decision_status = 'approved' AND refund_status = 'failed'"
)->fetchColumn();

$queryString = http_build_query(array_filter([
    'status' => $filterStatus,
    'cari'   => $cari,
    'hal'    => $halaman,
]));

require_once '../includes/admin_header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
    <div>
        <h1 style="margin:0;">Pengajuan Pembatalan</h1>
        <p style="color:var(--color-text-light); margin-top:6px;">
            Setujui atau tolak pengajuan maksimal 24 jam sejak diajukan.
        </p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <span class="badge badge-warning">Menunggu: <?= $ringkasan['pending'] ?></span>
        <span class="badge badge-success">Disetujui: <?= $ringkasan['approved'] ?></span>
        <span class="badge badge-danger">Ditolak: <?= $ringkasan['rejected'] ?></span>
        <span class="badge badge-neutral">Kedaluwarsa: <?= $ringkasan['expired'] ?></span>
        <?php if ($refundGagal > 0): ?>
            <span class="badge badge-danger">Refund perlu tindakan: <?= $refundGagal ?></span>
        <?php endif; ?>
    </div>
</div>

<form method="GET" action="kelola_pembatalan.php" class="admin-toolbar" style="margin-top:24px;">
    <div class="field form-group" style="margin:0;">
        <label>Status</label>
        <select name="status">
            <option value="">Semua</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Menunggu Persetujuan</option>
            <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>Disetujui</option>
            <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
            <option value="expired" <?= $filterStatus === 'expired' ? 'selected' : '' ?>>Kedaluwarsa</option>
        </select>
    </div>
    <div class="field form-group" style="margin:0;">
        <label>Cari</label>
        <input type="text" name="cari" placeholder="User / lapangan / kode / alasan" value="<?= clean($cari) ?>">
    </div>
    <button type="submit" class="btn btn-outline">Terapkan</button>
    <a href="kelola_pembatalan.php" class="btn btn-outline">Reset</a>
</form>

<div class="card" style="margin-top:20px;">
    <div class="card-body" style="overflow-x:auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Pengguna</th>
                    <th>Booking</th>
                    <th>Jadwal</th>
                    <th>Total / Bayar</th>
                    <th>Alasan</th>
                    <th>Pengajuan</th>
                    <th>Status</th>
                    <th>Refund</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="9" style="text-align:center; color:var(--color-text-light);">Tidak ada pengajuan pembatalan.</td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $cr): ?>
                        <?php
                            $statusLabel = pembatalanLabelStatus($cr['decision_status']);
                            $refundLabel = pembatalanLabelRefund($cr['refund_status']);
                            $bayarLabel = labelStatusPembayaran($cr['payment_status'] ?: 'unpaid');
                            $total = $cr['total_after_discount'] !== null ? $cr['total_after_discount'] : $cr['total_price'];
                            $sedangPending = $cr['decision_status'] === 'pending';
                        ?>
                        <tr>
                            <td>
                                <strong><?= clean($cr['user_name']) ?></strong><br>
                                <span style="font-size:12px; color:var(--color-text-light);"><?= clean($cr['user_email']) ?></span>
                            </td>
                            <td>
                                <strong><?= clean($cr['booking_code']) ?></strong><br>
                                <span style="font-size:12px; color:var(--color-text-light);"><?= clean($cr['court_name']) ?></span>
                            </td>
                            <td>
                                <?= date('d/m/Y', strtotime($cr['booking_date'])) ?><br>
                                <span style="font-size:12px; color:var(--color-text-light);">
                                    <?= substr($cr['start_time'], 0, 5) ?>-<?= substr($cr['end_time'], 0, 5) ?>
                                </span>
                            </td>
                            <td>
                                <?= formatRupiah($total) ?><br>
                                <span class="badge <?= $bayarLabel[1] ?>"><?= clean($bayarLabel[0]) ?></span>
                                <?php if (!empty($cr['is_simulated'])): ?>
                                    <span class="badge badge-warning" style="font-size:11px;">SIM</span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:220px; white-space:normal;">
                                <?= nl2br(clean($cr['reason'])) ?><br>
                                <span style="font-size:11px; color:var(--color-text-light);">
                                    Status awal: <?= clean($cr['previous_booking_status']) ?>
                                </span>
                            </td>
                            <td style="white-space:nowrap;">
                                <?= date('d/m/Y H:i', strtotime($cr['requested_at'])) ?><br>
                                <span style="font-size:12px; color:var(--color-text-light);">Batas: <?= date('d/m/Y H:i', strtotime($cr['deadline_at'])) ?></span>
                                <?php if ($sedangPending): ?>
                                    <br><span style="font-size:12px; color:var(--color-text-light);">Sisa: <?= clean(pembatalanFormatSisa($cr['sisa_detik'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $statusLabel[1] ?>"><?= clean($statusLabel[0]) ?></span>
                                <?php if (!empty($cr['decided_at'])): ?>
                                    <br><span style="font-size:11px; color:var(--color-text-light);"><?= date('d/m/Y H:i', strtotime($cr['decided_at'])) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($cr['decided_by_name'])): ?>
                                    <br><span style="font-size:11px; color:var(--color-text-light);">oleh <?= clean($cr['decided_by_name']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($cr['rejection_reason'])): ?>
                                    <br><span style="font-size:11px; color:var(--color-text-light);">Alasan tolak: <?= clean($cr['rejection_reason']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:180px; white-space:normal;">
                                <span class="badge <?= $refundLabel[1] ?>"><?= clean($refundLabel[0]) ?></span>
                                <?php if (!empty($cr['refund_note'])): ?>
                                    <br><span style="font-size:11px; color:var(--color-text-light);"><?= clean($cr['refund_note']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="min-width:190px;">
                                <?php if ($sedangPending): ?>
                                    <form method="POST" action="kelola_pembatalan.php" style="display:block; margin-bottom:8px;"
                                          onsubmit="return confirm('Setujui pembatalan booking ini? Jika sudah dibayar, refund akan diproses sesuai metode pembayaran.');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="aksi" value="setujui">
                                        <input type="hidden" name="request_id" value="<?= (int) $cr['id'] ?>">
                                        <input type="hidden" name="redirect_query" value="<?= clean($queryString) ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">Setujui</button>
                                    </form>

                                    <form method="POST" action="kelola_pembatalan.php" style="display:block;"
                                          onsubmit="return confirm('Tolak pengajuan pembatalan ini? Booking akan tetap aktif.');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="aksi" value="tolak">
                                        <input type="hidden" name="request_id" value="<?= (int) $cr['id'] ?>">
                                        <input type="hidden" name="redirect_query" value="<?= clean($queryString) ?>">
                                        <input type="text" name="alasan_penolakan" required maxlength="500"
                                               placeholder="Alasan penolakan"
                                               style="width:100%; margin-bottom:6px; padding:6px; border:1px solid var(--color-border); border-radius:6px; font-size:12px;">
                                        <button type="submit" class="btn btn-danger btn-sm">Tolak</button>
                                    </form>
                                <?php elseif ($cr['decision_status'] === 'approved'
                                    && $cr['refund_status'] === 'failed'
                                    && $cr['payment_status'] === 'paid'): ?>
                                    <form method="POST" action="kelola_pembatalan.php" style="display:block;"
                                          onsubmit="return confirm('Coba proses refund Midtrans lagi untuk booking ini?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="aksi" value="retry_refund">
                                        <input type="hidden" name="request_id" value="<?= (int) $cr['id'] ?>">
                                        <input type="hidden" name="redirect_query" value="<?= clean($queryString) ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">Proses Refund Lagi</button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:12px; color:var(--color-text-light);">Sudah diproses</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalHalaman > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalHalaman; $i++): ?>
                    <?php $q = http_build_query(array_filter(['status' => $filterStatus, 'cari' => $cari, 'hal' => $i])); ?>
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
