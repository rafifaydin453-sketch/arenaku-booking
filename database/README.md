# Database ArenaKu

Gunakan **`arenaku_install.sql`** untuk instalasi baru. File ini adalah skema
lengkap final yang sudah menggabungkan seluruh migrasi (Midtrans, promo,
pembatalan/refund, rating, verifikasi bukti, dan simulasi).

## Import

### phpMyAdmin
1. Buka phpMyAdmin.
2. Pilih database `arenaku` (buat jika belum ada).
3. Tab **Import** -> pilih `arenaku_install.sql` -> **Go**.

### Terminal
Dari folder project:

```bat
mysql -u root < database/arenaku_install.sql
```

## Isi Seed
- 1 admin: `admin@arenaku.com` / `admin123`
- 3 user: `budi@example.com`, `siti@example.com`, `andi@example.com` / `user123`
- 3 cabang olahraga
- 6 lapangan
- 3 promo

## Catatan
- File ini tidak menghapus database/tabel yang sudah ada.
- Untuk instalasi bersih, gunakan database kosong.
- File migrasi lama (`migrasi_*.sql`) dan `arenaku.sql` lama tidak disertakan
  di paket ZIP karena sudah digabungkan ke `arenaku_install.sql`.
