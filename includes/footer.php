<?php if (empty($hideSiteFooter)): ?>
<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <?= brandLogo('/arenaku/index.php', true) ?>
                <p>Temukan lapangan futsal, badminton, dan basket terbaik di sekitarmu. Cek jadwal real-time, pesan slot, dan bayar dengan aman dalam satu tempat.</p>
            </div>

            <div>
                <p class="footer-title">Jelajahi</p>
                <div class="footer-links">
                    <a href="/arenaku/lapangan.php">Semua Lapangan</a>
                    <a href="/arenaku/lapangan.php?jenis=1">Futsal</a>
                    <a href="/arenaku/lapangan.php?jenis=2">Badminton</a>
                    <a href="/arenaku/lapangan.php?jenis=3">Basket</a>
                </div>
            </div>

            <div>
                <p class="footer-title">ArenaKu</p>
                <div class="footer-links">
                    <a href="/arenaku/tentang_kami.php">Tentang Kami</a>
                    <a href="/arenaku/promo.php">Promo</a>
                    <a href="/arenaku/booking_saya.php">Booking Saya</a>
                    <a href="/arenaku/profil.php">Profil</a>
                </div>
            </div>

            <div>
                <p class="footer-title">Kontak</p>
                <div class="footer-links">
                    <span class="footer-contact-item"><?= svgIcon('pin', 'icon') ?> Bekasi, Jawa Barat</span>
                    <a class="footer-contact-item" href="https://wa.me/6285283019449" target="_blank" rel="noopener"><?= svgIcon('phone', 'icon') ?> 085283019449</a>
                    <a class="footer-contact-item" href="mailto:rafifaydin453@gmail.com"><?= svgIcon('mail', 'icon') ?> rafifaydin453@gmail.com</a>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <span>&copy; <?= date('Y') ?> ArenaKu. Project sekolah — bukan produk komersial.</span>
            <span class="footer-pay"><?= svgIcon('shield', 'icon') ?> Pembayaran aman via Midtrans</span>
        </div>
    </div>
</footer>
<?php endif; ?>
</body>
</html>
