<?php
/**
 * ADMIN - KELOLA USER
 * List, tambah, edit, hapus user, dan ubah role.
 */
session_start();
require_once '../config/koneksi.php';
require_once '../includes/functions.php';
require_once '../includes/admin_auth.php';

$pageTitle = 'Kelola User';
$adminActive = 'user';

$errors = [];

// =====================================================================
// PROSES AKSI (POST)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = isset($_POST['aksi']) ? $_POST['aksi'] : '';

    // ---------- SIMPAN (TAMBAH / EDIT) ----------
    if ($aksi === 'simpan') {
        $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $email= trim(isset($_POST['email']) ? $_POST['email'] : '');
        $phone= trim(isset($_POST['phone']) ? $_POST['phone'] : '');
        $role = isset($_POST['role']) ? $_POST['role'] : 'user';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        if ($name === '') $errors[] = 'Nama wajib diisi.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email tidak valid.';
        if (!in_array($role, ['user', 'admin'], true)) $errors[] = 'Role tidak valid.';

        // Email unik (kecuali milik dirinya sendiri)
        if (empty($errors)) {
            $stmtCek = $koneksi->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmtCek->execute([$email, $id]);
            if ($stmtCek->fetch()) $errors[] = 'Email sudah dipakai user lain.';
        }

        // Password: wajib kalau user baru
        if ($id === 0 && $password === '') $errors[] = 'Password wajib diisi untuk user baru.';
        if ($password !== '' && strlen($password) < 6) $errors[] = 'Password minimal 6 karakter.';

        if (empty($errors)) {
            if ($id > 0) {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $koneksi->prepare(
                        "UPDATE users SET name=?, email=?, phone=?, role=?, password=? WHERE id=?"
                    );
                    $stmt->execute([$name, $email, $phone, $role, $hash, $id]);
                } else {
                    $stmt = $koneksi->prepare(
                        "UPDATE users SET name=?, email=?, phone=?, role=? WHERE id=?"
                    );
                    $stmt->execute([$name, $email, $phone, $role, $id]);
                }
                setFlashSuccess('User berhasil diperbarui.');
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $koneksi->prepare(
                    "INSERT INTO users (name, email, phone, role, password) VALUES (?,?,?,?,?)"
                );
                $stmt->execute([$name, $email, $phone, $role, $hash]);
                setFlashSuccess('User baru berhasil ditambahkan.');
            }
            redirect('kelola_user.php');
        }
    }

    // ---------- HAPUS ----------
    if ($aksi === 'hapus') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        if ($id === (int) $_SESSION['user_id']) {
            setFlashError('Tidak bisa menghapus akun sendiri.');
        } elseif ($id > 0) {
            // Cek apakah user punya booking -> jangan hapus supaya data historis aman
            $stmtCek = $koneksi->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ?");
            $stmtCek->execute([$id]);
            if ($stmtCek->fetchColumn() > 0) {
                setFlashError('User memiliki data booking. Hapus dibatalkan agar riwayat tetap utuh.');
            } else {
                $stmt = $koneksi->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                setFlashSuccess('User berhasil dihapus.');
            }
        }
        redirect('kelola_user.php');
    }
}

// =====================================================================
// DATA UNTUK TAMPILAN
// =====================================================================
$modeEdit = false;
$editRow  = null;

if (isset($_GET['edit'])) {
    $stmt = $koneksi->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch();
    if ($editRow) $modeEdit = true;
}

// Pencarian + paginasi sederhana
$cari = isset($_GET['cari']) ? trim($_GET['cari']) : '';
$perHalaman = 10;
$halaman = isset($_GET['hal']) ? max(1, (int) $_GET['hal']) : 1;
$offset = ($halaman - 1) * $perHalaman;

$where = '';
$params = [];
if ($cari !== '') {
    $where = "WHERE name LIKE ? OR email LIKE ? OR phone LIKE ?";
    $like = '%' . $cari . '%';
    $params = [$like, $like, $like];
}

