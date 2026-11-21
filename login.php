<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';

$errors = [];
$pageTitle = 'Masuk';
$hideSiteFooter = true;

if (isLoggedIn()) {
    redirect('/arenaku/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $errors[] = 'Email dan password wajib diisi.';
    }

    if (empty($errors)) {
        $stmt = $koneksi->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['role']    = $user['role'];

            if ($user['role'] === 'admin') {
                redirect('/arenaku/admin/index.php');
            }
            redirect('/arenaku/index.php');
        } else {
            // Pesan digeneralisasi agar email terdaftar tidak bisa ditebak.
            $errors[] = 'Email atau password salah.';
        }
    }
}

require_once 'includes/header.php';
?>

<main id="main-content" class="auth-shell">
    <aside class="auth-poster">
        <div><?= brandLogo('/arenaku/index.php', true) ?></div>
        <?= courtDiagram() ?>
        <div class="auth-poster-copy">
            <span class="eyebrow">Selamat datang kembali</span>
            <h1>Lapangan sudah siap. Tinggal kamu yang datang.</h1>
            <p>Masuk untuk melihat jadwal, melanjutkan pembayaran, dan mengecek riwayat booking-mu.</p>
            <div class="auth-benefits">
                <span><?= svgIcon('shield', 'icon') ?> Pembayaran aman</span>
                <span><?= svgIcon('calendar', 'icon') ?> Jadwal real-time</span>
                <span><?= svgIcon('zap', 'icon') ?> Tanpa antre</span>
            </div>
        </div>
    </aside>

    <div class="auth-main">
        <div class="auth-box">
            <div class="auth-mobile-brand"><?= brandLogo('/arenaku/index.php') ?></div>

            <div class="auth-head">
                <span class="eyebrow">Akun ArenaKu</span>
                <h2>Masuk ke akunmu</h2>
                <p>Gunakan email dan password yang kamu daftarkan.</p>
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

            <form method="POST" action="login.php" class="auth-form">
                <div class="form-group">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" name="email" id="email" class="form-input" value="<?= isset($email) ? clean($email) : '' ?>" placeholder="nama@email.com" autocomplete="email" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="password-field">
                        <input type="password" name="password" id="password" class="form-input" placeholder="Masukkan password" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" data-password-toggle aria-label="Tampilkan kata sandi" aria-pressed="false">
                            <span class="pw-show"><?= svgIcon('eye', 'icon') ?></span>
                            <span class="pw-hide" hidden><?= svgIcon('eye-off', 'icon') ?></span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg btn-block">Masuk</button>
            </form>

            <p class="auth-switch">Belum punya akun? <a href="register.php">Daftar di sini</a></p>
            <a href="/arenaku/index.php" class="auth-back"><?= svgIcon('arrow-left', 'icon') ?> Kembali ke beranda</a>
        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>
