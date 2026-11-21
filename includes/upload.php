<?php

require_once __DIR__ . '/functions.php';

function uploadErrorMessage($code) {
    $messages = [
        UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi batas server.',
        UPLOAD_ERR_FORM_SIZE  => 'Ukuran file melebihi 3 MB.',
        UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian. Coba lagi.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server tidak menemukan folder sementara untuk upload.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menyimpan file upload.',
        UPLOAD_ERR_EXTENSION  => 'Upload dihentikan oleh ekstensi server.',
    ];

    return isset($messages[$code]) ? $messages[$code] : 'Terjadi kesalahan saat mengunggah file.';
}

function uploadFotoLapangan($file, array &$errors) {
    if (!is_array($file) || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $errorCode = (int) $file['error'];
    if ($errorCode !== UPLOAD_ERR_OK) {
        $errors[] = uploadErrorMessage($errorCode);
        return null;
    }

    $tmpPath = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $errors[] = 'File upload tidak valid.';
        return null;
    }

    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size <= 0) {
        $errors[] = 'File foto kosong atau tidak terbaca.';
        return null;
    }
    if ($size > 3 * 1024 * 1024) {
        $errors[] = 'Ukuran foto maksimal 3 MB.';
        return null;
    }

    $originalName = isset($file['name']) ? (string) $file['name'] : '';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        $errors[] = 'Format foto harus JPG, JPEG, PNG, atau WEBP.';
        return null;
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath);

    if (!in_array($mime, $allowedMimes, true)) {
        $errors[] = 'Tipe file tidak diizinkan. Gunakan gambar JPG, PNG, atau WEBP.';
        return null;
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false || empty($imageInfo['mime']) || !in_array($imageInfo['mime'], $allowedMimes, true)) {
        $errors[] = 'File bukan gambar yang valid.';
        return null;
    }

    $extensionMimeMap = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];
    if ($extensionMimeMap[$extension] !== $imageInfo['mime']) {
        $errors[] = 'Isi file tidak sesuai dengan ekstensi filenya.';
        return null;
    }

    $uploadDir = courtUploadDir();
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        $errors[] = 'Folder upload foto belum siap. Hubungi pengelola server.';
        return null;
    }

    $uniqueName = 'lapangan-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $uniqueName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        $errors[] = 'Foto gagal disimpan. Coba unggah ulang.';
        return null;
    }

    @chmod($targetPath, 0644);

    return $uniqueName;
}

function hapusFileFotoLapangan($koneksi, $fileName, $kecualiCourtId = 0) {
    $name = basename(str_replace('\\', '/', (string) $fileName));
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }

    $path = courtUploadDir() . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        return false;
    }

    $stmt = $koneksi->prepare("SELECT COUNT(*) FROM courts WHERE image = ? AND id <> ?");
    $stmt->execute([$name, (int) $kecualiCourtId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return false;
    }

    return @unlink($path);
}
