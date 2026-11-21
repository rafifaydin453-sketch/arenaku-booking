# ArenaKu

ArenaKu adalah aplikasi booking lapangan olahraga berbasis **PHP Native dan
MySQL** dengan integrasi **Midtrans Snap** untuk lapangan futsal, badminton, dan
basket. Pengguna dapat melihat jadwal kosong secara real-time, memesan slot per
jam, memakai kode promo, membayar melalui Midtrans, mengunggah bukti pembayaran,
mengajukan pembatalan/refund, dan memberi rating setelah booking selesai. Admin
mengelola lapangan, booking, pembayaran, pembatalan, promo, user, dan laporan.

Project ini dibuat sebagai **tugas sekolah** sekaligus **portofolio pribadi**
dan dapat dijalankan ulang menggunakan Laragon.

## Status Proyek

- Jenis: tugas sekolah dan portofolio pribadi.
- Status: aplikasi pembelajaran, bukan layanan produksi.
- Repository: `arenaku-booking` (public).
- Branch utama: `main`.
- Release: `v1.0.0`.

## Fitur Utama

### Pengguna
- Registrasi, login, logout, dan ubah profil/password.
- Daftar lapangan dengan pencarian, filter jenis olahraga, dan halaman detail.
- Jadwal slot per jam (zona waktu WIB) dan pencegahan double booking.
- Booking lapangan dengan perhitungan harga dan kode promo.
- Pembayaran melalui Midtrans Snap: QRIS, Virtual Account, dan e-wallet.
- Cek status pembayaran dan unggah bukti pembayaran.
- Riwayat booking, detail invoice, dan rating setelah booking selesai.
- Pengajuan pembatalan booking dengan batas waktu 24 jam dan status refund.

### Admin
- Dashboard ringkasan user, lapangan, booking, pembayaran, pembatalan, dan pendapatan.
- Kelola lapangan (tambah, ubah, hapus, upload foto).
- Kelola booking dan detail pembayaran.
- Verifikasi bukti pembayaran (setujui/tolak).
- Kelola pengajuan pembatalan dan status refund.
- Kelola promo/voucher.
- Kelola user.
- Laporan booking dan pembayaran.

### Keamanan
- PDO prepared statement untuk query database.
- Password disimpan dengan `password_hash()` (bcrypt).
- Proteksi CSRF pada form POST.
- Validasi tipe dan ukuran file upload.
- Folder `config/` dilindungi `.htaccess`.
- Folder upload tidak mengizinkan eksekusi file PHP.

## Teknologi

- PHP 8.x (native, tanpa framework)
- MySQL / MariaDB dengan PDO
- HTML, CSS, dan JavaScript
- Apache (Laragon)
- Library resmi `midtrans/midtrans-php` 2.6.2 (sudah tersedia di `vendor/`)
- Composer (opsional, untuk memperbarui dependency)

## Persyaratan Sistem

- Windows 10/11 dengan Laragon (Apache + MySQL + PHP).
- PHP 8.1 atau lebih baru (disarankan PHP 8.3).
- MySQL 5.7+ atau MySQL 8.0 / MariaDB yang kompatibel.
- Ekstensi PHP: `pdo_mysql`, `curl`, `openssl`, `json`, `mbstring`, `fileinfo`.
- Ekstensi `gd` diperlukan hanya untuk generator QR Code.
- Browser modern (Chrome, Edge, Firefox).
- Git (opsional, untuk clone repository).
- Koneksi internet untuk pembayaran Midtrans.

## Struktur Folder

```text
arenaku/
├── admin/                  Halaman admin
├── assets/                 CSS, JavaScript, font, dan gambar
├── config/                 Konfigurasi database, Midtrans, environment
├── cron/                   Script terjadwal (kedaluwarsa pembatalan)
├── database/               SQL instalasi
├── includes/               Helper, header, footer, autentikasi
├── qr/                     Hasil QR Code PNG
├── tools/                  Generator QR Code
├── vendor/                 Library Midtrans
├── *.php                   Halaman utama aplikasi
├── composer.json
├── composer.lock
├── README.md
├── .gitattributes
└── .gitignore
```

