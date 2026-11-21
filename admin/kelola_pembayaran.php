<?php
/**
 * ADMIN - PENGELOLAAN PEMBAYARAN
 * Sinkronisasi status Midtrans dan tindakan administratif pembayaran.
 */
session_start();
require_once '../config/koneksi.php';
require_once '../includes/functions.php';
require_once '../includes/admin_auth.php';
require_once '../includes/midtrans_helper.php';
require_once '../includes/simulasi_helper.php';

$pageTitle = 'Verifikasi Pembayaran';
$adminActive = 'pembayaran';

if (!function_exists('pembayaranMetodeAdmin')) {
    function pembayaranMetodeAdmin($metode)
    {
        $map = [
            'qris'          => 'QRIS',
            'transfer_bank' => 'Virtual Account',
            'e_wallet'      => 'E-Wallet',
        ];
        if (isset($map[$metode])) {
            return $map[$metode];
        }
        return ucwords(str_replace('_', ' ', (string) $metode));
    }
}

// =====================================================================
// PROSES AKSI
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerifikasi(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
        setFlashError('Sesi form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.');
        redirect('kelola_pembayaran.php');
    }

    $aksi = isset($_POST['aksi']) ? $_POST['aksi'] : '';
    $paymentId = (int) (isset($_POST['payment_id']) ? $_POST['payment_id'] : 0);
    $returnTo = isset($_POST['return_to']) ? (string) $_POST['return_to'] : 'kelola_pembayaran.php';
    if (!preg_match('/^(?:kelola_pembayaran|detail_pembayaran)\.php(?:\?[^#]*)?$/', $returnTo)) {
        $returnTo = 'kelola_pembayaran.php';
    }

    // ---------- KEPUTUSAN ATAS BUKTI PEMBAYARAN (simulasi & popup Midtrans) ----------
    if ($aksi === 'terima_bukti' || $aksi === 'tolak_bukti') {
        $keputusan = $aksi === 'terima_bukti' ? 'terima' : 'tolak';
        $alasanTolak = isset($_POST['alasan_penolakan']) ? trim((string) $_POST['alasan_penolakan']) : '';

        if ($keputusan === 'tolak' && $alasanTolak === '') {
            setFlashError('Alasan penolakan wajib diisi.');
            redirect($returnTo);
        }

        $hasil = pembayaranAdminPutusan($paymentId, (int) $_SESSION['user_id'], $keputusan, $alasanTolak);

        if ($hasil['ok']) {
            setFlashSuccess($hasil['message']);
        } else {
            setFlashError($hasil['error']);
        }
        redirect($returnTo);
    }

    // ---------- SINKRONKAN STATUS DARI MIDTRANS ----------
    if ($aksi === 'cek_midtrans') {
        $stmt = $koneksi->prepare("SELECT midtrans_order_id FROM payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        $orderId = $stmt->fetchColumn();

        if (!$orderId) {
            setFlashError('Transaksi ini belum punya order Midtrans.');
        } elseif (!midtransReady()) {
            setFlashError('Konfigurasi Midtrans belum aktif.');
        } else {
            try {
                $hasil = midtransProcessStatus($orderId, 'status_api');
                if ($hasil['ok']) {
                    setFlashSuccess('Status diperbarui dari Midtrans: ' . $hasil['label']);
                } else {
                    setFlashError('Gagal cek Midtrans: ' . $hasil['error']);
                }
            } catch (Throwable $e) {
                setFlashError('Gagal cek Midtrans: ' . $e->getMessage());
            }
        }
        redirect($returnTo);
    }

    // ---------- TANDAI LUNAS ----------
    if ($aksi === 'tandai_lunas') {
        // Jika bukti sedang menunggu verifikasi, status ditentukan oleh
        // keputusan atas bukti (terima/tolak), bukan aksi manual.
        $stmt = $koneksi->prepare("SELECT proof_status FROM payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        if ($stmt->fetchColumn() === 'pending') {
            setFlashError('Bukti pembayaran sedang menunggu verifikasi. Gunakan tombol Terima/Tolak Bukti.');
            redirect($returnTo);
        }

        $stmt = $koneksi->prepare(
            "SELECT booking_id FROM payments
             WHERE id = ? AND payment_status IN ('unpaid', 'pending', 'failed', 'expired')"
        );
        $stmt->execute([$paymentId]);
        $bookingId = $stmt->fetchColumn();

        if (!$bookingId) {
            setFlashError('Transaksi tidak ditemukan atau statusnya tidak bisa ditandai lunas.');
        } else {
            $stmt = $koneksi->prepare(
                "UPDATE payments SET payment_status = 'paid', paid_at = NOW(),
                        status_detail = 'Ditandai lunas oleh admin'
                 WHERE id = ? AND payment_status IN ('unpaid', 'pending', 'failed', 'expired')"
            );
            $stmt->execute([$paymentId]);

            $stmt = $koneksi->prepare(
                "UPDATE bookings SET booking_status = 'confirmed'
                 WHERE id = ? AND booking_status = 'pending_payment'"
            );
            $stmt->execute([(int) $bookingId]);

            setFlashSuccess('Pembayaran ditandai lunas.');
        }
        redirect($returnTo);
    }

    // ---------- TOLAK / GAGALKAN ----------
    if ($aksi === 'tolak') {
        $stmt = $koneksi->prepare("SELECT proof_status FROM payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        if ($stmt->fetchColumn() === 'pending') {
            setFlashError('Bukti pembayaran sedang menunggu verifikasi. Gunakan tombol Terima/Tolak Bukti.');
            redirect($returnTo);
        }

        $stmt = $koneksi->prepare(
            "UPDATE payments SET payment_status = 'failed',
                    status_detail = 'Ditolak oleh admin'
             WHERE id = ? AND payment_status IN ('pending', 'unpaid')"
        );
        $stmt->execute([$paymentId]);

        if ($stmt->rowCount() > 0) {
            setFlashSuccess('Pembayaran ditandai gagal.');
        } else {
            setFlashError('Transaksi tidak ditemukan atau statusnya tidak bisa digagalkan.');
        }
        redirect($returnTo);
    }

    redirect('kelola_pembayaran.php');
}

