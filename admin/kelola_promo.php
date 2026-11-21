<?php
/**
 * ADMIN - KELOLA PROMO
 * List, tambah, edit, hapus promo / voucher diskon (promos).
 */
session_start();
require_once '../config/koneksi.php';
require_once '../includes/functions.php';
require_once '../includes/admin_auth.php';

$pageTitle = 'Kelola Promo';
$adminActive = 'promo';

$errors = [];

// =====================================================================
// PROSES AKSI (POST)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = isset($_POST['aksi']) ? $_POST['aksi'] : '';

    // ---------- SIMPAN ----------
    if ($aksi === 'simpan') {
        $id           = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $kode         = strtoupper(trim(isset($_POST['kode']) ? $_POST['kode'] : ''));
        $nama         = trim(isset($_POST['nama']) ? $_POST['nama'] : '');
        $deskripsi    = trim(isset($_POST['deskripsi']) ? $_POST['deskripsi'] : '');
        $tipe         = isset($_POST['tipe']) ? $_POST['tipe'] : 'persen';
        $nilai        = (float) (isset($_POST['nilai']) ? $_POST['nilai'] : 0);
        $minBooking   = (float) (isset($_POST['min_booking']) ? $_POST['min_booking'] : 0);
        $maksDiskon   = trim(isset($_POST['maks_diskon']) ? $_POST['maks_diskon'] : '');
        $kuota        = trim(isset($_POST['kuota']) ? $_POST['kuota'] : '');
        $tanggalMulai = trim(isset($_POST['tanggal_mulai']) ? $_POST['tanggal_mulai'] : '');
        $tanggalAkhir = trim(isset($_POST['tanggal_akhir']) ? $_POST['tanggal_akhir'] : '');
        $status       = isset($_POST['status']) ? $_POST['status'] : 'aktif';

        if ($kode === '') $errors[] = 'Kode promo wajib diisi.';
        if (!preg_match('/^[A-Z0-9]{3,30}$/', $kode)) $errors[] = 'Kode promo hanya huruf/angka, 3-30 karakter.';
        if ($nama === '') $errors[] = 'Nama promo wajib diisi.';
        if (!in_array($tipe, ['persen', 'nominal'], true)) $errors[] = 'Tipe promo tidak valid.';
        if ($nilai <= 0) $errors[] = 'Nilai promo harus lebih dari 0.';
        if ($tipe === 'persen' && $nilai > 100) $errors[] = 'Diskon persen maksimal 100%.';
        if (!in_array($status, ['aktif', 'nonaktif'], true)) $errors[] = 'Status tidak valid.';

        $maksDiskonVal = ($maksDiskon === '') ? null : (float) $maksDiskon;
        $kuotaVal      = ($kuota === '') ? null : (int) $kuota;
        $mulaiVal      = ($tanggalMulai === '') ? null : $tanggalMulai;
        $akhirVal      = ($tanggalAkhir === '') ? null : $tanggalAkhir;

        if ($mulaiVal && $akhirVal && $mulaiVal > $akhirVal) {
            $errors[] = 'Tanggal mulai tidak boleh melebihi tanggal akhir.';
        }

        // Cek kode unik (selain dirinya sendiri)
        if (empty($errors)) {
            $stmtCek = $koneksi->prepare("SELECT id FROM promos WHERE kode = ? AND id != ?");
            $stmtCek->execute([$kode, $id]);
            if ($stmtCek->fetch()) $errors[] = 'Kode promo sudah dipakai.';
        }

        if (empty($errors)) {
            if ($id > 0) {
                $stmt = $koneksi->prepare(
                    "UPDATE promos SET kode=?, nama=?, deskripsi=?, tipe=?, nilai=?, min_booking=?,
                            maks_diskon=?, kuota=?, tanggal_mulai=?, tanggal_akhir=?, status=? WHERE id=?"
                );
                $stmt->execute([$kode, $nama, $deskripsi, $tipe, $nilai, $minBooking, $maksDiskonVal, $kuotaVal, $mulaiVal, $akhirVal, $status, $id]);
                setFlashSuccess('Promo berhasil diperbarui.');
            } else {
                $stmt = $koneksi->prepare(
                    "INSERT INTO promos (kode, nama, deskripsi, tipe, nilai, min_booking, maks_diskon, kuota, tanggal_mulai, tanggal_akhir, status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->execute([$kode, $nama, $deskripsi, $tipe, $nilai, $minBooking, $maksDiskonVal, $kuotaVal, $mulaiVal, $akhirVal, $status]);
                setFlashSuccess('Promo baru berhasil ditambahkan.');
            }
            redirect('kelola_promo.php');
        }
    }

    // ---------- HAPUS ----------
    if ($aksi === 'hapus') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $stmt = $koneksi->prepare("DELETE FROM promos WHERE id = ?");
        $stmt->execute([$id]);
        setFlashSuccess('Promo berhasil dihapus.');
        redirect('kelola_promo.php');
    }
}

