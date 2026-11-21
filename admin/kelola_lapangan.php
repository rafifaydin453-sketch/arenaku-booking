<?php
/**
 * ADMIN - KELOLA LAPANGAN
 * List, tambah, edit, hapus lapangan (courts).
 */
session_start();
require_once '../config/koneksi.php';
require_once '../includes/functions.php';
require_once '../includes/admin_auth.php';
require_once '../includes/upload.php';

$pageTitle = 'Kelola Lapangan';
$adminActive = 'lapangan';

$errors = [];

// Daftar cabang olahraga untuk dropdown
$sportsList = $koneksi->query("SELECT * FROM sports ORDER BY name")->fetchAll();

// =====================================================================
// PROSES AKSI (POST)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = isset($_POST['aksi']) ? $_POST['aksi'] : '';

    // ---------- SIMPAN ----------
    if ($aksi === 'simpan') {
        $id         = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $sportId    = (int) (isset($_POST['sport_id']) ? $_POST['sport_id'] : 0);
        $name       = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $description= trim(isset($_POST['description']) ? $_POST['description'] : '');
        $location   = trim(isset($_POST['location']) ? $_POST['location'] : '');
        $price      = (float) (isset($_POST['price_per_hour']) ? $_POST['price_per_hour'] : 0);
        $facilities = trim(isset($_POST['facilities']) ? $_POST['facilities'] : '');
        $opening    = isset($_POST['opening_time']) ? $_POST['opening_time'] : '06:00';
        $closing    = isset($_POST['closing_time']) ? $_POST['closing_time'] : '22:00';
        $status     = isset($_POST['status']) ? $_POST['status'] : 'active';

        if ($name === '') $errors[] = 'Nama lapangan wajib diisi.';
        if ($sportId <= 0) $errors[] = 'Cabang olahraga wajib dipilih.';
        if ($price <= 0) $errors[] = 'Harga per jam harus lebih dari 0.';
        if (!in_array($status, ['active', 'inactive'], true)) $errors[] = 'Status tidak valid.';
        if (strtotime($opening) >= strtotime($closing)) $errors[] = 'Jam buka harus lebih awal dari jam tutup.';

        $fotoBaru = null;
        if (empty($errors)) {
            $fotoBaru = uploadFotoLapangan(isset($_FILES['foto']) ? $_FILES['foto'] : null, $errors);
        }

        if (empty($errors)) {
            try {
                if ($id > 0) {
                    $stmtLama = $koneksi->prepare("SELECT image FROM courts WHERE id = ?");
                    $stmtLama->execute([$id]);
                    $fotoLama = (string) $stmtLama->fetchColumn();
                    $fotoSimpan = $fotoBaru !== null ? $fotoBaru : $fotoLama;

                    $stmt = $koneksi->prepare(
                        "UPDATE courts SET sport_id=?, name=?, description=?, location=?, price_per_hour=?,
                                image=?, facilities=?, opening_time=?, closing_time=?, status=? WHERE id=?"
                    );
                    $stmt->execute([$sportId, $name, $description, $location, $price, $fotoSimpan, $facilities, $opening, $closing, $status, $id]);

                    if ($fotoBaru !== null && $fotoLama !== '' && $fotoLama !== $fotoBaru) {
                        hapusFileFotoLapangan($koneksi, $fotoLama, $id);
                    }

                    setFlashSuccess('Lapangan berhasil diperbarui.');
                } else {
                    $fotoSimpan = $fotoBaru !== null ? $fotoBaru : '';

                    $stmt = $koneksi->prepare(
                        "INSERT INTO courts (sport_id, name, description, location, price_per_hour, image, facilities, opening_time, closing_time, status)
                         VALUES (?,?,?,?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([$sportId, $name, $description, $location, $price, $fotoSimpan, $facilities, $opening, $closing, $status]);
                    setFlashSuccess('Lapangan baru berhasil ditambahkan.');
                }
                redirect('kelola_lapangan.php');
            } catch (Throwable $e) {
                if ($fotoBaru !== null) {
                    hapusFileFotoLapangan($koneksi, $fotoBaru, $id);
                }
                $errors[] = 'Gagal menyimpan lapangan. Silakan coba lagi.';
            }
        }
    }

    // ---------- HAPUS ----------
    if ($aksi === 'hapus') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $stmtCek = $koneksi->prepare("SELECT COUNT(*) FROM bookings WHERE court_id = ?");
        $stmtCek->execute([$id]);
        if ($stmtCek->fetchColumn() > 0) {
            setFlashError('Lapangan memiliki data booking. Hapus dibatalkan agar riwayat tetap utuh.');
        } else {
            $stmtFoto = $koneksi->prepare("SELECT image FROM courts WHERE id = ?");
            $stmtFoto->execute([$id]);
            $fotoLama = (string) $stmtFoto->fetchColumn();

            $stmt = $koneksi->prepare("DELETE FROM courts WHERE id = ?");
            $stmt->execute([$id]);

            if ($fotoLama !== '') {
                hapusFileFotoLapangan($koneksi, $fotoLama, $id);
            }

            setFlashSuccess('Lapangan berhasil dihapus.');
        }
        redirect('kelola_lapangan.php');
    }
}