$stmtCount = $koneksi->prepare("SELECT COUNT(*) FROM users $where");
$stmtCount->execute($params);
$totalData = $stmtCount->fetchColumn();
$totalHalaman = max(1, (int) ceil($totalData / $perHalaman));

$stmtList = $koneksi->prepare(
    "SELECT * FROM users $where ORDER BY created_at DESC LIMIT $perHalaman OFFSET $offset"
);
$stmtList->execute($params);
$users = $stmtList->fetchAll();

require_once '../includes/admin_header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <h1 style="margin:0;">Kelola User</h1>
        <p style="color:var(--color-text-light);">Total <?= number_format($totalData) ?> user</p>
    </div>
    <a href="kelola_user.php?tambah=1" class="btn btn-primary">+ Tambah User</a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" style="margin-top:16px;">
        <?php foreach ($errors as $e): ?><p><?= clean($e) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($modeEdit || isset($_GET['tambah'])): ?>
    <!-- ============ FORM TAMBAH / EDIT ============ -->
    <div class="card" style="margin-top:24px;">
        <div class="card-body">
            <h3><?= $modeEdit ? 'Edit User' : 'Tambah User Baru' ?></h3>
            <form method="POST" action="kelola_user.php" style="margin-top:16px; max-width:520px;">
                <input type="hidden" name="aksi" value="simpan">
                <input type="hidden" name="id" value="<?= $modeEdit ? (int) $editRow['id'] : 0 ?>">

                <div class="field">
                    <label>Nama</label>
                    <input type="text" name="name" required value="<?= $modeEdit ? clean($editRow['name']) : '' ?>">
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="email" required value="<?= $modeEdit ? clean($editRow['email']) : '' ?>">
                </div>
                <div class="field">
                    <label>No. HP</label>
                    <input type="text" name="phone" value="<?= $modeEdit ? clean($editRow['phone']) : '' ?>">
                </div>
                <div class="field">
                    <label>Role</label>
                    <select name="role">
                        <option value="user" <?= ($modeEdit && $editRow['role'] === 'user') ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= ($modeEdit && $editRow['role'] === 'admin') ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div class="field">
                    <label>Password <?= $modeEdit ? '(kosongkan jika tidak diubah)' : '' ?></label>
                    <input type="text" name="password" <?= $modeEdit ? '' : 'required' ?> placeholder="min. 6 karakter">
                </div>

                <div class="admin-actions">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                    <a href="kelola_user.php" class="btn btn-outline">Batal</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- ============ PENCARIAN ============ -->
<form method="GET" action="kelola_user.php" class="admin-toolbar" style="margin-top:24px;">
    <div class="field form-group" style="margin:0;">
        <input type="text" name="cari" placeholder="Cari nama / email / HP..." value="<?= clean($cari) ?>" style="width:280px;">
    </div>
    <button type="submit" class="btn btn-outline">Cari</button>
    <?php if ($cari !== ''): ?>
        <a href="kelola_user.php" class="btn btn-outline">Reset</a>
    <?php endif; ?>
</form>

<!-- ============ TABEL USER ============ -->
<div class="card">
    <div class="card-body" style="overflow-x:auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama</th>
                    <th>Email</th>
                    <th>HP</th>
                    <th>Role</th>
                    <th>Dibuat</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="7" style="text-align:center; color:var(--color-text-light);">Belum ada user.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= (int) $u['id'] ?></td>
                            <td><strong><?= clean($u['name']) ?></strong></td>
                            <td><?= clean($u['email']) ?></td>
                            <td><?= clean($u['phone']) ?></td>
                            <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-info' : 'badge-neutral' ?>"><?= clean($u['role']) ?></span></td>
                            <td><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                            <td>
                                <div class="admin-actions">
                                    <a href="kelola_user.php?edit=<?= (int) $u['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                                    <form method="POST" action="kelola_user.php" onsubmit="return confirm('Hapus user ini?');" style="display:inline;">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- PAGINASI -->
        <?php if ($totalHalaman > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalHalaman; $i++): ?>
                    <?php if ($i === $halaman): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?hal=<?= $i ?>&cari=<?= urlencode($cari) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
