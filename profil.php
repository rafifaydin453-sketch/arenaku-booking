<?php

session_start();

require_once 'config/koneksi.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php'; // WAJIB LOGIN

$pageTitle = 'Profil Saya';

$errorsData = [];
$errorsPassword = [];

$successData = false;
$successPassword = false;


// ================================================================
// AMBIL DATA USER YANG SEDANG LOGIN
// ================================================================

$stmt = $koneksi->prepare("
    SELECT *
    FROM users
    WHERE id = ?
");

$stmt->execute([$_SESSION['user_id']]);

$user = $stmt->fetch();


// ================================================================
// PROSES UPDATE DATA PROFIL
// Nama, Email, Nomor HP
// ================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profil'])) {

    $name  = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);


    // Semua field wajib diisi
    if (empty($name) || empty($email) || empty($phone)) {

        $errorsData[] = 'Semua field wajib diisi.';
    }


    // Validasi format email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $errorsData[] = 'Format email tidak valid.';
    }


    // Batas panjang harus sama dengan lebar kolom di database (name/email 100, phone 20)
    // agar tidak memicu fatal "Data too long" pada saat UPDATE.
    if (mb_strlen($name) > 100) {
        $errorsData[] = 'Nama maksimal 100 karakter.';
    }
    if (mb_strlen($email) > 100) {
        $errorsData[] = 'Email maksimal 100 karakter.';
    }
    if (mb_strlen($phone) > 20) {
        $errorsData[] = 'Nomor telepon maksimal 20 karakter.';
    }


    // ============================================================
    // CEK EMAIL DUPLIKAT
    // ============================================================

    if (empty($errorsData)) {

        $stmtCek = $koneksi->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            AND id != ?
        ");

        $stmtCek->execute([
            $email,
            $_SESSION['user_id']
        ]);

        if ($stmtCek->fetch()) {

            $errorsData[] = 'Email sudah dipakai akun lain.';
        }
    }


    // ============================================================
    // UPDATE DATA USER
    // ============================================================

    if (empty($errorsData)) {

        $stmtUpdate = $koneksi->prepare("
            UPDATE users
            SET name = ?, email = ?, phone = ?
            WHERE id = ?
        ");

        $stmtUpdate->execute([
            $name,
            $email,
            $phone,
            $_SESSION['user_id']
        ]);


        // Update session nama supaya navbar langsung berubah
        $_SESSION['name'] = $name;


        $successData = true;


        // Ambil ulang data terbaru
        $stmt->execute([
            $_SESSION['user_id']
        ]);

        $user = $stmt->fetch();
    }
}


// ================================================================
// PROSES GANTI PASSWORD
// ================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {

    $passwordLama   = $_POST['password_lama'];
    $passwordBaru   = $_POST['password_baru'];
    $konfirmasiBaru = $_POST['konfirmasi_password_baru'];


    // Semua field password wajib diisi
    if (
        empty($passwordLama) ||
        empty($passwordBaru) ||
        empty($konfirmasiBaru)
    ) {

        $errorsPassword[] = 'Semua field password wajib diisi.';
    }


    // ============================================================
    // VERIFIKASI PASSWORD LAMA
    // ============================================================

    if (
        empty($errorsPassword) &&
        !password_verify($passwordLama, $user['password'])
    ) {

        $errorsPassword[] = 'Password lama salah.';
    }


    // Password baru minimal 6 karakter
    if (strlen($passwordBaru) < 6) {

        $errorsPassword[] =
            'Password baru minimal 6 karakter.';
    }


    // Password baru dan konfirmasi harus sama
    if ($passwordBaru !== $konfirmasiBaru) {

        $errorsPassword[] =
            'Konfirmasi password baru tidak cocok.';
    }


    // ============================================================
    // SIMPAN PASSWORD BARU
    // ============================================================

    if (empty($errorsPassword)) {

        $hashBaru = password_hash(
            $passwordBaru,
            PASSWORD_DEFAULT
        );


        $stmtUpdatePw = $koneksi->prepare("
            UPDATE users
            SET password = ?
            WHERE id = ?
        ");

        $stmtUpdatePw->execute([
            $hashBaru,
            $_SESSION['user_id']
        ]);


        $successPassword = true;
    }
}


require_once 'includes/header.php';
require_once 'includes/navbar.php';

?>

