<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';

$errors = [];
$pageTitle = 'Daftar';
$hideSiteFooter = true;

if (isLoggedIn()) {
    redirect('/arenaku/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $phone    = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    // ===== VALIDASI SERVER-SIDE =====
    if (empty($name) || empty($email) || empty($phone) || empty($password)) {
        $errors[] = 'Semua field wajib diisi.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password minimal 6 karakter.';
    }

    if ($password !== $confirm) {
        $errors[] = 'Konfirmasi password tidak cocok.';
    }

    // Batas panjang harus sama dengan lebar kolom database
    // (name/email 100, phone 20) agar tidak memicu "Data too long".
    if (mb_strlen($name) > 100) {
        $errors[] = 'Nama maksimal 100 karakter.';
    }
    if (mb_strlen($email) > 100) {
        $errors[] = 'Email maksimal 100 karakter.';
    }
    if (mb_strlen($phone) > 20) {
        $errors[] = 'Nomor telepon maksimal 20 karakter.';
    }

    if (empty($errors)) {
        $stmt = $koneksi->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'Email sudah terdaftar. Gunakan email lain.';
        }
    }

    if (empty($errors)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $koneksi->prepare(
            "INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, 'user')"
        );
        $stmt->execute([$name, $email, $phone, $hashedPassword]);

        // Langsung login-kan user setelah register berhasil.
        $_SESSION['user_id'] = $koneksi->lastInsertId();
        $_SESSION['name']    = $name;
        $_SESSION['role']    = 'user';

        redirect('/arenaku/index.php');
    }
}

require_once 'includes/header.php';
?>

<main id="main-content" class="auth-shell">
    <aside class="auth-poster">
        <div><?= brandLogo('/arenaku/index.php', true) ?></div>
        <?= courtDiagram() ?>
        <div class="auth-poster-copy">
            <span class="eyebrow">Gabung ArenaKu</span>
            <h1>Daftar sekali, main kapan saja.</h1>
            <p>Satu akun untuk semua lapangan futsal, badminton, dan basket di sekitarmu.</p>
            <div class="auth-benefits">
                <span><?= svgIcon('shield', 'icon') ?> Pembayaran aman</span>
                <span><?= svgIcon('clock', 'icon') ?> Riwayat booking rapi</span>
                <span><?= svgIcon('spark', 'icon') ?> Promo eksklusif</span>
            </div>
        </div>
    </aside>

    <div class="auth-main">
        <div class="auth-box" style="max-width:440px;">
            <div class="auth-mobile-brand"><?= brandLogo('/arenaku/index.php') ?></div>

            <div class="auth-head">
                <span class="eyebrow">Akun baru</span>
                <h2>Buat akun ArenaKu</h2>
                <p>Isi data di bawah untuk mulai booking lapangan.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" style="margin-top:20px;">
                    <?= svgIcon('alert', 'icon') ?>
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <p><?= clean($err) ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php" class="auth-form">
                <div class="form-group">
                    <label class="form-label" for="name">Nama Lengkap</label>
                    <input type="text" name="name" id="name" class="form-input" value="<?= isset($name) ? clean($name) : '' ?>" placeholder="Nama kamu" autocomplete="name" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" name="email" id="email" class="form-input" value="<?= isset($email) ? clean($email) : '' ?>" placeholder="nama@email.com" autocomplete="email" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="phone">Nomor HP</label>
                    <input type="text" name="phone" id="phone" class="form-input" value="<?= isset($phone) ? clean($phone) : '' ?>" placeholder="08xx xxxx xxxx" autocomplete="tel" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="password-field">
                        <input type="password" name="password" id="password" class="form-input" placeholder="Minimal 6 karakter" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" data-password-toggle aria-label="Tampilkan kata sandi" aria-pressed="false">
                            <span class="pw-show"><?= svgIcon('eye', 'icon') ?></span>
                            <span class="pw-hide" hidden><?= svgIcon('eye-off', 'icon') ?></span>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">Konfirmasi Password</label>
                    <div class="password-field">
                        <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder="Ulangi password" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" data-password-toggle aria-label="Tampilkan kata sandi" aria-pressed="false">
                            <span class="pw-show"><?= svgIcon('eye', 'icon') ?></span>
                            <span class="pw-hide" hidden><?= svgIcon('eye-off', 'icon') ?></span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg btn-block">Daftar</button>
            </form>

            <p class="auth-switch">Sudah punya akun? <a href="login.php">Masuk di sini</a></p>
            <a href="/arenaku/index.php" class="auth-back"><?= svgIcon('arrow-left', 'icon') ?> Kembali ke beranda</a>
        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>
