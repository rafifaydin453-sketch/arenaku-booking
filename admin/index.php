<?php

session_start();

require_once '../config/koneksi.php';
require_once '../includes/functions.php';
require_once '../includes/admin_auth.php';

$pageTitle = 'Admin Dashboard';


// ================================================================
// 1. TOTAL USER
// ================================================================

$stmt = $koneksi->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE role = 'user'
");

$totalUser = $stmt->fetch()['total'];


// ================================================================
// 2. TOTAL LAPANGAN
// ================================================================

$stmt = $koneksi->query("
    SELECT COUNT(*) AS total
    FROM courts
");

$totalLapangan = $stmt->fetch()['total'];


// ================================================================
// 3. TOTAL BOOKING
// ================================================================

$stmt = $koneksi->query("
    SELECT COUNT(*) AS total
    FROM bookings
");

$totalBooking = $stmt->fetch()['total'];


// ================================================================
// 4. BOOKING HARI INI
// ================================================================

$stmt = $koneksi->query("
    SELECT COUNT(*) AS total
    FROM bookings
    WHERE booking_date = CURDATE()
");

$bookingHariIni = $stmt->fetch()['total'];


// ================================================================
// 5. BOOKING PENDING
// ================================================================

$stmt = $koneksi->query("
    SELECT COUNT(*) AS total
    FROM bookings
    WHERE booking_status = 'pending_payment'
");

$bookingPending = $stmt->fetch()['total'];


// ================================================================
// 6. PEMBAYARAN PENDING
// ================================================================

$stmt = $koneksi->query("
    SELECT COUNT(*) AS total
    FROM payments
    WHERE payment_status = 'pending'
");

$pembayaranPending = $stmt->fetch()['total'];


// ================================================================
// 7. PENGAJUAN PEMBATALAN MENUNGGU KEPUTUSAN
// ================================================================

$stmt = $koneksi->query("
    SELECT COUNT(*) AS total
    FROM cancellation_requests
    WHERE decision_status = 'pending'
      AND deadline_at > NOW()
");

$pembatalanPending = $stmt->fetch()['total'];


// ================================================================
// 8. TOTAL PENDAPATAN
// ================================================================

$stmt = $koneksi->query("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM payments
    WHERE payment_status = 'paid'
");

$totalPendapatan = $stmt->fetch()['total'];

$adminActive = 'dashboard';
require_once '../includes/admin_header.php';
?>

<div>
    <h1 style="margin:0;">Dashboard Admin</h1>
    <p style="color:var(--color-text-light);">Ringkasan data ArenaKu</p>
</div>

<!-- =================================================
     STATISTIK
================================================== -->

<div
    style="
        display:grid;
        grid-template-columns:
            repeat(auto-fit, minmax(220px, 1fr));
        gap:20px;
        margin-top:24px;
    "
>


    <!-- TOTAL USER -->

    <div class="card">

        <div class="card-body">

            <p>
                Total User
            </p>

            <h2>
                <?= number_format($totalUser) ?>
            </h2>

        </div>

    </div>


    <!-- TOTAL LAPANGAN -->

    <div class="card">

        <div class="card-body">

            <p>
                Total Lapangan
            </p>

            <h2>
                <?= number_format($totalLapangan) ?>
            </h2>

        </div>

    </div>


    <!-- TOTAL BOOKING -->

    <div class="card">

        <div class="card-body">

            <p>
                Total Booking
            </p>

            <h2>
                <?= number_format($totalBooking) ?>
            </h2>

        </div>

    </div>


    <!-- BOOKING HARI INI -->

    <div class="card">

        <div class="card-body">

            <p>
                Booking Hari Ini
            </p>

            <h2>
                <?= number_format($bookingHariIni) ?>
            </h2>

        </div>

    </div>


    <!-- BOOKING PENDING -->

    <div class="card">

        <div class="card-body">

            <p>
                Booking Pending
            </p>

            <h2>
                <?= number_format($bookingPending) ?>
            </h2>

        </div>

    </div>


    <!-- PEMBAYARAN PENDING -->

    <div class="card">

        <div class="card-body">

            <p>
                Pembayaran Pending
            </p>

            <h2>
                <?= number_format($pembayaranPending) ?>
            </h2>

        </div>

    </div>


    <!-- PENGAJUAN PEMBATALAN -->

    <div class="card">

        <div class="card-body">

            <p>
                Pengajuan Pembatalan
            </p>

            <h2>
                <?= number_format($pembatalanPending) ?>
            </h2>

            <a href="kelola_pembatalan.php" class="btn btn-outline btn-sm" style="margin-top:10px;">Lihat pengajuan</a>

        </div>

    </div>


    <!-- TOTAL PENDAPATAN -->

    <div class="card">

        <div class="card-body">

            <p>
                Total Pendapatan
            </p>

            <h2>
                <?= formatRupiah($totalPendapatan) ?>
            </h2>

        </div>

    </div>

</div>


<!-- =================================================
     MENU ADMIN
================================================== -->

<div
    class="card"
    style="margin-top:32px;"
>

    <div class="card-body">

        <h3>
            Menu Admin
        </h3>

        <p style="margin-top:8px;">
            Kelola seluruh data ArenaKu dari sini.
        </p>


        <div
            style="
                display:grid;
                grid-template-columns:
                    repeat(auto-fit, minmax(180px, 1fr));
                gap:12px;
                margin-top:20px;
            "
        >

            <a href="kelola_user.php" class="btn btn-outline">Kelola User</a>
            <a href="kelola_lapangan.php" class="btn btn-outline">Kelola Lapangan</a>
            <a href="kelola_booking.php" class="btn btn-outline">Kelola Booking</a>
            <a href="kelola_pembatalan.php" class="btn btn-outline">Pengajuan Pembatalan</a>
            <a href="kelola_pembayaran.php" class="btn btn-outline">Verifikasi Pembayaran</a>
            <a href="kelola_promo.php" class="btn btn-outline">Kelola Promo</a>
            <a href="laporan.php" class="btn btn-outline">Laporan</a>

        </div>

    </div>

</div>

<?php require_once '../includes/admin_footer.php'; ?>
