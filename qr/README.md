# QR Code ArenaKu

Folder ini menampung hasil QR Code PNG kualitas tinggi (2000 x 2000 px).

QR **belum dibuat** karena URL repository GitHub dan URL hosting belum diisi.
Generator sengaja menolak URL kosong, localhost, alamat contoh, atau URL sementara.

## Cara membuat

1. Buka `tools/qr-config.php`, isi URL asli:
   - `github_url` untuk repository GitHub ArenaKu.
   - `website_url` untuk website ArenaKu yang sudah online (opsional).
2. Jalankan dari terminal di folder project:

   ```bat
   php tools/generate_qr.php github
   php tools/generate_qr.php website
   ```

   Atau buat keduanya sekaligus:

   ```bat
   php tools/generate_qr.php all
   ```

3. Hasil tersimpan di folder ini:
   - `arenaku-github.png`
   - `arenaku-website.png`

Generator memakai library QR lokal (tanpa layanan online) dengan koreksi
error level H dan resolusi 2000 x 2000 px.
