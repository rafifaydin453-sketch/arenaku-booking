-- =========================================================
-- ArenaKu - SQL INSTALASI LENGKAP
-- ---------------------------------------------------------
-- File ini adalah gabungan skema final + data awal (seed).
-- Gunakan file ini untuk instalasi baru di Laragon/phpMyAdmin.
--
-- Cara import:
--   1. Buat database baru bernama `arenaku` (atau biarkan file ini
--      membuatnya otomatis).
--   2. Import file ini melalui phpMyAdmin atau:
--        mysql -u root < database/arenaku_install.sql
--   3. Pastikan config/koneksi.php mengarah ke database `arenaku`.
--
-- Catatan:
--   - File ini TIDAK menghapus database/tabel yang sudah ada.
--   - Untuk instalasi bersih, gunakan database kosong.
--   - Data contoh: 1 admin + 3 user, 3 cabang olahraga, 6 lapangan,
--     3 promo. Tidak ada data booking/pembayaran pribadi.
-- =========================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE DATABASE IF NOT EXISTS arenaku
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE arenaku;

-- =========================================================
-- TABEL: users
-- =========================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABEL: sports
-- =========================================================
CREATE TABLE IF NOT EXISTS sports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABEL: courts
-- =========================================================
CREATE TABLE IF NOT EXISTS courts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sport_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    location VARCHAR(150),
    price_per_hour DECIMAL(10,2) NOT NULL,
    image VARCHAR(255),
    facilities VARCHAR(255),
    opening_time TIME NOT NULL DEFAULT '06:00:00',
    closing_time TIME NOT NULL DEFAULT '22:00:00',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_courts_sport
        FOREIGN KEY (sport_id) REFERENCES sports(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_courts_sport (sport_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABEL: promos
-- =========================================================
CREATE TABLE IF NOT EXISTS promos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode VARCHAR(30) NOT NULL,
    nama VARCHAR(120) NOT NULL,
    deskripsi TEXT,
    tipe ENUM('persen', 'nominal') NOT NULL DEFAULT 'persen',
    nilai DECIMAL(10,2) NOT NULL,
    min_booking DECIMAL(10,2) NOT NULL DEFAULT 0,
    maks_diskon DECIMAL(10,2) NULL,
    kuota INT NULL,
    terpakai INT NOT NULL DEFAULT 0,
    tanggal_mulai DATE NULL,
    tanggal_akhir DATE NULL,
    status ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY kode (kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABEL: bookings
-- =========================================================
CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_code VARCHAR(20) NOT NULL,
    user_id INT NOT NULL,
    court_id INT NOT NULL,
    booking_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    duration INT NOT NULL,
    price_per_hour DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    promo_id INT NULL,
    promo_code VARCHAR(30) NULL,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_after_discount DECIMAL(10,2) NULL,
    booking_status ENUM('pending_payment', 'confirmed', 'completed', 'cancelled')
        NOT NULL DEFAULT 'pending_payment',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bookings_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_bookings_court
        FOREIGN KEY (court_id) REFERENCES courts(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_bookings_promo
        FOREIGN KEY (promo_id) REFERENCES promos(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uniq_booking_code (booking_code),
    INDEX idx_bookings_availability (court_id, booking_date, start_time, booking_status),
    INDEX fk_bookings_promo (promo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABEL: payments
-- =========================================================
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    payment_method ENUM('qris', 'transfer_bank', 'e_wallet', 'transfer_manual') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_status ENUM('unpaid', 'pending', 'paid', 'failed', 'refunded', 'expired')
        NOT NULL DEFAULT 'unpaid',
    is_simulated TINYINT(1) NOT NULL DEFAULT 0,
    payment_proof VARCHAR(255) DEFAULT NULL,
    payment_proof_uploaded_at DATETIME NULL,
    proof_status ENUM('none', 'pending', 'approved', 'rejected')
        NOT NULL DEFAULT 'none',
    rejection_reason VARCHAR(500) NULL,
    verified_by INT NULL,
    verified_at DATETIME NULL,
    paid_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    midtrans_order_id VARCHAR(100) NULL,
    midtrans_transaction_id VARCHAR(100) NULL,
    snap_token TEXT NULL,
    payment_type VARCHAR(50) NULL,
    payment_code VARCHAR(100) NULL,
    bank VARCHAR(20) NULL,
    fraud_status VARCHAR(20) NULL,
    status_detail VARCHAR(50) NULL,
    expire_time DATETIME NULL,
    raw_notification JSON NULL,
    UNIQUE KEY uniq_booking_payment (booking_id),
    UNIQUE KEY uniq_midtrans_order_id (midtrans_order_id),
    INDEX idx_payments_simulated (is_simulated),
    INDEX idx_payments_verified_by (verified_by),
    INDEX idx_payments_proof_status (proof_status),
    CONSTRAINT fk_payments_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payments_verified_by
        FOREIGN KEY (verified_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABEL: payment_settings (legacy, disiapkan untuk kompatibilitas)
-- =========================================================
CREATE TABLE IF NOT EXISTS payment_settings (
    id TINYINT UNSIGNED NOT NULL,
    bank_name VARCHAR(50) NOT NULL DEFAULT '',
    account_number VARCHAR(50) NOT NULL DEFAULT '',
    account_holder VARCHAR(100) NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABEL: payment_verifications (legacy, disiapkan untuk kompatibilitas)
-- =========================================================
CREATE TABLE IF NOT EXISTS payment_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    booking_id INT NOT NULL,
    admin_id INT DEFAULT NULL,
    action ENUM('submitted', 'approved', 'rejected') NOT NULL,
    status_before ENUM('unpaid', 'pending', 'paid', 'failed', 'refunded', 'expired') DEFAULT NULL,
    status_after ENUM('unpaid', 'pending', 'paid', 'failed', 'refunded', 'expired') DEFAULT NULL,
    proof_file VARCHAR(255) DEFAULT NULL,
    rejection_reason VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_verifications_payment (payment_id, created_at),
    INDEX idx_verifications_booking (booking_id),
    INDEX idx_verifications_admin (admin_id),
    CONSTRAINT fk_verifications_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_verifications_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_verifications_admin
        FOREIGN KEY (admin_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABEL: cancellation_requests
-- =========================================================
CREATE TABLE IF NOT EXISTS cancellation_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    active_booking_id INT NULL,
    user_id INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    previous_booking_status ENUM('pending_payment', 'confirmed', 'completed', 'cancelled') NOT NULL,
    decision_status ENUM('pending', 'approved', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deadline_at DATETIME NOT NULL,
    decided_at DATETIME NULL,
    decided_by INT NULL,
    rejection_reason VARCHAR(500) NULL,
    refund_status ENUM('none', 'processing', 'success', 'failed') NOT NULL DEFAULT 'none',
    refund_amount DECIMAL(10,2) NULL,
    refund_key VARCHAR(100) NULL,
    refund_note VARCHAR(255) NULL,
    refund_requested_at DATETIME NULL,
    refund_confirmed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_active_cancellation (active_booking_id),
    INDEX idx_cr_booking (booking_id),
    INDEX idx_cr_user (user_id),
    INDEX idx_cr_status_deadline (decision_status, deadline_at),
    CONSTRAINT fk_cr_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cr_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cr_admin
        FOREIGN KEY (decided_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABEL: ratings
-- =========================================================
CREATE TABLE IF NOT EXISTS ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    court_id INT NOT NULL,
    user_id INT NOT NULL,
    stars TINYINT NOT NULL,
    review TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_booking (booking_id),
    INDEX idx_court (court_id),
    INDEX idx_user (user_id),
    CONSTRAINT fk_rating_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_rating_court
        FOREIGN KEY (court_id) REFERENCES courts(id) ON DELETE CASCADE,
    CONSTRAINT fk_rating_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_stars CHECK (stars BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- SEED DATA
-- =========================================================

-- Akun demo (password sudah di-hash bcrypt):
--   admin@arenaku.com / admin123
--   budi@example.com  / user123
--   siti@example.com  / user123
--   andi@example.com  / user123
INSERT INTO users (id, name, email, phone, password, role) VALUES
(1, 'Admin ArenaKu', 'admin@arenaku.com', '081200000000',
  '$2y$10$g58ks51QrYuuH4KfADoCJORf3Cxyx2hBeyUOQIkv2ZkSkw43GU/MK', 'admin'),
(2, 'Budi Santoso', 'budi@example.com', '081234567890',
  '$2y$10$1qVjosSpV62h9StVuZyI1.yI4rQnBCLfSXnHxlqjDicviLj3tBrDu', 'user'),
(3, 'Siti Aminah', 'siti@example.com', '081234567891',
  '$2y$10$1qVjosSpV62h9StVuZyI1.yI4rQnBCLfSXnHxlqjDicviLj3tBrDu', 'user'),
(4, 'Andi Wijaya', 'andi@example.com', '081234567892',
  '$2y$10$1qVjosSpV62h9StVuZyI1.yI4rQnBCLfSXnHxlqjDicviLj3tBrDu', 'user')
ON DUPLICATE KEY UPDATE
    name = VALUES(name), phone = VALUES(phone), role = VALUES(role);

INSERT INTO sports (id, name, description) VALUES
(1, 'Futsal', 'Lapangan futsal indoor & outdoor'),
(2, 'Badminton', 'Lapangan badminton modern'),
(3, 'Basket', 'Lapangan basket berkualitas')
ON DUPLICATE KEY UPDATE
    name = VALUES(name), description = VALUES(description);

INSERT INTO courts (id, sport_id, name, description, location, price_per_hour, image, facilities, opening_time, closing_time, status) VALUES
(1, 1, 'Arena Futsal Prime', 'Lapangan futsal indoor dengan rumput sintetis berkualitas tinggi.', 'Bekasi, Jawa Barat', 100000, 'Arena Futsal Prime.jpg', 'Toilet, Parkir Luas, Mushola, WiFi', '06:00:00', '23:00:00', 'active'),
(2, 1, 'Arena Futsal Sport', 'Lapangan futsal dengan pencahayaan LED terang, cocok untuk malam hari.', 'Bekasi, Jawa Barat', 120000, 'Arena Futsal Sport.webp', 'Toilet, Parkir Luas, Kantin', '06:00:00', '23:00:00', 'active'),
(3, 2, 'Arena Badminton 01', 'Lapangan badminton indoor dengan lantai vinyl standar kompetisi.', 'Bekasi, Jawa Barat', 60000, 'Arena Badminton 01.jpg', 'Toilet, Parkir, Ruang Ganti', '07:00:00', '22:00:00', 'active'),
(4, 2, 'Arena Badminton 02', 'Lapangan badminton dengan sirkulasi udara baik dan pencahayaan optimal.', 'Bekasi, Jawa Barat', 65000, 'Arena Badminton 02.jpg', 'Toilet, Parkir, WiFi', '07:00:00', '22:00:00', 'active'),
(5, 3, 'Arena Basketball Court', 'Lapangan basket outdoor dengan permukaan aspal halus.', 'Bekasi, Jawa Barat', 80000, 'Arena Basketball Court.jpg', 'Toilet, Parkir Luas', '06:00:00', '21:00:00', 'active'),
(6, 3, 'Arena Basketball Indoor', 'Lapangan basket indoor full AC, cocok untuk turnamen kecil.', 'Bekasi, Jawa Barat', 100000, 'Arena Basketball Indoor.jpg', 'Toilet, Parkir, AC, Kantin', '06:00:00', '21:00:00', 'active')
ON DUPLICATE KEY UPDATE
    sport_id = VALUES(sport_id), name = VALUES(name), description = VALUES(description),
    location = VALUES(location), price_per_hour = VALUES(price_per_hour), image = VALUES(image),
    facilities = VALUES(facilities), opening_time = VALUES(opening_time),
    closing_time = VALUES(closing_time), status = VALUES(status);

INSERT INTO promos (id, kode, nama, deskripsi, tipe, nilai, min_booking, maks_diskon, kuota, tanggal_akhir, status) VALUES
(1, 'ARENA20', 'Diskon 20% Booking Pertama', 'Potongan 20% untuk semua lapangan, maksimal Rp 30.000.', 'persen', 20, 0, 30000, 100, '2026-12-31', 'aktif'),
(2, 'HEMAT25RB', 'Hemat Rp 25.000', 'Potongan langsung Rp 25.000 untuk booking minimal Rp 150.000.', 'nominal', 25000, 150000, NULL, 50, '2026-12-31', 'aktif'),
(3, 'WEEKEND10', 'Promo Akhir Pekan 10%', 'Diskon 10% khusus booking di hari Sabtu & Minggu.', 'persen', 10, 0, 20000, NULL, '2026-12-31', 'aktif')
ON DUPLICATE KEY UPDATE
    nama = VALUES(nama), deskripsi = VALUES(deskripsi), tipe = VALUES(tipe),
    nilai = VALUES(nilai), min_booking = VALUES(min_booking), maks_diskon = VALUES(maks_diskon),
    kuota = VALUES(kuota), tanggal_akhir = VALUES(tanggal_akhir), status = VALUES(status);

-- Selesai.
