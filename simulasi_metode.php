<?php
/**
 * HALAMAN SIMULASI PEMBAYARAN PER METODE (development/testing only)
 * -----------------------------------------------------------------
 * Menampilkan tampilan pembayaran PALSU yang jelas-jelas simulasi:
 *   - QRIS      : kotak "QR SIMULASI" (tren tidak dapat discan, BUKAN QRIS asli)
 *   - TRANSFER  : nomor Virtual Account dummy berprefix "SIM"
 *   - E-WALLET  : instruksi dummy per aplikasi (GoPay/ShopeePay/DANA)
 *
 * Pengguna melakukan pembayaran simulasi sesuai metode, mengunggah gambar
 * bukti pembayaran, lalu menekan "Kirim Bukti Pembayaran". POST dikirim ke
 * simulasi_pembayaran.php -> bukti disimpan dan status menjadi 'pending'
 * (Menunggu Keputusan Admin). Booking TIDAK langsung dianggap berhasil.
 *
 * Halaman ini menolak dibuka kalau mode simulasi tidak aktif.
 */

session_start();
require_once 'config/koneksi.php';
require_once 'includes/simulasi_helper.php';

// ===== GUARD ENVIRONMENT =====
// Ditaruh terlebih dahulu: tanpa konfigurasi development, halaman simulasi
// tidak dibuka sama sekali (dilempar ke beranda), bahkan sebelum cek login.
if (!simulasiAktif()) {
    redirect('/arenaku/index.php');
}

require_once 'includes/functions.php';
require_once 'includes/auth.php';           // WAJIB LOGIN

$pageTitle = 'Pembayaran (Simulasi)';

// ===== PARAMETRE =====
$bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
$metode    = isset($_GET['metode']) ? $_GET['metode'] : '';
$channel   = isset($_GET['channel']) ? strtolower(trim($_GET['channel'])) : '';

$metodeValid  = ['qris', 'transfer_bank', 'e_wallet'];
$channelValid = array_keys(simulasiEwalletProviders());

if ($bookingId <= 0 || !in_array($metode, $metodeValid, true)) {
    die('Permintaan simulasi tidak valid.');
}
if (!in_array($channel, $channelValid, true)) {
    $channel = '';
}

