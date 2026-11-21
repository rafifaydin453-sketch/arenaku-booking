<?php
/**
 * ADMIN - LAPORAN
 * Rekap pendapatan & booking per periode, plus rincian per lapangan.
 */
session_start();
require_once '../config/koneksi.php';
require_once '../includes/functions.php';
require_once '../includes/admin_auth.php';

$pageTitle = 'Laporan';
$adminActive = 'laporan';

// =====================================================================
// PERIODE (default: bulan ini)
// =====================================================================
$dari   = isset($_GET['dari']) && $_GET['dari'] !== '' ? $_GET['dari'] : date('Y-m-01');
$sampai = isset($_GET['sampai']) && $_GET['sampai'] !== '' ? $_GET['sampai'] : date('Y-m-d');

// Validasi format tanggal
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) $dari = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) $sampai = date('Y-m-d');

// =====================================================================
// REKAP UTAMA (berdasarkan tanggal booking)
// =====================================================================
$stmt = $koneksi->prepare(
    "SELECT
        COUNT(*) AS total_booking,
        SUM(CASE WHEN bookings.booking_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN bookings.booking_status = 'completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN bookings.booking_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN bookings.booking_status = 'pending_payment' THEN 1 ELSE 0 END) AS pending
     FROM bookings
     WHERE bookings.booking_date BETWEEN ? AND ?"
);
$stmt->execute([$dari, $sampai]);
$rekap = $stmt->fetch();

// Pendapatan lunas dalam periode (via paid_at)
$stmt = $koneksi->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total
     FROM payments
     WHERE payment_status = 'paid'
       AND DATE(paid_at) BETWEEN ? AND ?"
);
$stmt->execute([$dari, $sampai]);
$pendapatan = $stmt->fetchColumn();

// =====================================================================
// RINCIAN PER LAPANGAN
// =====================================================================
$stmt = $koneksi->prepare(
    "SELECT courts.name AS court_name,
            COUNT(bookings.id) AS jumlah_booking,
            COALESCE(SUM(CASE WHEN payments.payment_status = 'paid' THEN payments.amount ELSE 0 END), 0) AS pendapatan
     FROM bookings
     JOIN courts ON bookings.court_id = courts.id
     LEFT JOIN payments ON payments.booking_id = bookings.id
     WHERE bookings.booking_date BETWEEN ? AND ?
     GROUP BY courts.id, courts.name
     ORDER BY pendapatan DESC"
);
$stmt->execute([$dari, $sampai]);
$perLapangan = $stmt->fetchAll();

// =====================================================================
// RINCIAN HARIAN
// =====================================================================
$stmt = $koneksi->prepare(
    "SELECT bookings.booking_date,
            COUNT(*) AS jumlah,
            COALESCE(SUM(CASE WHEN payments.payment_status = 'paid' THEN payments.amount ELSE 0 END), 0) AS pendapatan
     FROM bookings
     LEFT JOIN payments ON payments.booking_id = bookings.id
     WHERE bookings.booking_date BETWEEN ? AND ?
     GROUP BY bookings.booking_date
     ORDER BY bookings.booking_date DESC"
);
$stmt->execute([$dari, $sampai]);
$perHari = $stmt->fetchAll();

require_once '../includes/admin_header.php';
?>

<div>
    <h1 style="margin:0;">Laporan</h1>
    <p style="color:var(--color-text-light);">Periode <?= date('d/m/Y', strtotime($dari)) ?> - <?= date('d/m/Y', strtotime($sampai)) ?></p>
</div>

<!-- ============ FILTER PERIODE ============ -->
<form method="GET" action="laporan.php" class="admin-toolbar" style="margin-top:24px;">
    <div class="field form-group" style="margin:0;">
        <label>Dari</label>
        <input type="date" name="dari" value="<?= clean($dari) ?>">
    </div>
    <div class="field form-group" style="margin:0;">
        <label>Sampai</label>
        <input type="date" name="sampai" value="<?= clean($sampai) ?>">
    </div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
    <a href="laporan.php" class="btn btn-outline">Bulan Ini</a>
</form>

<!-- ============ RINGKASAN ============ -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-top:24px;">
    <div class="card"><div class="card-body">
        <p style="color:var(--color-text-light);">Total Pendapatan (Lunas)</p>
        <h2><?= formatRupiah($pendapatan) ?></h2>
    </div></div>
    <div class="card"><div class="card-body">
        <p style="color:var(--color-text-light);">Total Booking</p>
        <h2><?= number_format($rekap['total_booking']) ?></h2>
    </div></div>
    <div class="card"><div class="card-body">
        <p style="color:var(--color-text-light);">Terkonfirmasi</p>
        <h2><?= number_format($rekap['confirmed']) ?></h2>
    </div></div>
    <div class="card"><div class="card-body">
        <p style="color:var(--color-text-light);">Selesai</p>
        <h2><?= number_format($rekap['completed']) ?></h2>
    </div></div>
    <div class="card"><div class="card-body">
        <p style="color:var(--color-text-light);">Menunggu Bayar</p>
        <h2><?= number_format($rekap['pending']) ?></h2>
    </div></div>
    <div class="card"><div class="card-body">
        <p style="color:var(--color-text-light);">Dibatalkan</p>
        <h2><?= number_format($rekap['cancelled']) ?></h2>
    </div></div>
</div>

<!-- ============ PER LAPANGAN ============ -->
<div class="card" style="margin-top:24px;">
    <div class="card-body">
        <h3>Pendapatan per Lapangan</h3>
        <div style="overflow-x:auto; margin-top:12px;">
            <table class="admin-table">
                <thead>
                    <tr><th>Lapangan</th><th>Jumlah Booking</th><th>Pendapatan (Lunas)</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($perLapangan)): ?>
                        <tr><td colspan="3" style="text-align:center; color:var(--color-text-light);">Tidak ada data.</td></tr>
                    <?php else: ?>
                        <?php foreach ($perLapangan as $l): ?>
                            <tr>
                                <td><?= clean($l['court_name']) ?></td>
                                <td><?= number_format($l['jumlah_booking']) ?></td>
                                <td><?= formatRupiah($l['pendapatan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============ PER HARI ============ -->
<div class="card" style="margin-top:24px;">
    <div class="card-body">
        <h3>Rincian Harian</h3>
        <div style="overflow-x:auto; margin-top:12px;">
            <table class="admin-table">
                <thead>
                    <tr><th>Tanggal</th><th>Jumlah Booking</th><th>Pendapatan (Lunas)</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($perHari)): ?>
                        <tr><td colspan="3" style="text-align:center; color:var(--color-text-light);">Tidak ada data.</td></tr>
                    <?php else: ?>
                        <?php foreach ($perHari as $h): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($h['booking_date'])) ?></td>
                                <td><?= number_format($h['jumlah']) ?></td>
                                <td><?= formatRupiah($h['pendapatan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
