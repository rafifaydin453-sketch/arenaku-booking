<?php
session_start();
require_once 'config/koneksi.php';
require_once 'includes/functions.php';

$pageTitle = 'Tentang Kami';
$pageDescription = 'Kenali ArenaKu — platform booking lapangan olahraga di Bekasi yang menghubungkan pemain dengan lapangan terbaik di sekitarnya.';

$totalLapangan = (int) $koneksi->query("SELECT COUNT(*) FROM courts WHERE status = 'active'")->fetchColumn();
$totalBooking  = (int) $koneksi->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$totalUser     = (int) $koneksi->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();

require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<main id="main-content">
    <section class="about-hero">
        <?= courtDiagram() ?>
        <div class="container">
            <div class="about-hero-inner">
                <span class="eyebrow">Tentang ArenaKu</span>
                <h1>Membuat booking lapangan semudah klik.</h1>
                <p>ArenaKu menghubungkan pemain dengan lapangan olahraga terbaik di sekitarnya — cepat, transparan, dan tanpa ribet.</p>

                <div class="about-stats">
                    <div class="about-stat">
                        <strong><?= $totalLapangan ?>+</strong>
                        <span>Lapangan aktif</span>
                    </div>
                    <div class="about-stat">
                        <strong><?= number_format($totalBooking, 0, ',', '.') ?></strong>
                        <span>Total booking</span>
                    </div>
                    <div class="about-stat">
                        <strong><?= number_format($totalUser, 0, ',', '.') ?>+</strong>
                        <span>Pelanggan terdaftar</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="story-grid">
                <div class="story-copy">
                    <span class="section-index">Cerita kami</span>
                    <h2 style="margin-top:14px;">Berawal dari satu masalah sederhana.</h2>
                    <p>ArenaKu lahir dari pengalaman susahnya mencari dan memesan lapangan olahraga yang jelas ketersediaannya. Kami ingin mengubah itu — memberi akses ke jadwal real-time, harga transparan, dan pembayaran yang aman dalam satu tempat.</p>
                    <p>Mulai dari futsal, badminton, hingga basket, semua lapangan yang terdaftar sudah kami verifikasi kondisi dan fasilitasnya.</p>
                </div>

                <div class="story-card">
                    <?= courtDiagram() ?>
                    <small>Prinsip kami</small>
                    <blockquote>“Jadwal yang jelas, harga yang jujur, dan pembayaran yang aman — tanpa perlu tanya sana-sini.”</blockquote>
                    <p>Setiap keputusan produk berangkat dari satu pertanyaan: apakah ini memudahkan pemain untuk segera bermain?</p>
                </div>
            </div>
        </div>
    </section>

    <section class="section section-alt">
        <div class="container">
            <div class="story-grid">
                <div>
                    <span class="section-index">Visi</span>
                    <h2 style="margin-top:14px;">Olahraga tanpa hambatan.</h2>
                    <p class="lede" style="margin-top:16px; max-width:520px;">Menjadi platform booking lapangan olahraga nomor satu yang membuat siapa pun bisa berolahraga tanpa hambatan.</p>
                </div>
                <div>
                    <span class="section-index">Misi</span>
                    <h2 style="margin-top:14px;">Cepat, transparan, aman.</h2>
                    <p class="lede" style="margin-top:16px; max-width:520px;">Menyediakan pengalaman booking yang cepat, transparan, dan aman, serta mendukung pengelola lapangan lokal untuk tumbuh bersama.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="section-head">
                <div>
                    <span class="section-index">Nilai yang kami pegang</span>
                    <h2>Tiga hal yang tidak kami kompromikan.</h2>
                </div>
            </div>

            <div class="value-list">
                <div class="value-item">
                    <span class="value-num">01</span>
                    <div>
                        <h3>Cepat</h3>
                        <p>Booking selesai dalam hitungan menit, kapan pun. Slot yang sudah dipesan langsung tertutup agar tidak ada jadwal bentrok.</p>
                    </div>
                </div>
                <div class="value-item">
                    <span class="value-num">02</span>
                    <div>
                        <h3>Aman</h3>
                        <p>Pembayaran diproses lewat Midtrans. ArenaKu tidak menyimpan data kartu atau PIN kamu.</p>
                    </div>
                </div>
                <div class="value-item">
                    <span class="value-num">03</span>
                    <div>
                        <h3>Terpercaya</h3>
                        <p>Lapangan dan pengelola yang terdaftar sudah diverifikasi kondisi serta fasilitasnya sebelum tampil di katalog.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section section-alt" id="kontak">
        <div class="container">
            <div class="contact-section">
                <div>
                    <span class="section-index">Kontak</span>
                    <h2 style="margin-top:14px;">Ada pertanyaan atau ingin bekerja sama?</h2>
                    <p class="lede" style="margin-top:14px; max-width:480px;">Kami siap membantu. Hubungi tim ArenaKu melalui salah satu kanal berikut.</p>

                    <div class="contact-list">
                        <div class="contact-item">
                            <span class="contact-icon"><?= svgIcon('mail', 'icon') ?></span>
                            <div>
                                <small>Email</small>
                                <strong><a href="mailto:rafifaydin453@gmail.com">rafifaydin453@gmail.com</a></strong>
                            </div>
                        </div>
                        <div class="contact-item">
                            <span class="contact-icon"><?= svgIcon('phone', 'icon') ?></span>
                            <div>
                                <small>Telepon / WhatsApp</small>
                                <strong><a href="https://wa.me/6285283019449" target="_blank" rel="noopener">085283019449</a></strong>
                            </div>
                        </div>
                        <div class="contact-item">
                            <span class="contact-icon"><?= svgIcon('pin', 'icon') ?></span>
                            <div>
                                <small>Alamat</small>
                                <strong>Bekasi, Jawa Barat</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="contact-cta">
                    <h3>Siap main?</h3>
                    <p>Pilih lapangan, tentukan jadwal, dan amankan slotmu sebelum diambil pemain lain.</p>
                    <a href="/arenaku/lapangan.php" class="btn btn-primary">Mulai booking</a>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require_once 'includes/footer.php'; ?>
