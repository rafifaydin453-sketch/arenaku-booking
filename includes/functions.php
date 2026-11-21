<?php
/**
 * File kumpulan fungsi bantuan (helper functions) ArenaKu
 */

// Fungsi untuk redirect ke halaman lain lalu langsung berhenti eksekusi
function redirect($url) {
    header("Location: $url");
    exit;
}

// Fungsi untuk membersihkan output teks dari user sebelum ditampilkan ke HTML
// (mencegah XSS - Cross Site Scripting)
function clean($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Cek apakah user (siapapun rolenya) sedang login
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Cek apakah yang login sekarang adalah admin
function isAdmin() {
    return isLoggedIn() && $_SESSION['role'] === 'admin';
}

// Format angka ke format Rupiah, contoh: 100000 -> "Rp 100.000"
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Format tanggal dengan nama bulan Bahasa Indonesia.
// $panjang = 'pendek' -> 28 Okt 2026, 'panjang' -> 28 Oktober 2026
function formatTanggalId($tanggal, $panjang = 'pendek') {
    if (empty($tanggal)) {
        return '-';
    }

    $time = strtotime($tanggal);
    if ($time === false) {
        return '-';
    }

    $bulanPendek  = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $bulanPanjang = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $bulan = $panjang === 'panjang' ? $bulanPanjang : $bulanPendek;

    return date('j', $time) . ' ' . $bulan[(int) date('n', $time) - 1] . ' ' . date('Y', $time);
}

// Nama hari dalam Bahasa Indonesia.
function formatHariId($tanggal) {
    $time = strtotime((string) $tanggal);
    if ($time === false) {
        return '-';
    }

    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    return $hari[(int) date('w', $time)];
}

// Inisial nama untuk avatar.
function getInitials($nama) {
    $nama = trim((string) $nama);
    if ($nama === '') {
        return 'AK';
    }

    $kata = preg_split('/\s+/', $nama);
    $inisial = strtoupper(substr($kata[0], 0, 1));
    if (count($kata) > 1) {
        $inisial .= strtoupper(substr(end($kata), 0, 1));
    }

    return $inisial;
}

// Simpan pesan sekali-tampil (flash) ke session, ditampilkan di admin_header.php
function setFlashSuccess($pesan) {
    $_SESSION['flash_success'] = $pesan;
}

function setFlashError($pesan) {
    $_SESSION['flash_error'] = $pesan;
}

// ---------------------------------------------------------------------------
// PROTEKSI CSRF (Cross-Site Request Forgery)
// Token tersimpan di SESSION server, bukan di query string / cookie JS.
// Halaman yang punya form POST menyisipkan token; sisi server memverifikasi
// dengan hash_equals sebelum memproses.
// ---------------------------------------------------------------------------
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Hidden input siap tempel ke dalam <form>
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . clean(csrfToken()) . '">';
}