// =====================================================================
// DATA + FILTER
// =====================================================================
$filterStatus = isset($_GET['status']) ? (string) $_GET['status'] : '';
$filterVerifikasi = isset($_GET['verifikasi']) ? (string) $_GET['verifikasi'] : '';
$cari = isset($_GET['cari']) ? trim((string) $_GET['cari']) : '';

$perHalaman = 15;
$halaman = isset($_GET['hal']) ? max(1, (int) $_GET['hal']) : 1;
$offset = ($halaman - 1) * $perHalaman;

$where = [];
$params = [];
if (in_array($filterStatus, ['unpaid', 'pending', 'paid', 'failed', 'expired', 'refunded'], true)) {
    $where[] = 'payments.payment_status = ?';
    $params[] = $filterStatus;
}
if (in_array($filterVerifikasi, ['none', 'pending', 'approved', 'rejected'], true)) {
    $where[] = 'payments.proof_status = ?';
    $params[] = $filterVerifikasi;
}
if ($cari !== '') {
    $where[] = '(bookings.booking_code LIKE ? OR users.name LIKE ? OR users.email LIKE ?)';
    $like = '%' . $cari . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sqlBase = "FROM payments
            JOIN bookings ON payments.booking_id = bookings.id
            JOIN users ON bookings.user_id = users.id
            JOIN courts ON bookings.court_id = courts.id
            $whereSql";

$stmtCount = $koneksi->prepare("SELECT COUNT(*) $sqlBase");
$stmtCount->execute($params);
$totalData = (int) $stmtCount->fetchColumn();
$totalHalaman = max(1, (int) ceil($totalData / $perHalaman));

$stmtList = $koneksi->prepare(
    "SELECT payments.id, payments.booking_id, payments.payment_method, payments.amount,
            payments.payment_status, payments.payment_type, payments.bank,
            payments.midtrans_order_id, payments.is_simulated, payments.paid_at, payments.created_at,
            payments.payment_proof, payments.payment_proof_uploaded_at, payments.verified_at,
            payments.proof_status, payments.rejection_reason,
            bookings.booking_code, bookings.booking_status, bookings.booking_date,
            bookings.start_time, bookings.end_time,
            users.name AS user_name, users.email AS user_email,
            courts.name AS court_name
     $sqlBase
     ORDER BY payments.created_at DESC, payments.id DESC
     LIMIT $perHalaman OFFSET $offset"
);
$stmtList->execute($params);
$payments = $stmtList->fetchAll();

$sumPaid = $koneksi->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='paid'")->fetchColumn();
$sumPending = (int) $koneksi->query("SELECT COUNT(*) FROM payments WHERE payment_status='pending'")->fetchColumn();
$sumTotal = (int) $koneksi->query("SELECT COUNT(*) FROM payments")->fetchColumn();

$returnParams = array_filter([
    'status' => $filterStatus,
    'verifikasi' => $filterVerifikasi,
    'cari'   => $cari,
    'hal'    => $halaman > 1 ? $halaman : '',
], function ($nilai) {
    return $nilai !== '' && $nilai !== null;
});
$returnTo = 'kelola_pembayaran.php' . ($returnParams ? '?' . http_build_query($returnParams) : '');

require_once '../includes/admin_header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <h1 style="margin:0;">Verifikasi Pembayaran</h1>
        <p style="color:var(--color-text-light);">
            Total pendapatan lunas: <strong><?= formatRupiah($sumPaid) ?></strong> &middot;
            Menunggu: <strong><?= $sumPending ?></strong> &middot;
            Total transaksi: <strong><?= $sumTotal ?></strong>
        </p>
    </div>
    <?php if (!midtransReady()): ?>
        <span class="badge badge-warning">Midtrans belum aktif</span>
    <?php else: ?>
        <span class="badge badge-success">Midtrans aktif</span>
    <?php endif; ?>
</div>

<form method="GET" action="kelola_pembayaran.php" class="admin-toolbar" style="margin-top:18px;">
    <div class="field form-group" style="margin:0;">
        <label>Status</label>
        <select name="status">
            <option value="">Semua</option>
            <option value="unpaid" <?= $filterStatus === 'unpaid' ? 'selected' : '' ?>>Belum Bayar</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Menunggu</option>
            <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Lunas</option>
            <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Gagal</option>
            <option value="expired" <?= $filterStatus === 'expired' ? 'selected' : '' ?>>Kedaluwarsa</option>
            <option value="refunded" <?= $filterStatus === 'refunded' ? 'selected' : '' ?>>Refund</option>
        </select>
    </div>
    <div class="field form-group" style="margin:0;">
        <label>Verifikasi bukti</label>
        <select name="verifikasi">
            <option value="">Semua</option>
            <option value="pending" <?= $filterVerifikasi === 'pending' ? 'selected' : '' ?>>Menunggu keputusan</option>
            <option value="approved" <?= $filterVerifikasi === 'approved' ? 'selected' : '' ?>>Disetujui</option>
            <option value="rejected" <?= $filterVerifikasi === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
            <option value="none" <?= $filterVerifikasi === 'none' ? 'selected' : '' ?>>Tanpa bukti</option>
        </select>
    </div>
    <div class="field form-group" style="margin:0;">
        <label>Cari</label>
        <input type="text" name="cari" placeholder="Kode booking / user / email" value="<?= clean($cari) ?>">
    </div>
    <button type="submit" class="btn btn-outline">Terapkan</button>
    <a href="kelola_pembayaran.php" class="btn btn-outline">Reset</a>
</form>

<!-- ============ TABEL ============ -->
<div class="card" style="margin-top:18px;">
    <div class="card-body" style="overflow-x:auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Booking / User</th>
                    <th>Jadwal</th>
                    <th>Total</th>
                    <th>Metode</th>
                    <th>Bukti</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; color:var(--color-text-light);">
                            Tidak ada data pembayaran.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <?php
                            $pId = (int) $p['id'];
                            $lp = labelStatusPembayaran($p['payment_status']);
                            $simLp = simulasiStatusPembayaran($p);
                            if ($simLp !== null) {
                                $lp = $simLp;
                            }
                            $proofUrl = !empty($p['payment_proof']) ? simulasiBuktiUrl($p['payment_proof']) : '';
                            $proofStatus = !empty($p['proof_status']) ? $p['proof_status'] : 'none';
                            // Bukti menunggu keputusan admin (simulasi maupun alur Midtrans).
                            $proofPending = $proofStatus === 'pending';
                            list($verifLabel, $verifClass) = labelStatusVerifikasi($proofStatus);
                            $providerLabel = simulasiEwalletProviderLabel($p);
                            $metodeTampil = pembayaranMetodeAdmin($p['payment_method']);
                            if (!empty($p['is_simulated']) && $metodeTampil !== '') {
                                $metodeTampil .= ' Simulasi';
                            }
                        ?>
                        <tr>
                            <td>
                                <strong><?= clean($p['booking_code']) ?></strong><br>
                                <span style="font-size:12px; color:var(--color-text-light);">
                                    <?= clean($p['user_name']) ?> &middot; <?= clean($p['user_email']) ?>
                                </span><br>
                                <span style="font-size:12px; color:var(--color-text-light);"><?= clean($p['court_name']) ?></span>
                            </td>
                            <td>
                                <?= formatTanggalId($p['booking_date']) ?><br>
                                <span style="font-size:12px; color:var(--color-text-light);">
                                    <?= substr($p['start_time'], 0, 5) ?>-<?= substr($p['end_time'], 0, 5) ?>
                                </span>
                            </td>
                            <td><?= formatRupiah($p['amount']) ?></td>
                            <td>
                                <?= clean($metodeTampil) ?><br>
                                <?php if ($providerLabel !== ''): ?>
                                    <span style="font-size:12px; color:var(--color-text-light);">Provider: <strong><?= clean($providerLabel) ?></strong></span><br>
                                <?php elseif (!empty($p['payment_type'])): ?>
                                    <span style="font-size:12px; color:var(--color-text-light);"><?= clean(strtoupper($p['payment_type'])) ?></span><br>
                                <?php endif; ?>
                                <?php if (!empty($p['bank'])): ?>
                                    <span style="font-size:12px; color:var(--color-text-light);"><?= clean(strtoupper($p['bank'])) ?></span><br>
                                <?php endif; ?>
                                <?php if ($p['midtrans_order_id']): ?>
                                    <code style="font-size:11px;"><?= clean($p['midtrans_order_id']) ?></code>
                                <?php endif; ?>
                                <?php if (!empty($p['is_simulated'])): ?>
                                    <span class="badge badge-warning" style="font-size:11px;">SIM</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($proofUrl): ?>
                                    <a href="<?= clean($proofUrl) ?>" target="_blank" rel="noopener">
                                        <img src="<?= clean($proofUrl) ?>" alt="Bukti pembayaran <?= clean($p['booking_code']) ?>"
                                             style="width:64px; height:64px; object-fit:cover; border-radius:8px; border:1px solid var(--line);">
                                    </a>
                                    <?php if (!empty($p['payment_proof_uploaded_at'])): ?>
                                        <br>
                                        <span style="font-size:11px; color:var(--color-text-light);"><?= date('d/m/Y H:i', strtotime($p['payment_proof_uploaded_at'])) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:var(--color-text-light);">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $lp[1] ?>"><?= clean($lp[0]) ?></span>
                                <?php if ($proofUrl): ?>
                                    <br>
                                    <span class="badge <?= $verifClass ?>" style="font-size:11px; margin-top:4px;">Bukti: <?= clean($verifLabel) ?></span>
                                    <?php if ($proofStatus === 'rejected' && !empty($p['rejection_reason'])): ?>
                                        <br>
                                        <span style="font-size:11px; color:var(--color-text-light);">
                                            Alasan: <?= clean(mb_strimwidth($p['rejection_reason'], 0, 60, '...')) ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($p['paid_at']): ?>
                                    <br>
                                    <span style="font-size:11px; color:var(--color-text-light);">
                                        <?= date('d/m/Y H:i', strtotime($p['paid_at'])) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="admin-actions">
                                    <?php if ($proofPending): ?>
                                        <form method="POST" action="kelola_pembayaran.php" onsubmit="return confirm('Terima bukti pembayaran ini? Booking akan dikonfirmasi.');" style="display:inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="aksi" value="terima_bukti">
                                            <input type="hidden" name="payment_id" value="<?= $pId ?>">
                                            <input type="hidden" name="return_to" value="<?= clean($returnTo) ?>">
                                            <button type="submit" class="btn btn-primary btn-sm">Terima Bukti</button>
                                        </form>

                                        <form method="POST" action="kelola_pembayaran.php" style="display:inline;"
                                              onsubmit="var a = prompt('Alasan penolakan (wajib):'); if (!a || !a.trim()) { return false; } this.querySelector('[name=alasan_penolakan]').value = a.trim(); return confirm('Tolak bukti pembayaran ini? Pengguna dapat mengunggah ulang bukti.');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="aksi" value="tolak_bukti">
                                            <input type="hidden" name="alasan_penolakan" value="">
                                            <input type="hidden" name="payment_id" value="<?= $pId ?>">
                                            <input type="hidden" name="return_to" value="<?= clean($returnTo) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Tolak Bukti</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($proofUrl || $proofStatus !== 'none'): ?>
                                        <a href="detail_pembayaran.php?id=<?= $pId ?>" class="btn btn-outline btn-sm">Detail</a>
                                    <?php endif; ?>

                                    <?php if (!$proofPending): ?>
                                        <?php if ($p['midtrans_order_id']): ?>
                                            <form method="POST" action="kelola_pembayaran.php" style="display:inline;">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="aksi" value="cek_midtrans">
                                                <input type="hidden" name="payment_id" value="<?= $pId ?>">
                                                <input type="hidden" name="return_to" value="<?= clean($returnTo) ?>">
                                                <button type="submit" class="btn btn-outline btn-sm">Cek Midtrans</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (in_array($p['payment_status'], ['unpaid', 'pending', 'failed', 'expired'], true)): ?>
                                            <form method="POST" action="kelola_pembayaran.php" onsubmit="return confirm('Tandai pembayaran ini LUNAS?');" style="display:inline;">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="aksi" value="tandai_lunas">
                                                <input type="hidden" name="payment_id" value="<?= $pId ?>">
                                                <input type="hidden" name="return_to" value="<?= clean($returnTo) ?>">
                                                <button type="submit" class="btn btn-primary btn-sm">Tandai Lunas</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (in_array($p['payment_status'], ['pending', 'unpaid'], true)): ?>
                                            <form method="POST" action="kelola_pembayaran.php" onsubmit="return confirm('Tandai pembayaran ini GAGAL?');" style="display:inline;">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="aksi" value="tolak">
                                                <input type="hidden" name="payment_id" value="<?= $pId ?>">
                                                <input type="hidden" name="return_to" value="<?= clean($returnTo) ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Gagalkan</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalHalaman > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalHalaman; $i++): ?>
                    <?php
                    $q = http_build_query(array_filter([
                        'status' => $filterStatus,
                        'cari' => $cari,
                        'hal' => $i,
                    ], function ($nilai) {
                        return $nilai !== '' && $nilai !== null;
                    }));
                    ?>
                    <?php if ($i === $halaman): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= clean($q) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