Folder `dist/` berisi hasil packaging lokal dan tidak di-track Git. ZIP release
disimpan sebagai asset GitHub Release, bukan di dalam repository.

## Clone Repository

```bash
git clone https://github.com/USERNAME/arenaku-booking.git
cd arenaku-booking
```

Ganti `USERNAME` dengan username GitHub pemilik repository. Jika tidak memakai
Git, unduh ZIP source dari halaman **Releases** `v1.0.0`.

## Instalasi Menggunakan Laragon

> Penting: ekstrak project ke folder bernama **arenaku**.
> Banyak link di aplikasi memakai path `/arenaku/...`, jadi gunakan
> `C:\laragon\www\arenaku` agar tidak terjadi error redirect.

1. Clone repository atau ekstrak file ZIP ke `C:\laragon\www\arenaku`.
2. Jalankan Laragon, lalu klik **Start All** (Apache dan MySQL).
3. Buka phpMyAdmin, Command Prompt/Laragon Terminal, atau PowerShell.
4. Import database dari `database/arenaku_install.sql` (lihat bagian berikut).
5. Periksa konfigurasi database di `config/koneksi.php`.
6. Konfigurasi Midtrans jika ingin memakai pembayaran (lihat bagian Midtrans).
7. Buka salah satu alamat:
   - `http://arenaku.test`
   - `http://localhost/arenaku`

Jika Laragon belum membuat virtual host otomatis, gunakan
`http://localhost/arenaku`.

## Membuat Database dan Import SQL

File SQL yang digunakan: `database/arenaku_install.sql`.

File tersebut berisi skema lengkap dan data awal:
- 1 akun admin dan 3 akun user demo.
- 3 cabang olahraga.
- 6 lapangan.
- 3 promo contoh.
- Tidak berisi data booking/pembayaran pribadi.

### Cara 1: phpMyAdmin

1. Buka `http://localhost/phpmyadmin`.
2. Buat database baru bernama `arenaku` dengan collation `utf8mb4_unicode_ci`
   (atau langsung import karena file SQL juga membuat database jika belum ada).
3. Pilih database `arenaku` -> tab **Import**.
4. Pilih file `database/arenaku_install.sql`.
5. Klik **Go/Import**.
6. Pastikan tabel `users`, `sports`, `courts`, `bookings`, `payments`,
   `promos`, `cancellation_requests`, dan `ratings` sudah terbentuk.

### Cara 2: Command Prompt / Laragon Terminal

Dari folder project:

```bat
mysql -u root < database/arenaku_install.sql
```

Jika MySQL memakai password:

```bat
mysql -u root -p < database/arenaku_install.sql
```

### Cara 3: PowerShell

Dari folder project:

```powershell
Get-Content .\database\arenaku_install.sql -Raw | mysql -u root
```

Jika MySQL memakai password:

```powershell
Get-Content .\database\arenaku_install.sql -Raw | mysql -u root -p
```

Jika `mysql` belum ada di `PATH`, gunakan executable MySQL Laragon:

```powershell
Get-Content .\database\arenaku_install.sql -Raw | & "C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe" -u root
```

Sesuaikan path executable jika versi MySQL Laragon berbeda.

File SQL ini tidak menghapus database/tabel yang sudah ada. Untuk instalasi
bersih, gunakan database kosong bernama `arenaku`. Jika sudah punya database
`arenaku` versi lama, backup lalu hapus/rename database lama sebelum import;
`CREATE TABLE IF NOT EXISTS` tidak menambahkan kolom baru ke tabel yang sudah ada.

## Konfigurasi Koneksi Database

Konfigurasi default ada di `config/koneksi.php`:

```php
'host'   => 'localhost',
'dbname' => 'arenaku',
'user'   => 'root',
'pass'   => '',
```

Default ini cocok untuk Laragon (user `root` tanpa password).

Jika password database berbeda:

1. Salin `config/koneksi.local.example.php` menjadi `config/koneksi.local.php`.
2. Isi `host`, `dbname`, `user`, dan `pass` sesuai MySQL kamu.
3. File `config/koneksi.local.php` sudah masuk `.gitignore`, jadi tidak akan
   ikut ter-upload ke GitHub.

Jangan menulis password database langsung ke file yang akan di-commit.

## Konfigurasi Midtrans

Integrasi Midtrans berada di:
- `config/midtrans.php` — membaca key dan status production.
- `config/midtrans_keys.php` — file rahasia berisi key (tidak disertakan di ZIP).
- `includes/midtrans_helper.php` — pembuatan transaksi Snap dan verifikasi status.
- `midtrans_notification.php` — webhook notifikasi Midtrans.
- `cek_status_pembayaran.php` — pengecekan status server-to-server.
- `pembayaran.php` — halaman pembayaran Snap.

### 1. Buat file key

Salin:

```text
config/midtrans_keys.example.php -> config/midtrans_keys.php
```

Lalu isi key dari dashboard Midtrans.

### 2. Midtrans Sandbox

1. Login ke `https://dashboard.sandbox.midtrans.com/`.
2. Buka **Settings -> Access Keys**.
3. Salin **Server Key** dan **Client Key** ke `config/midtrans_keys.php`.
4. Pastikan:

```php
'is_production' => false,
```

5. Aktifkan channel pembayaran yang diinginkan di dashboard Sandbox
   (QRIS, VA bank, GoPay, ShopeePay, DANA, dan lain-lain).
6. Untuk webhook di localhost, Midtrans tidak bisa mengakses
   `http://localhost`. Gunakan tunnel publik (ngrok/localtunnel) dan daftarkan
   URL berikut di dashboard Sandbox:
   `https://domain-tunnel-kamu/arenaku/midtrans_notification.php`
7. Alternatif tanpa webhook: gunakan tombol **Cek status pembayaran** pada
   halaman pembayaran. Halaman itu mengecek status langsung ke API Midtrans.

### 3. Midtrans Production

1. Login ke `https://dashboard.midtrans.com/`.
2. Ambil **Production Server Key** dan **Client Key**.
3. Isi `config/midtrans_keys.php` dan ubah:

```php
'is_production' => true,
```

4. Daftarkan Notification URL produksi:
   `https://domain-kamu/arenaku/midtrans_notification.php`
5. Pastikan website memakai HTTPS.
6. Jangan pernah commit `config/midtrans_keys.php` ke GitHub.

Catatan: `pembayaran.php` otomatis memilih Snap.js sandbox atau production
berdasarkan nilai `is_production`.

### 4. Jika Midtrans belum dikonfigurasi

Jika `config/midtrans_keys.php` belum ada atau key masih placeholder:
- Halaman pembayaran menampilkan pesan bahwa Midtrans belum aktif.
- Tombol metode pembayaran dinonaktifkan.
- Booking tetap tersimpan, tetapi pembayaran online belum bisa dilakukan.

## Menjalankan Website

1. Pastikan Apache dan MySQL Laragon berjalan.
2. Buka `http://arenaku.test` atau `http://localhost/arenaku`.
3. Login menggunakan akun demo di bawah.
4. Untuk admin, buka `http://arenaku.test/admin/` setelah login sebagai admin.

## Akun Demo

| Role  | Email               | Password  |
|-------|---------------------|-----------|
| Admin | admin@arenaku.com   | admin123  |
| User  | budi@example.com    | user123   |
| User  | siti@example.com    | user123   |
| User  | andi@example.com    | user123   |

Akun demo berasal dari `database/arenaku_install.sql`. Segera ganti password
jika project dipakai di lingkungan nyata.

## Mode Simulasi Pembayaran (Opsional, Development)

Mode simulasi adalah fitur lama untuk pengujian tanpa Midtrans. Mode ini
**tidak aktif secara default** karena `config/environment.php` tidak disertakan
di paket.

Untuk mengaktifkan di development:

1. Salin `config/environment.example.php` menjadi `config/environment.php`.
2. Ubah isinya:

```php
'environment' => 'development',
'simulation_enabled' => true,
```

3. Pastikan `config/midtrans_keys.php` memakai `is_production => false`.
4. Jangan aktifkan mode ini di production.

Jika `config/environment.php` tidak ada atau `simulation_enabled` bernilai
`false`, halaman simulasi akan ditolak (404) dan alur aktif hanya memakai
Midtrans.

## Cron Pembatalan (Opsional)

Script `cron/expire_cancellations.php` menandai pengajuan pembatalan yang
melewati batas 24 jam sebagai `expired`. Script ini tidak membatalkan booking.

Jalankan manual:

```bat
php C:\laragon\www\arenaku\cron\expire_cancellations.php
```

Untuk otomatis, buat task di Windows Task Scheduler setiap 5-10 menit dengan
perintah di atas. Script hanya bisa dijalankan dari CLI.

## QR Code

Generator QR tersedia di `tools/generate_qr.php`. QR dibuat lokal dengan
library PHP (tanpa layanan online), resolusi 2000 x 2000 px, dan error
correction level H.

1. Isi URL asli di `tools/qr-config.php`:

```php
'github_url'  => 'https://github.com/USERNAME/arenaku-booking',
'website_url' => 'https://arenaku.domainmu.com',
```

2. Jalankan:

```bat
php tools/generate_qr.php github
php tools/generate_qr.php website
```

Atau keduanya:

```bat
php tools/generate_qr.php all
```

3. Hasil:
   - `qr/arenaku-github.png`
   - `qr/arenaku-website.png`

Generator menolak URL kosong, localhost, alamat contoh, dan URL sementara.
Karena URL asli belum diisi, QR **belum dibuat** di paket ini.

## Persiapan GitHub

- Repository: `arenaku-booking`
- Branch utama: `main`
- Visibility: public

File `.gitignore` dan `.gitattributes` sudah disiapkan untuk mencegah file
sensitif/runtime ikut ter-upload dan menormalkan line ending:

- `config/midtrans_keys.php` (Server Key/Client Key)
- `config/environment.php` (konfigurasi development)
- `config/koneksi.local.php` (kredensial database lokal)
- `.env` dan file environment lokal
- upload bukti pembayaran `assets/img/bukti/*`
- upload foto lapangan `assets/img/lapangan/*`
- video/berkas upload tak terpakai di `admin/`
- cache, log, file sementara
- hasil packaging `dist/` dan file ZIP
- SQL lama `database/arenaku.sql` dan `database/migrasi_*.sql`
- `test_koneksi.php`, `style-guide.php`, dan `assets/pe` (berkas development)

Untuk repository lokal baru (sebelum push pertama):

```bat
git init -b main
git add .
git diff --cached --name-only
```

Setelah clone, periksa file yang akan di-stage:

```bat
git status
git add .
git diff --cached --name-only
```

Pastikan file rahasia di atas tidak muncul. Jika sebelumnya sudah terlanjur
di-track, keluarkan dari index tanpa menghapus file lokal:

```bat
git rm --cached config/midtrans_keys.php config/environment.php
```

Project ini **tidak memakai file `.env`** untuk konfigurasi runtime. Konfigurasi
memakai file PHP di folder `config/`. File `.env.example` hanya berisi petunjuk
referensi, bukan konfigurasi yang dibaca aplikasi.

Jangan commit atau push sebelum daftar file staged diperiksa.

## Isi Paket dan File yang Dikecualikan

Paket ZIP berisi source code aplikasi, aset, library Midtrans, SQL instalasi,
README, `.gitignore`, dan tools QR.