// ===== AMBIL BOOKING + PAYMENT MILIK USER =====
$stmt = $koneksi->prepare(
    "SELECT bookings.*, courts.name AS court_name, courts.location,
            payments.id AS payment_id, payments.payment_status, payments.amount,
            payments.midtrans_order_id, payments.is_simulated,
            payments.payment_proof, payments.payment_proof_uploaded_at
     FROM bookings
     JOIN courts ON bookings.court_id = courts.id
     JOIN payments ON payments.booking_id = bookings.id
     WHERE bookings.id = ? AND bookings.user_id = ?"
);
$stmt->execute([$bookingId, $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking) {
    die('Booking tidak ditemukan atau bukan milik kamu.');
}

// Booking harus masih menunggu pembayaran dan belum lunas
if ($booking['payment_status'] === 'paid' || $booking['booking_status'] !== 'pending_payment') {
    redirect('/arenaku/detail_booking.php?id=' . $bookingId);
}

// Bukti sudah terkirim dan sedang menunggu admin -> jangan tampilkan form ulang.
if ($booking['payment_status'] === 'pending'
    && !empty($booking['payment_proof'])
    && !empty($booking['is_simulated'])) {
    redirect('/arenaku/hasil_pembayaran.php?booking_id=' . $bookingId);
}

// Provider e-wallet wajib dipilih lebih dulu sebelum lanjut ke halaman simulasi.
if ($metode === 'e_wallet' && $channel === '') {
    redirect('/arenaku/simulasi_ewallet.php?booking_id=' . $bookingId);
}

// Nominal konsisten dari DATABASE (sumber kebenaran), bukan dari URL
$totalBayar  = (float) ($booking['total_after_discount'] !== null
    ? $booking['total_after_discount']
    : $booking['total_price']);

// Nomor VA dummy (prefix SIM, tidak bisa dibayar sungguhan di bank manapun)
$vaDummy = 'SIM8077' . str_pad((string) $booking['payment_id'], 6, '0', STR_PAD_LEFT);
$idTransaksiDummy = 'SIM-EW-' . str_pad((string) $booking['payment_id'], 6, '0', STR_PAD_LEFT);

$channelLabel = [];
foreach (simulasiEwalletProviders() as $providerKey => $provider) {
    $channelLabel[$providerKey] = $provider['label'];
}

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<style>
/* Gaya spesifik halaman simulasi - dipisah agar tidak menyentuh style.css */
.sim-qr {
    width: 200px; height: 200px; margin: 0 auto;
    border: 3px dashed var(--color-text-muted, #9CA3AF);
    border-radius: 12px;
    background: repeating-linear-gradient(45deg, #F3F4F6 0 10px, #FFFFFF 10px 20px);
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    font-weight: 800; color: #6B7280; text-align: center;
    user-select: none;
}
.sim-qr .qr-title { font-size: 26px; letter-spacing: 2px; }
.sim-qr .qr-sub   { font-size: 11px; font-weight: 700; margin-top: 4px; color: #9CA3AF; }
.sim-banner {
    border: 2px dashed #F59E0B;
    background: var(--color-warning-light, #FEF3C7);
    color: #92400E;
    padding: 14px 18px; border-radius: var(--radius-md, 10px);
    font-weight: 700; font-size: 14.5px; text-align: center;
}
.sim-kode {
    font-family: ui-monospace, Consolas, monospace;
    font-size: 22px; font-weight: 800; letter-spacing: 2px;
    background: var(--color-bg-muted, #F3F4F6);
    padding: 8px 14px; border-radius: 8px; display: inline-block;
}
.sim-upload {
    display: grid;
    grid-template-columns: 170px minmax(0, 1fr);
    gap: 16px;
    align-items: center;
    padding: 14px;
    border: 1px dashed var(--line-strong);
    border-radius: var(--radius-md);
    background: var(--paper-2);
    text-align: left;
}
.sim-upload-preview {
    position: relative;
    display: grid;
    place-items: center;
    width: 100%;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    background: var(--white);
    color: var(--muted);
}
.sim-upload-preview img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: var(--white);
}
.sim-upload-placeholder {
    display: grid;
    justify-items: center;
    gap: 5px;
    padding: 12px;
    text-align: center;
}
.sim-upload-placeholder .icon {
    width: 26px;
    height: 26px;
    color: var(--forest);
    opacity: 0.5;
}
.sim-upload-placeholder small {
    font-size: 0.7rem;
    font-weight: 600;
}
.sim-upload-title {
    display: block;
    font-size: 0.9rem;
    color: var(--ink);
}
.sim-upload-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}
.sim-upload-input {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
@media (max-width: 560px) {
    .sim-upload {
        grid-template-columns: 1fr;
    }
    .sim-upload-preview {
        max-width: 220px;
    }
}
</style>

<section class="section">
    <div class="container" style="max-width:640px;">

        <!-- ===== STEPPER (sama dgn pembayaran.php) ===== -->
        <div class="stepper">
            <div class="stepper-item done">
                <span class="step-dot">1</span>
                <p>Lapangan</p>
            </div>
            <div class="stepper-item done">
                <span class="step-dot">2</span>
                <p>Data</p>
            </div>
            <div class="stepper-item current">
                <span class="step-dot">3</span>
                <p>Pembayaran</p>
            </div>
            <div class="stepper-item">
                <span class="step-dot">4</span>
                <p>Selesai</p>
            </div>
        </div>

        <!-- ===== BANNER WAJIB: MODE SIMULASI ===== -->
        <div class="sim-banner" style="margin-top:20px;">
            ⚠️ MODE SIMULASI - TIDAK ADA UANG SUNGGUHAN
            <p style="font-weight:500; font-size:13px; margin-top:6px;">
                Ini masih lingkungan pengujian. Tidak ada QR sungguhan, tidak ada transfer,
                dan tidak ada transaksi ke Midtrans. Booking dikonfirmasi setelah admin menerima
                bukti pembayaran.
            </p>
        </div>

        <?php if ($booking['midtrans_order_id'] && $booking['payment_status'] === 'pending'): ?>
            <div class="alert alert-info" style="margin-top:12px; font-size:13px;">
                ℹ️ Booking ini juga punya transaksi Midtrans yang masih menunggu. Simulasi tidak
                dirujuk ke Midtrans sama sekali; jalur Midtrans asli tetap bekerja normal.
            </div>
        <?php endif; ?>

        <div class="page-head" style="justify-content:center; text-align:center; margin-top:16px;">
            <div>
                <span class="eyebrow">Simulasi Pembayaran</span>
                <h2 style="margin-top:12px;">
                    <?php if ($metode === 'qris'): ?>
                        📱 QRIS (Simulasi)
                    <?php elseif ($metode === 'transfer_bank'): ?>
                        🏦 Virtual Account (Simulasi)
                    <?php else: ?>
                        <?= clean($channelLabel[$channel]) ?> (Simulasi)
                    <?php endif; ?>
                </h2>
                <p style="margin-top:8px;"><span class="badge badge-warning">Menunggu Bukti Pembayaran</span></p>
            </div>
        </div>

        <div class="card card-soft" style="margin-top:16px;">
            <div class="card-body" style="text-align:center;">

                <?php if ($metode === 'qris'): ?>
                    <!-- ===== QR DUMMY: jelas-jelas bukan QR yang bisa discan ===== -->
                    <div class="sim-qr">
                        <div class="qr-title">QR SIMULASI</div>
                        <div class="qr-sub">ILUSTRASI - tidak dapat discan</div>
                    </div>
                    <p style="font-size:13px; color:var(--color-text-light); margin-top:12px;">
                        Kotak ini <strong>bukan</strong> kode QRIS sungguhan dan sengaja tidak bisa discan.
                        Setelah melakukan pembayaran simulasi, unggah gambar bukti pembayaran di bawah
                        untuk diperiksa admin.
                    </p>

                <?php elseif ($metode === 'transfer_bank'): ?>
                    <!-- ===== VA DUMMY ===== -->
                    <p style="font-size:14px;">Transfer ke Virtual Account berikut <em>(nomor palsu)</em>:</p>
                    <p style="margin-top:8px;">
                        <span class="sim-kode">SIM 8077 <?= substr($vaDummy, 7) ?></span>
                    </p>
                    <div class="info-block" style="margin-top:16px; text-align:left;">
                        <div class="info-row"><span>Bank</span><span>SIM-BCA (dummy)</span></div>
                        <div class="info-row"><span>a/n</span><span>SIMULASI ARENAKU</span></div>
                        <div class="info-row"><span>Nominal</span><span class="text-price"><?= formatRupiah($totalBayar) ?></span></div>
                    </div>
                    <p style="font-size:13px; color:var(--color-text-light); margin-top:12px;">
                        Nomor berawalan <code>SIM</code> tidak terhubung ke bank manapun. Tidak perlu
                        menyalakan aplikasi bank. Setelah transfer simulasi, unggah bukti pembayaran
                        di bawah untuk diperiksa admin.
                    </p>

                <?php else: ?>
                    <!-- ===== E-WALLET DUMMY ===== -->
                    <div style="display:flex; justify-content:center;">
                        <img src="<?= clean(simulasiEwalletLogoUrl($channel)) ?>" alt="Logo <?= clean($channelLabel[$channel]) ?>"
                             style="display:block; height:56px; width:auto;">
                    </div>
                    <p style="font-size:14px; margin-top:8px;">
                        Bayar dengan <strong><?= clean($channelLabel[$channel]) ?></strong> &mdash; instruksi dummy.
                    </p>
                    <div class="info-block" style="margin-top:12px; text-align:left;">
                        <div class="info-row"><span>Aplikasi</span><span><?= clean($channelLabel[$channel]) ?> (simulasi)</span></div>
                        <div class="info-row"><span>ID Transaksi</span><span><code><?= clean($idTransaksiDummy) ?></code></span></div>
                        <div class="info-row"><span>Nominal</span><span class="text-price"><?= formatRupiah($totalBayar) ?></span></div>
                    </div>
                    <ul style="text-align:left; font-size:13.5px; color:var(--color-text-light); margin-top:12px; padding-left:20px;">
                        <li>Tidak ada aplikasi <?= clean($channelLabel[$channel]) ?> yang dibuka.</li>
                        <li>Tidak ada notification push yang datang.</li>
                        <li>Setelah itu unggah gambar bukti pembayaran di bawah untuk diperiksa admin.</li>
                    </ul>
                <?php endif; ?>

            </div>
        </div>

        <!-- ===== RINGKASAN (konsisten dgn checkout & hasil) ===== -->
        <div class="card card-soft" style="margin-top:16px;">
            <div class="card-body">
                <h4>Ringkasan Pesanan</h4>
                <div class="info-block" style="margin-top:12px;">
                    <div class="info-row"><span>Kode</span><span><?= clean($booking['booking_code']) ?></span></div>
                    <div class="info-row"><span>Lapangan</span><span><?= clean($booking['court_name']) ?></span></div>
                    <div class="info-row"><span>Tanggal</span><span><?= date('d F Y', strtotime($booking['booking_date'])) ?></span></div>
                    <div class="info-row"><span>Jam</span><span><?= date('H:i', strtotime($booking['start_time'])) ?> - <?= date('H:i', strtotime($booking['end_time'])) ?></span></div>
                    <div class="info-row"><span>Durasi</span><span><?= $booking['duration'] ?> jam x <?= formatRupiah($booking['price_per_hour']) ?></span></div>
                    <div class="info-row"><span>Total</span><span class="text-price" style="font-size:18px;"><?= formatRupiah($totalBayar) ?></span></div>
                    <div class="info-row"><span>Metode</span><span>
                        <?php if ($metode === 'qris'): ?>QRIS (Simulasi)
                        <?php elseif ($metode === 'transfer_bank'): ?>Virtual Account (Simulasi)
                        <?php else: ?><?= clean($channelLabel[$channel]) ?> (Simulasi)
                        <?php endif; ?>
                    </span></div>
                </div>
            </div>
        </div>

        <form id="formBukti" enctype="multipart/form-data" style="margin-top:16px;" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
            <input type="hidden" name="metode" value="<?= clean($metode) ?>">
            <input type="hidden" name="channel" value="<?= clean($metode === 'e_wallet' ? $channel : '') ?>">

            <?php if ($booking['payment_status'] === 'failed' && !empty($booking['payment_proof'])): ?>
                <div class="alert alert-danger" style="margin-bottom:12px;">
                    Bukti sebelumnya ditolak admin. Periksa kembali nominal/tujuan pembayaran, lalu unggah bukti baru.
                </div>
            <?php endif; ?>

            <div class="sim-upload">
                <div class="sim-upload-preview">
                    <img id="buktiPreview" alt="Pratinjau bukti pembayaran" hidden>
                    <span class="sim-upload-placeholder" id="buktiPlaceholder">
                        <?= svgIcon('receipt', 'icon') ?>
                        <small>Belum ada bukti dipilih</small>
                    </span>
                </div>
                <div>
                    <strong class="sim-upload-title">Unggah bukti pembayaran</strong>
                    <p class="form-hint">JPG, JPEG, atau PNG. Maksimal 3 MB. Pastikan nominal dan tujuan pembayaran terlihat jelas.</p>
                    <div class="sim-upload-actions">
                        <label class="btn btn-outline btn-sm" for="buktiPembayaran"><?= svgIcon('plus', 'icon') ?> Pilih Gambar</label>
                        <input class="sim-upload-input" type="file" id="buktiPembayaran" name="bukti"
                               accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
                        <button type="button" class="btn btn-ghost btn-sm" id="btnGantiBukti" hidden>Ganti Gambar</button>
                    </div>
                    <p class="form-error" id="buktiError" hidden></p>
                </div>
            </div>

            <button type="submit" id="btnKirimBukti" class="btn btn-primary btn-block" style="margin-top:16px;" disabled>
                Kirim Bukti Pembayaran
            </button>
            <p style="font-size:12px; color:var(--color-text-light); text-align:center; margin-top:8px;">
                Bukti akan diperiksa admin. Status berubah menjadi <strong>Menunggu Keputusan Admin</strong>.
            </p>
        </form>

        <div id="simGagal" class="alert alert-danger" style="display:none; margin-top:12px;"></div>

        <div style="display:flex; gap:12px; margin-top:12px;">
            <a href="pembayaran.php?booking_id=<?= $bookingId ?>" class="btn btn-outline btn-block">Kembali ke Pilih Metode</a>
            <a href="detail_booking.php?id=<?= $bookingId ?>" class="btn btn-outline btn-block">Detail Booking</a>
        </div>
    </div>
</section>

<script>
(function () {
    var form = document.getElementById('formBukti');
    var input = document.getElementById('buktiPembayaran');
    var preview = document.getElementById('buktiPreview');
    var placeholder = document.getElementById('buktiPlaceholder');
    var btnGanti = document.getElementById('btnGantiBukti');
    var btn = document.getElementById('btnKirimBukti');
    var error = document.getElementById('buktiError');
    var gagal = document.getElementById('simGagal');
    var objectUrl = '';
    var teksAsli = btn.innerText;

    function showError(pesan) {
        error.textContent = pesan || '';
        error.hidden = !pesan;
    }

    function resetPreview() {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = '';
        }
        preview.removeAttribute('src');
        preview.hidden = true;
        placeholder.hidden = false;
        btnGanti.hidden = true;
    }

    function validasiFile(file) {
        if (!file) {
            return 'Unggah gambar bukti pembayaran terlebih dahulu.';
        }
        if (!/\.(jpe?g|png)$/i.test(file.name) || !/^image\/(jpeg|png)$/i.test(file.type)) {
            return 'Format gambar harus JPG, JPEG, atau PNG.';
        }
        if (file.size > 3 * 1024 * 1024) {
            return 'Ukuran gambar maksimal 3 MB.';
        }
        return '';
    }

    input.addEventListener('change', function () {
        showError('');
        gagal.style.display = 'none';

        var file = input.files && input.files[0];
        var pesan = validasiFile(file);

        if (pesan) {
            input.value = '';
            btn.disabled = true;
            resetPreview();
            showError(pesan);
            return;
        }

        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
        }
        objectUrl = URL.createObjectURL(file);
        preview.src = objectUrl;
        preview.hidden = false;
        placeholder.hidden = true;
        btnGanti.hidden = false;
        btn.disabled = false;
    });

    btnGanti.addEventListener('click', function () {
        input.click();
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var file = input.files && input.files[0];
        var pesan = validasiFile(file);
        if (pesan) {
            showError(pesan);
            btn.disabled = true;
            return;
        }

        // Loading state + cegah klik berulang saat upload berjalan
        btn.disabled = true;
        teksAsli = btn.innerText;
        btn.innerText = 'Mengirim bukti...';
        gagal.style.display = 'none';

        fetch('simulasi_pembayaran.php', { method: 'POST', body: new FormData(form) })
            .then(function (r) { return r.json().catch(function () { throw new Error('Respon tidak valid'); }); })
            .then(function (data) {
                if (data.ok && data.redirect) {
                    window.location.href = data.redirect;
                    return; // biarkan tombol tetap disabled sampai pindah halaman
                }
                gagal.innerHTML = '';
                gagal.appendChild(document.createTextNode(data.error || 'Bukti pembayaran gagal dikirim.'));
                gagal.style.display = 'block';
                btn.disabled = false;
                btn.innerText = teksAsli;
            })
            .catch(function (err) {
                gagal.innerHTML = '';
                gagal.appendChild(document.createTextNode(
                    (err && err.message === 'Respon tidak valid')
                        ? 'Server menolak permintaan simulasi (mode simulasi tidak aktif).'
                        : 'Kesalahan jaringan. Coba lagi.'
                ));
                gagal.style.display = 'block';
                btn.disabled = false;
                btn.innerText = teksAsli;
            });
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