<main id="main-content">
    <section class="section">
        <div class="container">
            <div class="page-head">
                <div>
                    <span class="eyebrow">Akun saya</span>
                    <h1 style="font-size:clamp(2rem,3.6vw,3rem); margin-top:12px;">Profil saya</h1>
                    <p>Perbarui data diri dan keamanan akunmu.</p>
                </div>
            </div>

            <div class="account-layout">
                <aside class="account-side">
                    <div class="account-user">
                        <div class="account-user-row">
                            <span class="avatar avatar-lg"><?= clean(getInitials($user['name'])) ?></span>
                            <div>
                                <h3><?= clean($user['name']) ?></h3>
                                <p><?= clean($user['email']) ?></p>
                            </div>
                        </div>
                    </div>

                    <nav class="account-nav" aria-label="Navigasi akun">
                        <a href="/arenaku/profil.php" class="active"><?= svgIcon('user', 'icon') ?> Profil saya</a>
                        <a href="/arenaku/booking_saya.php"><?= svgIcon('ticket', 'icon') ?> Booking saya</a>
                        <a href="/arenaku/promo.php"><?= svgIcon('spark', 'icon') ?> Promo</a>
                        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                            <a href="/arenaku/admin/index.php"><?= svgIcon('grid', 'icon') ?> Panel admin</a>
                        <?php endif; ?>
                        <div class="account-nav-sep"></div>
                        <a href="/arenaku/index.php"><?= svgIcon('arrow-left', 'icon') ?> Kembali ke beranda</a>
                        <a href="/arenaku/logout.php" class="is-danger"><?= svgIcon('logout', 'icon') ?> Keluar</a>
                    </nav>
                </aside>

                <div class="account-content">
                    <?php if ($successData): ?>
                        <div class="alert alert-success">
                            <?= svgIcon('check', 'icon') ?>
                            <div><p>Data profil berhasil diperbarui.</p></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($successPassword): ?>
                        <div class="alert alert-success">
                            <?= svgIcon('check', 'icon') ?>
                            <div><p>Password berhasil diubah.</p></div>
                        </div>
                    <?php endif; ?>

                    <div class="panel">
                        <div class="account-panel-head">
                            <div>
                                <h3>Data diri</h3>
                                <p>Nama dan kontak ini akan dipakai saat kamu booking.</p>
                            </div>
                            <span class="badge badge-lime"><?= ($user['role'] === 'admin') ? 'Admin' : 'Member' ?></span>
                        </div>

                        <div class="account-panel-body">
                            <?php if (!empty($errorsData)): ?>
                                <div class="alert alert-warning" style="margin-bottom:18px;">
                                    <?= svgIcon('alert', 'icon') ?>
                                    <div>
                                        <?php foreach ($errorsData as $err): ?>
                                            <p><?= clean($err) ?></p>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="profil.php">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label" for="name">Nama Lengkap</label>
                                        <input type="text" name="name" id="name" class="form-input" value="<?= clean($user['name']) ?>" autocomplete="name" required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="email">Email</label>
                                        <input type="email" name="email" id="email" class="form-input" value="<?= clean($user['email']) ?>" autocomplete="email" required>
                                    </div>

                                    <div class="form-group form-full">
                                        <label class="form-label" for="phone">Nomor HP</label>
                                        <input type="text" name="phone" id="phone" class="form-input" value="<?= clean($user['phone']) ?>" autocomplete="tel" required>
                                        <span class="form-hint">Contoh: 0812 3456 7890</span>
                                    </div>
                                </div>

                                <button type="submit" name="update_profil" class="btn btn-primary">Simpan perubahan</button>
                            </form>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="account-panel-head">
                            <div>
                                <h3>Ganti password</h3>
                                <p>Gunakan minimal 6 karakter dan jangan bagikan ke siapa pun.</p>
                            </div>
                            <?= svgIcon('lock', 'icon') ?>
                        </div>

                        <div class="account-panel-body">
                            <?php if (!empty($errorsPassword)): ?>
                                <div class="alert alert-warning" style="margin-bottom:18px;">
                                    <?= svgIcon('alert', 'icon') ?>
                                    <div>
                                        <?php foreach ($errorsPassword as $err): ?>
                                            <p><?= clean($err) ?></p>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="profil.php">
                                <div class="form-grid">
                                    <div class="form-group form-full">
                                        <label class="form-label" for="password_lama">Password lama</label>
                                        <div class="password-field">
                                            <input type="password" name="password_lama" id="password_lama" class="form-input" autocomplete="current-password" required>
                                            <button type="button" class="password-toggle" data-password-toggle aria-label="Tampilkan kata sandi" aria-pressed="false">
                                                <span class="pw-show"><?= svgIcon('eye', 'icon') ?></span>
                                                <span class="pw-hide" hidden><?= svgIcon('eye-off', 'icon') ?></span>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="password_baru">Password baru</label>
                                        <div class="password-field">
                                            <input type="password" name="password_baru" id="password_baru" class="form-input" autocomplete="new-password" required>
                                            <button type="button" class="password-toggle" data-password-toggle aria-label="Tampilkan kata sandi" aria-pressed="false">
                                                <span class="pw-show"><?= svgIcon('eye', 'icon') ?></span>
                                                <span class="pw-hide" hidden><?= svgIcon('eye-off', 'icon') ?></span>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="konfirmasi_password_baru">Konfirmasi password baru</label>
                                        <div class="password-field">
                                            <input type="password" name="konfirmasi_password_baru" id="konfirmasi_password_baru" class="form-input" autocomplete="new-password" required>
                                            <button type="button" class="password-toggle" data-password-toggle aria-label="Tampilkan kata sandi" aria-pressed="false">
                                                <span class="pw-show"><?= svgIcon('eye', 'icon') ?></span>
                                                <span class="pw-hide" hidden><?= svgIcon('eye-off', 'icon') ?></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" name="update_password" class="btn btn-outline">Ubah password</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require_once 'includes/footer.php'; ?>