File berikut sengaja tidak dimasukkan ke ZIP:
- `config/midtrans_keys.php` — berisi key Midtrans asli.
- `config/environment.php` — konfigurasi development lokal.
- `config/koneksi.local.php` — kredensial database lokal (jika ada).
- `assets/img/bukti/*` — bukti pembayaran milik pengujian.
- `admin/20261021-0435-30.7803020.mp4` — berkas video tak terpakai.
- `assets/pe` — file kosong tak terpakai.
- `test_koneksi.php` dan `style-guide.php` — utilitas development.
- `database/arenaku.sql` dan `database/migrasi_*.sql` — sudah digabung ke
  `database/arenaku_install.sql`.
- `dist/` — folder hasil packaging.

ZIP dibuat dari salinan bersih dan tidak diubah. Perubahan README setelah
packaging hanya untuk kebutuhan dokumentasi publikasi GitHub.

## GitHub Release

Rilis **v1.0.0** menyimpan paket source sebagai asset release, bukan di dalam
repository. Asset yang disarankan:

- `ArenaKu-Source-2026-11-20.zip` — source code lengkap (105 file).
- `arenaku_install.sql` — salinan SQL instalasi.
- `README.md` — salinan README saat rilis.
- `LAPORAN-PACKAGING.md` — laporan audit/packaging.
- `CHECKSUMS.txt` — SHA256 ZIP, SQL, dan README.

Gunakan file dari folder `dist/` agar `CHECKSUMS.txt` cocok dengan asset yang
diunggah; README root bisa berubah setelah rilis. ZIP tidak di-commit ke Git
karena merupakan binary dan duplikasi source. Unduh ZIP dari halaman
**Releases** repository. Verifikasi checksum dengan PowerShell:

```powershell
Get-FileHash .\ArenaKu-Source-2026-11-20.zip -Algorithm SHA256
```

## Troubleshooting

### "Koneksi database gagal"
- Pastikan MySQL Laragon berjalan.
- Pastikan database `arenaku` sudah dibuat dan SQL sudah diimport.
- Periksa `config/koneksi.php` atau `config/koneksi.local.php`.
- Pastikan ekstensi `pdo_mysql` aktif.

### "Midtrans belum aktif"
- Pastikan `config/midtrans_keys.php` ada.
- Pastikan Server Key dan Client Key bukan placeholder `XXXX`.
- Pastikan channel pembayaran aktif di dashboard Midtrans.

### "No payment channels available"
- Aktifkan channel pembayaran di dashboard Midtrans sesuai environment
  (Sandbox/Production).
- Pastikan key yang dipakai sesuai environment.

### Pembayaran tidak berubah setelah popup ditutup
- Di localhost, webhook Midtrans tidak bisa masuk. Gunakan tunnel publik atau
  tombol **Cek status pembayaran**.
- Di production, periksa Notification URL di dashboard Midtrans.

### Halaman simulasi 404
- Normal jika `config/environment.php` tidak ada atau
  `simulation_enabled` bernilai `false`.

### Redirect selalu ke `/arenaku/...`
- Pastikan folder project bernama `arenaku` di `C:\laragon\www`.
- Jika ingin nama folder lain, path di aplikasi perlu disesuaikan.

### Upload bukti pembayaran gagal
- Format harus JPG, JPEG, PNG, atau WEBP.
- Ukuran maksimal 3 MB.
- Pastikan folder `assets/img/bukti` dapat ditulis oleh Apache/PHP.

### QR Code gagal dibuat
- Isi URL asli di `tools/qr-config.php`.
- Pastikan ekstensi `gd` aktif.
- Jalankan dari terminal di folder project.

## Lisensi

Repository ini **tidak menyertakan file LICENSE**. Tidak ada lisensi open source
yang diberikan; hak cipta tetap milik pembuat. Project ditampilkan sebagai tugas
sekolah dan portofolio pribadi. Untuk penggunaan di luar tugas/portofolio,
hubungi pemilik repository.

## Catatan

- Jangan mengaktifkan mode simulasi di production.
- Jangan commit key Midtrans atau password database.
- Jangan mengubah folder upload menjadi executable.
- ZIP dan `dist/` tidak di-commit; gunakan GitHub Release.
- Library Midtrans di `vendor/` berasal dari `midtrans/midtrans-php` versi 2.6.2.
