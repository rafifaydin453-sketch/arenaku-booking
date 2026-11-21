<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';

$pageTitle = 'Promo';

$hariIni = date('Y-m-d');
$stmt = $koneksi->prepare(
    "SELECT * FROM promos
     WHERE status = 'aktif'
       AND (tanggal_mulai IS NULL OR tanggal_mulai <= ?)
       AND (tanggal_akhir IS NULL OR tanggal_akhir >= ?)
       AND (kuota IS NULL OR terpakai < kuota)
     ORDER BY id DESC"
);
$stmt->execute([$hariIni, $hariIni]);
$promos = $stmt->fetchAll();

function promoNilaiLabel($promo)
{
    if ($promo['tipe'] === 'persen') {
        return rtrim(rtrim(number_format((float) $promo['nilai'], 1, ',', '.'), '0'), ',') . '%';
    }
    return formatRupiah($promo['nilai']);
}

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<main id="main-content">
    <section class="promo-hero">
        <div class="container">
            <div class="promo-hero-grid">
                <div>
                    <span class="eyebrow">Promo berjalan</span>
                    <h1>Main lebih sering,<br>bayar lebih ringan.</h1>
                    <p>Pakai kode promo saat konfirmasi booking. Potongan langsung dihitung pada total sebelum kamu membayar.</p>
                    <a href="#daftar-promo" class="btn btn-accent">Lihat semua promo <?= svgIcon('arrow-right', 'icon') ?></a>
                </div>

                <?php if (!empty($promos)): ?>
                    <?php $heroPromo = $promos[0]; ?>
                    <div class="promo-hero-ticket">
                        <small>Kode promo</small>
                        <strong><?= clean(promoNilaiLabel($heroPromo)) ?></strong>
                        <span><?= clean($heroPromo['nama']) ?></span>
                        <div class="ticket-dashes"><?= clean($heroPromo['kode']) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="section" id="daftar-promo">
        <div class="container">
            <div class="promo-section-head">
                <div>
                    <span class="section-index">Katalog promo</span>
                    <h2 style="margin-top:12px;">Pilih yang paling menguntungkan.</h2>
                </div>
                <span class="text-muted" style="font-size:0.8rem;"><?= count($promos) ?> promo aktif</span>
            </div>

            <?php if (empty($promos)): ?>
                <div class="empty-state">
                    <span class="empty-state-icon"><?= svgIcon('ticket', 'icon') ?></span>
                    <h3>Belum ada promo aktif</h3>
                    <p>Promo baru akan tampil di sini. Sementara itu, kamu tetap bisa memesan lapangan seperti biasa.</p>
                    <a href="/arenaku/lapangan.php" class="btn btn-primary">Lihat lapangan</a>
                </div>
            <?php else: ?>
                <div class="promo-grid">
                    <?php foreach ($promos as $p): ?>
                        <?php
                            $nilaiLabel = promoNilaiLabel($p);
                            $sisaKuota = $p['kuota'] !== null ? max(0, (int) $p['kuota'] - (int) $p['terpakai']) : null;

                            // Contoh perhitungan potongan (total dibuat minimal sebesar syarat promo)
                            $contohTotal = max(200000, (float) $p['min_booking']);
                            $contohDiskon = $p['tipe'] === 'persen'
                                ? $contohTotal * ((float) $p['nilai'] / 100)
                                : (float) $p['nilai'];
                            if ($p['tipe'] === 'persen' && $p['maks_diskon'] !== null) {
                                $contohDiskon = min($contohDiskon, (float) $p['maks_diskon']);
                            }
                            $contohDiskon = min($contohDiskon, $contohTotal);
                            $contohBayar = $contohTotal - $contohDiskon;
                        ?>
                        <article class="ticket-card">
                            <div class="ticket-stub">
                                <small>Potongan</small>
                                <strong><?= clean($nilaiLabel) ?></strong>
                                <span><?= $p['tipe'] === 'persen' ? 'diskon persen' : 'diskon nominal' ?></span>
                            </div>

                            <div class="ticket-body">
                                <div class="court-card-meta" style="margin-bottom:6px;">
                                    <span class="badge badge-lime">Aktif</span>
                                    <?php if ($p['tanggal_akhir']): ?>
                                        <span class="text-muted" style="font-size:0.7rem;">s/d <?= formatTanggalId($p['tanggal_akhir']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <h3><?= clean($p['nama']) ?></h3>
                                <p><?= clean($p['deskripsi']) ?></p>

                                <div class="ticket-conditions">
                                    <?php if ((float) $p['min_booking'] > 0): ?>
                                        <span><?= svgIcon('check', 'icon') ?> Minimal booking <?= formatRupiah($p['min_booking']) ?></span>
                                    <?php else: ?>
                                        <span><?= svgIcon('check', 'icon') ?> Tanpa minimum booking</span>
                                    <?php endif; ?>
                                    <?php if ($p['maks_diskon'] !== null): ?>
                                        <span><?= svgIcon('check', 'icon') ?> Maksimal potongan <?= formatRupiah($p['maks_diskon']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($sisaKuota !== null): ?>
                                        <span><?= svgIcon('check', 'icon') ?> Sisa kuota <?= $sisaKuota ?> pemakaian</span>
                                    <?php endif; ?>
                                    <span><?= svgIcon('spark', 'icon') ?> Contoh: total <?= formatRupiah($contohTotal) ?> → bayar <?= formatRupiah($contohBayar) ?></span>
                                </div>

                                <div class="ticket-foot">
                                    <div style="display:flex; align-items:center; gap:7px; flex-wrap:wrap;">
                                        <span class="ticket-code"><?= clean($p['kode']) ?></span>
                                        <button type="button" class="copy-btn" data-copy="<?= clean($p['kode']) ?>">
                                            <?= svgIcon('copy', 'icon') ?> <span data-copy-label>Salin</span>
                                        </button>
                                    </div>
                                    <a href="/arenaku/lapangan.php" class="ticket-use">Pakai promo <?= svgIcon('arrow-right', 'icon') ?></a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section-sm">
        <div class="container">
            <div class="section-head">
                <div>
                    <span class="section-index">Cara pakai</span>
                    <h2>Empat langkah memakai kode.</h2>
                </div>
            </div>
            <div class="route-grid">
                <div class="route-step">
                    <span class="route-num">Langkah 01</span>
                    <h3>Pilih lapangan</h3>
                    <p>Cari lapangan dan jadwal yang ingin kamu pesan.</p>
                </div>
                <div class="route-step">
                    <span class="route-num">Langkah 02</span>
                    <h3>Tentukan durasi</h3>
                    <p>Pilih berapa jam kamu akan bermain.</p>
                </div>
                <div class="route-step">
                    <span class="route-num">Langkah 03</span>
                    <h3>Masukkan kode</h3>
                    <p>Tempel kode promo pada halaman konfirmasi booking.</p>
                </div>
                <div class="route-step">
                    <span class="route-num">Langkah 04</span>
                    <h3>Bayar lebih hemat</h3>
                    <p>Total otomatis terpotong sesuai ketentuan promo.</p>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require_once 'includes/footer.php'; ?>