// =====================================================================
// DATA TAMPILAN
// =====================================================================
$modeEdit = false;
$editRow  = null;
if (isset($_GET['edit'])) {
    $stmt = $koneksi->prepare("SELECT * FROM promos WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch();
    if ($editRow) $modeEdit = true;
}

$promos = $koneksi->query("SELECT * FROM promos ORDER BY created_at DESC")->fetchAll();

require_once '../includes/admin_header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <h1 style="margin:0;">Kelola Promo</h1>
        <p style="color:var(--color-text-light);">Total <?= count($promos) ?> promo</p>
    </div>
    <a href="kelola_promo.php?tambah=1" class="btn btn-primary">+ Tambah Promo</a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" style="margin-top:16px;">
        <?php foreach ($errors as $e): ?><p><?= clean($e) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($modeEdit || isset($_GET['tambah'])): ?>
    <div class="card" style="margin-top:24px;">
        <div class="card-body">
            <h3><?= $modeEdit ? 'Edit Promo' : 'Tambah Promo Baru' ?></h3>
            <form method="POST" action="kelola_promo.php" style="margin-top:16px; max-width:640px;">
                <input type="hidden" name="aksi" value="simpan">
                <input type="hidden" name="id" value="<?= $modeEdit ? (int) $editRow['id'] : 0 ?>">

                <div class="field">
                    <label>Kode Promo</label>
                    <input type="text" name="kode" required style="text-transform:uppercase;" value="<?= $modeEdit ? clean($editRow['kode']) : '' ?>" placeholder="ARENA20">
                </div>
                <div class="field">
                    <label>Nama Promo</label>
                    <input type="text" name="nama" required value="<?= $modeEdit ? clean($editRow['nama']) : '' ?>">
                </div>
                <div class="field">
                    <label>Deskripsi</label>
                    <textarea name="deskripsi" rows="3"><?= $modeEdit ? clean($editRow['deskripsi']) : '' ?></textarea>
                </div>
                <div style="display:flex; gap:12px;">
                    <div class="field" style="flex:1;">
                        <label>Tipe</label>
                        <select name="tipe">
                            <option value="persen" <?= ($modeEdit && $editRow['tipe'] === 'persen') ? 'selected' : '' ?>>Persen (%)</option>
                            <option value="nominal" <?= ($modeEdit && $editRow['tipe'] === 'nominal') ? 'selected' : '' ?>>Nominal (Rp)</option>
                        </select>
                    </div>
                    <div class="field" style="flex:1;">
                        <label>Nilai</label>
                        <input type="number" name="nilai" min="0" step="1000" required value="<?= $modeEdit ? (int) $editRow['nilai'] : '' ?>">
                    </div>
                </div>
                <div style="display:flex; gap:12px;">
                    <div class="field" style="flex:1;">
                        <label>Minimal Booking (Rp)</label>
                        <input type="number" name="min_booking" min="0" step="1000" value="<?= $modeEdit ? (int) $editRow['min_booking'] : '0' ?>">
                    </div>
                    <div class="field" style="flex:1;">
                        <label>Maks. Diskon (Rp, opsional)</label>
                        <input type="number" name="maks_diskon" min="0" step="1000" value="<?= ($modeEdit && $editRow['maks_diskon'] !== null) ? (int) $editRow['maks_diskon'] : '' ?>" placeholder="kosong = tanpa batas">
                    </div>
                </div>
                <div style="display:flex; gap:12px;">
                    <div class="field" style="flex:1;">
                        <label>Kuota (opsional)</label>
                        <input type="number" name="kuota" min="0" value="<?= ($modeEdit && $editRow['kuota'] !== null) ? (int) $editRow['kuota'] : '' ?>" placeholder="kosong = tak terbatas">
                    </div>
                    <div class="field" style="flex:1;">
                        <label>Status</label>
                        <select name="status">
                            <option value="aktif" <?= ($modeEdit && $editRow['status'] === 'aktif') ? 'selected' : '' ?>>Aktif</option>
                            <option value="nonaktif" <?= ($modeEdit && $editRow['status'] === 'nonaktif') ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex; gap:12px;">
                    <div class="field" style="flex:1;">
                        <label>Tanggal Mulai (opsional)</label>
                        <input type="date" name="tanggal_mulai" value="<?= ($modeEdit && $editRow['tanggal_mulai']) ? clean($editRow['tanggal_mulai']) : '' ?>">
                    </div>
                    <div class="field" style="flex:1;">
                        <label>Tanggal Akhir (opsional)</label>
                        <input type="date" name="tanggal_akhir" value="<?= ($modeEdit && $editRow['tanggal_akhir']) ? clean($editRow['tanggal_akhir']) : '' ?>">
                    </div>
                </div>

                <div class="admin-actions">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                    <a href="kelola_promo.php" class="btn btn-outline">Batal</a>
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
                    <th>Kode</th>
                    <th>Nama</th>
                    <th>Diskon</th>
                    <th>Min. Booking</th>
                    <th>Kuota</th>
                    <th>Periode</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($promos)): ?>
                    <tr><td colspan="9" style="text-align:center; color:var(--color-text-light);">Belum ada promo.</td></tr>
                <?php else: ?>
                    <?php foreach ($promos as $p): ?>
                        <tr>
                            <td><?= (int) $p['id'] ?></td>
                            <td><strong style="font-family:monospace;"><?= clean($p['kode']) ?></strong></td>
                            <td><?= clean($p['nama']) ?></td>
                            <td><?= $p['tipe'] === 'persen' ? (int) $p['nilai'] . '%' : formatRupiah($p['nilai']) ?></td>
                            <td><?= formatRupiah($p['min_booking']) ?></td>
                            <td><?= $p['kuota'] !== null ? (int) $p['terpakai'] . '/' . (int) $p['kuota'] : '∞' ?></td>
                            <td style="font-size:12px;">
                                <?= $p['tanggal_mulai'] ? date('d/m/y', strtotime($p['tanggal_mulai'])) : '-' ?>
                                &ndash;
                                <?= $p['tanggal_akhir'] ? date('d/m/y', strtotime($p['tanggal_akhir'])) : '-' ?>
                            </td>
                            <td><span class="badge <?= $p['status'] === 'aktif' ? 'badge-success' : 'badge-neutral' ?>"><?= clean($p['status']) ?></span></td>
                            <td>
                                <div class="admin-actions">
                                    <a href="kelola_promo.php?edit=<?= (int) $p['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                                    <form method="POST" action="kelola_promo.php" onsubmit="return confirm('Hapus promo ini?');" style="display:inline;">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
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