// Verifikasi token dari POST. true = sah.
function csrfVerifikasi($token) {
    if (empty($_SESSION['csrf_token']) || !is_string($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Terjemahkan label status booking ke teks + kelas badge
function labelStatusBooking($status) {
    $map = [
        'pending_payment' => ['Menunggu Pembayaran', 'badge-warning'],
        'confirmed'       => ['Terkonfirmasi', 'badge-success'],
        'completed'       => ['Selesai', 'badge-info'],
        'cancelled'       => ['Dibatalkan', 'badge-danger'],
    ];
    return isset($map[$status]) ? $map[$status] : [$status, 'badge-neutral'];
}

// Terjemahkan label status pembayaran ke teks + kelas badge.
// Transfer manual memakai label yang berbeda karena melibatkan verifikasi admin.
function labelStatusPembayaran($status) {
    $map = [
        'unpaid'   => ['Belum Bayar', 'badge-neutral'],
        'pending'  => ['Menunggu', 'badge-warning'],
        'paid'     => ['Lunas', 'badge-success'],
        'failed'   => ['Gagal', 'badge-danger'],
        'expired'  => ['Kedaluwarsa', 'badge-danger'],
        'refunded' => ['Refund', 'badge-info'],
    ];
    return isset($map[$status]) ? $map[$status] : [$status, 'badge-neutral'];
}

// Terjemahkan label status verifikasi bukti pembayaran (keputusan admin).
function labelStatusVerifikasi($proofStatus) {
    $map = [
        'none'     => ['Belum Ada Bukti', 'badge-neutral'],
        'pending'  => ['Menunggu Verifikasi', 'badge-warning'],
        'approved' => ['Terverifikasi', 'badge-success'],
        'rejected' => ['Ditolak', 'badge-danger'],
    ];
    return isset($map[$proofStatus]) ? $map[$proofStatus] : ['-', 'badge-neutral'];
}

/**
 * Validasi kode promo dan hitung potongannya untuk total booking tertentu.
 *
 * @param PDO    $koneksi   koneksi database
 * @param string $kode      kode promo yang diinput user
 * @param float  $total     total harga booking sebelum diskon
 * @return array ['ok' => bool, 'promo' => array|null, 'diskon' => float, 'error' => string|null]
 */
function validasiPromo($koneksi, $kode, $total) {
    $kode = strtoupper(trim($kode));

    if ($kode === '') {
        return ['ok' => false, 'promo' => null, 'diskon' => 0, 'error' => 'Kode promo kosong.'];
    }

    $stmt = $koneksi->prepare("SELECT * FROM promos WHERE kode = ? LIMIT 1");
    $stmt->execute([$kode]);
    $promo = $stmt->fetch();

    if (!$promo) {
        return ['ok' => false, 'promo' => null, 'diskon' => 0, 'error' => 'Kode promo tidak ditemukan.'];
    }

    if ($promo['status'] !== 'aktif') {
        return ['ok' => false, 'promo' => null, 'diskon' => 0, 'error' => 'Kode promo sudah tidak aktif.'];
    }

    $hariIni = date('Y-m-d');
    if ($promo['tanggal_mulai'] && $hariIni < $promo['tanggal_mulai']) {
        return ['ok' => false, 'promo' => null, 'diskon' => 0, 'error' => 'Promo belum berlaku.'];
    }
    if ($promo['tanggal_akhir'] && $hariIni > $promo['tanggal_akhir']) {
        return ['ok' => false, 'promo' => null, 'diskon' => 0, 'error' => 'Masa berlaku promo sudah habis.'];
    }

    if ($promo['kuota'] !== null && (int) $promo['terpakai'] >= (int) $promo['kuota']) {
        return ['ok' => false, 'promo' => null, 'diskon' => 0, 'error' => 'Kuota promo sudah habis.'];
    }

    if ($total < (float) $promo['min_booking']) {
        return [
            'ok' => false, 'promo' => null, 'diskon' => 0,
            'error' => 'Minimal total booking ' . formatRupiah($promo['min_booking']) . ' untuk memakai promo ini.',
        ];
    }

    // Hitung diskon
    if ($promo['tipe'] === 'persen') {
        $diskon = $total * ((float) $promo['nilai'] / 100);
        if ($promo['maks_diskon'] !== null) {
            $diskon = min($diskon, (float) $promo['maks_diskon']);
        }
    } else {
        $diskon = (float) $promo['nilai'];
    }

    // Diskon tidak boleh melebihi total
    $diskon = min($diskon, $total);

    return ['ok' => true, 'promo' => $promo, 'diskon' => $diskon, 'error' => null];
}

/**
 * Zona waktu resmi aplikasi (seluruh jadwal & booking memakai WIB).
 */
function zonaWaktuAplikasi() {
    return new DateTimeZone('Asia/Jakarta');
}

/**
 * Waktu "sekarang" dalam zona waktu Asia/Jakarta.
 */
function waktuSekarangJakarta() {
    return new DateTimeImmutable('now', zonaWaktuAplikasi());
}

/**
 * Konversi string 'HH:MM(:SS)' menjadi jumlah menit sejak tengah malam.
 */
function menitDariJam($jam) {
    $jam = substr((string) $jam, 0, 5);
    $bagian = explode(':', $jam);
    return ((int) $bagian[0]) * 60 + (int) $bagian[1];
}

/**
 * Menghasilkan daftar slot jam 1 jam-an antara jam buka dan jam tutup,
 * lalu menandai slot mana yang sudah terisi booking aktif (pending_payment/confirmed).
 *
 * Slot pada tanggal HARI INI yang waktu mulainya sudah terlewat, atau sedang
 * berlangsung, otomatis ditandai tidak tersedia (zona waktu Asia/Jakarta).
 * Slot tanggal mendatang mengikuti aturan booking normal.
 *
 * @param string $openTime      contoh: '06:00:00'
 * @param string $closeTime     contoh: '22:00:00'
 * @param array  $bookedRanges  array of ['start_time' => ..., 'end_time' => ...]
 * @param string|null $tanggal  'Y-m-d' tanggal jadwal; null = hari ini
 * @return array daftar slot: [['start' => '08:00', 'end' => '09:00', 'available' => true, 'state' => 'tersedia'], ...]
 *         state: 'tersedia' | 'terisi' | 'berlangsung' | 'terlewat'
 */
function generateTimeSlots($openTime, $closeTime, $bookedRanges, $tanggal = null) {
    $slots = [];

    $sekarang     = waktuSekarangJakarta();
    $tanggalSlot  = ($tanggal !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $tanggal))
        ? $tanggal
        : $sekarang->format('Y-m-d');

    $tanggalHariIni  = $sekarang->format('Y-m-d');
    $isHariIni       = ($tanggalSlot === $tanggalHariIni);
    $isTanggalLampau = ($tanggalSlot < $tanggalHariIni);

    // Menit "sekarang" sejak tengah malam (WIB), hanya relevan untuk tanggal hari ini
    $nowMenit = (int) $sekarang->format('G') * 60 + (int) $sekarang->format('i');

    $bukaMenit  = menitDariJam($openTime);
    $tutupMenit = menitDariJam($closeTime);

    for ($m = $bukaMenit; $m < $tutupMenit; $m += 60) {
        $endM    = $m + 60; // slot selalu 1 jam
        $isAvailable = true;
        $state       = 'tersedia';

        // Cek apakah slot ini bentrok dengan salah satu booking yang sudah ada
        // Bentrok kalau: slot_start < booking_end DAN booking_start < slot_end
        foreach ($bookedRanges as $booked) {
            $bookedStart = menitDariJam($booked['start_time']);
            $bookedEnd   = menitDariJam($booked['end_time']);

            if ($m < $bookedEnd && $bookedStart < $endM) {
                $isAvailable = false;
                $state       = 'terisi';
                break;
            }
        }

        // Slot tanggal lampau & slot hari ini yang sudah mulai tidak bisa dibooking
        if ($isAvailable) {
            if ($isTanggalLampau) {
                $isAvailable = false;
                $state       = 'terlewat';
            } elseif ($isHariIni && $m <= $nowMenit) {
                $isAvailable = false;
                $state       = ($nowMenit < $endM) ? 'berlangsung' : 'terlewat';
            }
        }

        $slots[] = [
            'start'     => sprintf('%02d:%02d', intdiv($m, 60), $m % 60),
            'end'       => sprintf('%02d:%02d', intdiv($endM, 60), $endM % 60),
            'available' => $isAvailable,
            'state'     => $state,
        ];
    }

    return $slots;
}

/**
 * Ambil ringkasan rating sebuah lapangan.
 * Mengembalikan ['avg' => float, 'count' => int].
 */
function getRatingLapangan($koneksi, $courtId) {
    $stmt = $koneksi->prepare(
        "SELECT AVG(stars) AS avg_stars, COUNT(*) AS total FROM ratings WHERE court_id = ?"
    );
    $stmt->execute([$courtId]);
    $row = $stmt->fetch();

    return [
        'avg'   => ($row['avg_stars'] !== null) ? round((float) $row['avg_stars'], 1) : 0.0,
        'count' => (int) ($row['total'] ?? 0),
    ];
}

/**
 * Ambil rating yang sudah diberikan untuk sebuah booking.
 * Mengembalikan baris rating atau null.
 */
function getRatingBooking($koneksi, $bookingId) {
    $stmt = $koneksi->prepare("SELECT * FROM ratings WHERE booking_id = ? LIMIT 1");
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Cek apakah sebuah booking boleh & sudah dapat dirating.
 * Syarat: status 'completed' dan belum pernah dirating.
 */
function bisaDirating($koneksi, $bookingId, $bookingStatus) {
    if ($bookingStatus !== 'completed') {
        return false;
    }
    return getRatingBooking($koneksi, $bookingId) === null;
}

/**
 * Render bintang rating sebagai teks (mis. "★★★★☆").
 */
function bintangRating($avg) {
    $full = (int) floor($avg);
    $out  = str_repeat('★', max(0, min(5, $full)));
    $out .= str_repeat('☆', max(0, 5 - $full));
    return $out;
}

function appBaseUrl() {
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $appRoot = realpath(__DIR__ . '/..');
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : '';

    if ($appRoot && $docRoot) {
        $appRoot = rtrim(str_replace('\\', '/', $appRoot), '/');
        $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');

        if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
            $base = rtrim(substr($appRoot, strlen($docRoot)), '/');
            return $base;
        }
    }

    $base = '/arenaku';
    return $base;
}

function assetUrl($path) {
    $path = ltrim(str_replace('\\', '/', (string) $path), '/');
    $segments = array_map('rawurlencode', explode('/', $path));
    return appBaseUrl() . '/assets/' . implode('/', $segments);
}

function courtUploadDir() {
    return dirname(__DIR__) . '/assets/img/lapangan';
}

function courtImageDirs() {
    $root = dirname(__DIR__);
    return [
        'img/lapangan' => $root . '/assets/img/lapangan',
        'img'          => $root . '/assets/img',
        'images'       => $root . '/assets/images',
    ];
}

function courtImageAliasMap() {
    return [
        'futsal-prime.jpg' => 'Arena Futsal Prime.jpg',
        'futsal-sport.jpg' => 'Arena Futsal Sport.webp',
        'badminton-01.jpg' => 'Arena Badminton 01.jpg',
        'badminton-02.jpg' => 'Arena Badminton 02.jpg',
        'basket-court.jpg' => 'Arena Basketball Court.jpg',
        'basket-indoor.jpg'=> 'Arena Basketball Indoor.jpg',
    ];
}

function resolveCourtImage($image) {
    static $cache = [];

    $image = trim((string) $image);
    if ($image === '' || preg_match('#^(?:https?:)?//#i', $image) || stripos($image, 'data:') === 0) {
        return null;
    }

    $name = basename(str_replace('\\', '/', $image));
    if ($name === '' || $name === '.' || $name === '..' || !preg_match('/\.(jpe?g|png|webp)$/i', $name)) {
        return null;
    }

    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }

    $candidates = [$name];
    $aliases = courtImageAliasMap();
    foreach ($aliases as $from => $to) {
        if (strcasecmp($from, $name) === 0) {
            $candidates[] = $to;
            break;
        }
    }

    foreach ($candidates as $candidate) {
        foreach (courtImageDirs() as $relativeDir => $dir) {
            $path = $dir . DIRECTORY_SEPARATOR . $candidate;
            if (is_file($path)) {
                return $cache[$name] = ['path' => $path, 'relative' => $relativeDir . '/' . $candidate];
            }
        }

        foreach (courtImageDirs() as $relativeDir => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = @scandir($dir);
            if (!$files) {
                continue;
            }
            foreach ($files as $file) {
                if (strcasecmp($file, $candidate) === 0 && is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                    return $cache[$name] = ['path' => $dir . DIRECTORY_SEPARATOR . $file, 'relative' => $relativeDir . '/' . $file];
                }
            }
        }
    }

    return $cache[$name] = null;
}

function courtImageUrl($court, $fallback = '') {
    $image = is_array($court) ? (isset($court['image']) ? $court['image'] : '') : (string) $court;
    $resolved = resolveCourtImage($image);

    if ($resolved !== null) {
        return assetUrl($resolved['relative']);
    }

    return $fallback !== '' ? $fallback : '';
}

function courtImageFilePath($court) {
    $image = is_array($court) ? (isset($court['image']) ? $court['image'] : '') : (string) $court;
    $resolved = resolveCourtImage($image);
    return $resolved !== null ? $resolved['path'] : null;
}

function courtImageFallbackUrl() {
    return assetUrl('img/fallback-lapangan.svg');
}

function sportImageUrl($sport) {
    $image = is_array($sport) && isset($sport['sport_image']) ? $sport['sport_image'] : '';
    $resolved = resolveCourtImage($image);
    return $resolved !== null ? assetUrl($resolved['relative']) : '';
}