// =====================================================================
// DATA TAMPILAN
// =====================================================================
$modeEdit = false;
$editRow  = null;
$fotoSaatIni = '';
if (isset($_GET['edit'])) {
    $stmt = $koneksi->prepare("SELECT * FROM courts WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch();
    if ($editRow) {
        $modeEdit = true;
        $fotoSaatIni = courtImageUrl($editRow);
    }
}

$courts = $koneksi->query(
    "SELECT courts.*, sports.name AS sport_name
     FROM courts JOIN sports ON courts.sport_id = sports.id
     ORDER BY courts.created_at DESC"
)->fetchAll();

require_once '../includes/admin_header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <h1 style="margin:0;">Kelola Lapangan</h1>
        <p style="color:var(--color-text-light);">Total <?= count($courts) ?> lapangan</p>
    </div>
    <a href="kelola_lapangan.php?tambah=1" class="btn btn-primary">+ Tambah Lapangan</a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" style="margin-top:16px;">
        <?php foreach ($errors as $e): ?><p><?= clean($e) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($modeEdit || isset($_GET['tambah'])): ?>
    <div class="card" style="margin-top:24px;">
        <div class="card-body">
            <h3><?= $modeEdit ? 'Edit Lapangan' : 'Tambah Lapangan Baru' ?></h3>
            <form method="POST" action="kelola_lapangan.php" enctype="multipart/form-data" style="margin-top:16px; max-width:640px;">
                <input type="hidden" name="aksi" value="simpan">
                <input type="hidden" name="id" value="<?= $modeEdit ? (int) $editRow['id'] : 0 ?>">

                <div class="field">
                    <label>Cabang Olahraga</label>
                    <select name="sport_id" required>
                        <option value="">-- pilih --</option>
                        <?php foreach ($sportsList as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= ($modeEdit && (int) $editRow['sport_id'] === (int) $s['id']) ? 'selected' : '' ?>>
                                <?= clean($s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Nama Lapangan</label>
                    <input type="text" name="name" required value="<?= $modeEdit ? clean($editRow['name']) : '' ?>">
                </div>
                <div class="field">
                    <label>Deskripsi</label>
                    <textarea name="description" rows="3"><?= $modeEdit ? clean($editRow['description']) : '' ?></textarea>
                </div>
                <div class="field">
                    <label>Lokasi</label>
                    <input type="text" name="location" value="<?= $modeEdit ? clean($editRow['location']) : '' ?>">
                </div>
                <div class="field">
                    <label>Harga per Jam</label>
                    <input type="number" name="price_per_hour" min="0" step="1000" required value="<?= $modeEdit ? (int) $editRow['price_per_hour'] : '' ?>">
                </div>
                <div class="field">
                    <label>Foto Lapangan</label>
                    <div class="upload-card" data-upload-preview>
                        <div class="upload-preview <?= $fotoSaatIni !== '' ? 'has-image' : '' ?>">
                            <img src="<?= $fotoSaatIni !== '' ? clean($fotoSaatIni) : '' ?>" alt="Pratinjau foto lapangan" <?= $fotoSaatIni === '' ? 'hidden' : '' ?>>
                            <span class="upload-placeholder" <?= $fotoSaatIni !== '' ? 'hidden' : '' ?>>
                                <?= svgIcon('field', 'icon') ?>
                                <small>Belum ada foto</small>
                            </span>
                        </div>
                        <div class="upload-body">
                            <strong class="upload-title"><?= $fotoSaatIni !== '' ? 'Ganti foto lapangan' : 'Unggah foto lapangan' ?></strong>
                            <p class="form-hint">JPG, PNG, atau WEBP. Maksimal 3 MB. Rasio ideal 16:9.</p>
                            <div class="upload-actions">
                                <label class="btn btn-outline btn-sm" for="foto-lapangan"><?= svgIcon('plus', 'icon') ?> Pilih Foto</label>
                                <input class="upload-input" type="file" id="foto-lapangan" name="foto" accept="image/jpeg,image/png,image/webp" data-upload-input>
                                <button type="button" class="btn btn-ghost btn-sm" data-upload-reset hidden>Batalkan</button>
                            </div>
                            <p class="form-error" data-upload-error hidden></p>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label>Fasilitas (pisahkan dengan koma)</label>
                    <input type="text" name="facilities" value="<?= $modeEdit ? clean($editRow['facilities']) : '' ?>" placeholder="Parkir, Toilet, Mushola">
                </div>
                <div style="display:flex; gap:12px;">
                    <div class="field" style="flex:1;">
                        <label>Jam Buka</label>
                        <input type="time" name="opening_time" value="<?= $modeEdit ? substr($editRow['opening_time'], 0, 5) : '06:00' ?>">
                    </div>
                    <div class="field" style="flex:1;">
                        <label>Jam Tutup</label>
                        <input type="time" name="closing_time" value="<?= $modeEdit ? substr($editRow['closing_time'], 0, 5) : '22:00' ?>">
                    </div>
                </div>
                <div class="field">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= ($modeEdit && $editRow['status'] === 'active') ? 'selected' : '' ?>>Aktif</option>
                        <option value="inactive" <?= ($modeEdit && $editRow['status'] === 'inactive') ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </div>

                <div class="admin-actions">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                    <a href="kelola_lapangan.php" class="btn btn-outline">Batal</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card" style="margin-top:24px;">
    <div class="card-body" style="overflow-x:auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Foto</th>
                    <th>Nama</th>
                    <th>Cabang</th>
                    <th>Lokasi</th>
                    <th>Harga/Jam</th>
                    <th>Jam</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($courts)): ?>
                    <tr><td colspan="9" style="text-align:center; color:var(--color-text-light);">Belum ada lapangan.</td></tr>
                <?php else: ?>
                    <?php foreach ($courts as $c): ?>
                        <?php $thumb = courtImageUrl($c); ?>
                        <tr>
                            <td><?= (int) $c['id'] ?></td>
                            <td>
                                <?php if ($thumb !== ''): ?>
                                    <img class="admin-court-thumb" src="<?= clean($thumb) ?>" alt="<?= clean($c['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <span class="admin-court-thumb is-empty"><?= svgIcon('field', 'icon') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= clean($c['name']) ?></strong></td>
                            <td><?= clean($c['sport_name']) ?></td>
                            <td><?= clean($c['location']) ?></td>
                            <td><?= formatRupiah($c['price_per_hour']) ?></td>
                            <td><?= substr($c['opening_time'], 0, 5) ?>-<?= substr($c['closing_time'], 0, 5) ?></td>
                            <td><span class="badge <?= $c['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= $c['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></span></td>
                            <td>
                                <div class="admin-actions">
                                    <a href="kelola_lapangan.php?edit=<?= (int) $c['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                                    <form method="POST" action="kelola_lapangan.php" onsubmit="return confirm('Hapus lapangan ini?');" style="display:inline;">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
