<?php
/**
 * Generator QR Code ArenaKu (khusus CLI).
 *
 * Cara pakai:
 *   php tools/generate_qr.php github
 *   php tools/generate_qr.php website
 *   php tools/generate_qr.php all
 *
 * Isi URL terlebih dahulu di tools/qr-config.php.
 * Hasil PNG kualitas tinggi disimpan di folder qr/.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Akses ditolak. Jalankan generator ini dari terminal (CLI).\n");
}

$configFile = __DIR__ . '/qr-config.php';
if (!is_readable($configFile)) {
    fwrite(STDERR, "File tools/qr-config.php tidak ditemukan.\n");
    exit(1);
}

$config = require $configFile;
if (!is_array($config)) {
    fwrite(STDERR, "Format tools/qr-config.php tidak valid.\n");
    exit(1);
}

$targets = [
    'github'  => ['key' => 'github_url',  'file' => 'arenaku-github.png',  'label' => 'Repository GitHub'],
    'website' => ['key' => 'website_url', 'file' => 'arenaku-website.png', 'label' => 'Website ArenaKu'],
];

$pilihan = isset($argv[1]) ? strtolower(trim((string) $argv[1])) : '';
if ($pilihan === '') {
    fwrite(STDERR, "Cara pakai: php tools/generate_qr.php [github|website|all]\n");
    exit(1);
}

if ($pilihan !== 'all' && !isset($targets[$pilihan])) {
    fwrite(STDERR, "Pilihan tidak dikenal: {$pilihan}\n");
    fwrite(STDERR, "Gunakan: github, website, atau all.\n");
    exit(1);
}

/**
 * Validasi URL agar QR tidak berisi alamat palsu/sementara.
 */
function qrValidasiUrl(string $url, string $jenis): array
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [false, 'Format URL tidak valid.'];
    }

    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host   = strtolower((string) ($parts['host'] ?? ''));

    if (!in_array($scheme, ['http', 'https'], true)) {
        return [false, 'URL harus diawali http:// atau https://.'];
    }

    if ($host === '') {
        return [false, 'URL tidak memiliki nama domain.'];
    }

    $hostTerlarang = ['localhost', '127.0.0.1', '::1'];
    if (in_array($host, $hostTerlarang, true)
        || preg_match('/\.(test|local|localhost|invalid|example)$/i', $host)
        || preg_match('/^example\.(com|org|net)$/i', $host)) {
        return [false, 'URL lokal/contoh tidak diizinkan. Gunakan URL hosting asli.'];
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ipPublik = filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($ipPublik === false) {
            return [false, 'Alamat IP privat/lokal tidak diizinkan.'];
        }
    }

    if ($jenis === 'github') {
        if (!in_array($host, ['github.com', 'www.github.com'], true)) {
            return [false, 'URL repository harus memakai domain github.com.'];
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segmen = $path === '' ? [] : explode('/', $path);
        if (count($segmen) < 2 || $segmen[0] === '' || $segmen[1] === '') {
            return [false, 'URL GitHub harus mengarah ke repository (contoh: https://github.com/user/repo).'];
        }
    }

    return [true, ''];
}

$daftar = $pilihan === 'all' ? array_keys($targets) : [$pilihan];
$outputDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'qr';
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Folder qr/ gagal dibuat.\n");
    exit(1);
}

require_once __DIR__ . '/lib/php-qrcode/qrcode.php';

$berhasil = 0;
$dilewati = 0;

foreach ($daftar as $jenis) {
    $meta = $targets[$jenis];
    $url  = trim((string) ($config[$meta['key']] ?? ''));

    if ($url === '') {
        fwrite(STDOUT, "[SKIP] {$meta['label']}: {$meta['key']} belum diisi di tools/qr-config.php.\n");
        $dilewati++;
        continue;
    }

    [$valid, $alasan] = qrValidasiUrl($url, $jenis);
    if (!$valid) {
        fwrite(STDERR, "[GAGAL] {$meta['label']}: {$alasan}\n");
        exit(1);
    }

    $outputFile = $outputDir . DIRECTORY_SEPARATOR . $meta['file'];

    try {
        $qr = new QRCode($url, [
            's'  => 'qrh',
            'w'  => 2000,
            'h'  => 2000,
            'p'  => 4,
            'bc' => 'FFFFFF',
            'fc' => '000000',
        ]);
        $image = $qr->render_image();
        $tersimpan = imagepng($image, $outputFile, 9);
        imagedestroy($image);
    } catch (Throwable $e) {
        fwrite(STDERR, "[GAGAL] {$meta['label']}: " . $e->getMessage() . "\n");
        exit(1);
    }

    if (!$tersimpan || !is_file($outputFile)) {
        fwrite(STDERR, "[GAGAL] {$meta['label']}: PNG gagal disimpan.\n");
        exit(1);
    }

    $info = getimagesize($outputFile);
    $ukuran = $info ? $info[0] . 'x' . $info[1] : 'tidak diketahui';
    $bytes  = filesize($outputFile);

    fwrite(STDOUT, "[OK] {$meta['label']}\n");
    fwrite(STDOUT, "     Isi   : {$url}\n");
    fwrite(STDOUT, "     File  : qr/{$meta['file']}\n");
    fwrite(STDOUT, "     Ukuran: {$ukuran} px, {$bytes} bytes\n");
    $berhasil++;
}

fwrite(STDOUT, "\nSelesai. Dibuat: {$berhasil}, dilewati: {$dilewati}.\n");
if ($berhasil === 0) {
    fwrite(STDOUT, "Isi URL asli di tools/qr-config.php lalu jalankan ulang.\n");
}
