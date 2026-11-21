<?php
/**
 * Cron/scheduled task ArenaKu:
 * Menandai pengajuan pembatalan yang melewati batas 24 jam sebagai 'expired'.
 *
 * Jalankan dari CLI (Windows Task Scheduler / Laragon Terminal):
 *   php C:\laragon\www\arenaku\cron\expire_cancellations.php
 *
 * Script ini TIDAK membatalkan booking. Booking yang kedaluwarsa tetap
 * aktif seperti status sebelumnya; hanya pengajuannya yang ditutup.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Akses ditolak. Script ini hanya boleh dijalankan dari CLI.\n");
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pembatalan_helper.php';

$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arenaku_expire_cancellations.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Proses kedaluwarsa lain sedang berjalan. Dilewati.\n");
    exit(0);
}

try {
    $jumlah = pembatalanExpireDue($koneksi, 500);
    $sekarang = $koneksi->query('SELECT NOW()')->fetchColumn();
    fwrite(STDOUT, '[' . $sekarang . '] Pengajuan kedaluwarsa diproses: ' . $jumlah . "\n");
} catch (Throwable $e) {
    error_log('[Cron Pembatalan] ' . $e->getMessage());
    fwrite(STDERR, 'Gagal memproses kedaluwarsa: ' . $e->getMessage() . "\n");
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(1);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
