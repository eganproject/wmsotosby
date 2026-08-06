# Menaikkan WMS Otosby ke Hostinger

Urutan di bawah disusun menurut apa yang paling sering menggagalkan
penempatan di shared hosting, bukan menurut urutan pekerjaannya.

## 1. Kenali dulu galatnya

Kode 403 dan 500 punya sebab yang sama sekali berbeda. Bacanya begini:

| Yang terlihat | Artinya | Ke bagian |
|---|---|---|
| **403 Forbidden** | Apache menolak melayani folder karena tidak menemukan `index.php` di sana | 2 |
| **500** / halaman putih | PHP jalan tapi gagal — biasanya `vendor/` atau `.env` belum ada | 3 dan 4 |
| Halaman tampil tanpa gaya, atau galat *Vite manifest not found* | Berkas hasil build belum diunggah | 5 |
| **404** di semua halaman kecuali beranda | `mod_rewrite` mati atau `.htaccess` tidak terbaca | 2 |

Galat sebenarnya selalu tercatat di `storage/logs/laravel.log`. Buka itu
lebih dulu sebelum menebak.

## 2. Document root — penyebab 403

Laravel hanya boleh melayani isi folder `public/`. Sisanya berada di luar
jangkauan internet. Ada dua cara memenuhinya:

**Cara pertama, dan yang benar.** Di hPanel Hostinger:
*Websites → Dashboard → Advanced → Website root*, arahkan ke
`public_html/public` (atau ke mana pun folder `public/` proyek berada).

**Cara kedua, bila document root tidak bisa diubah.** Berkas `.htaccess`
di akar proyek sudah menyiapkannya: seluruh permintaan diteruskan ke
`public/`, dan folder kode seperti `app/`, `vendor/`, serta berkas `.env`
ditolak agar tidak ikut terjangkau. Pastikan berkas itu benar-benar
terunggah — berkas berawalan titik sering tersembunyi di pengelola berkas
dan pada klien FTP.

Pastikan juga `mod_rewrite` aktif. Hostinger menyalakannya secara bawaan.

## 3. Dependensi PHP

`vendor/` tidak ikut ke dalam git. Di server:

```bash
composer install --no-dev --optimize-autoloader
```

Bila tidak ada akses SSH, jalankan perintah itu di komputer Anda lalu
unggah folder `vendor/` apa adanya.

PHP yang dipakai harus **8.2 ke atas** — atur di hPanel bagian *PHP
Configuration*. Ekstensi yang dibutuhkan: `pdo_mysql`, `mbstring`,
`openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `zip`
(`zip` dipakai import dan export Excel).

## 4. Berkas `.env`

`.env` juga tidak ikut ke dalam git — sengaja, karena berisi sandi. Salin
dari `.env.example` lalu sesuaikan:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domain-anda.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=nama_database_hostinger
DB_USERNAME=user_database_hostinger
DB_PASSWORD=sandi_database

SESSION_DRIVER=database
```

`APP_DEBUG=false` wajib di server. Bila `true`, setiap galat menampilkan
isi berkas dan nilai konfigurasi kepada siapa pun yang membuka halamannya.

Lalu:

```bash
php artisan key:generate      # mengisi APP_KEY
php artisan migrate --force   # membuat seluruh tabel
php artisan db:seed --class=RolePermissionSeeder
```

Seeder itu membuat izin, tiga role, dan akun `admin@wmsotosby.test`
dengan sandi `password`. **Ganti sandinya begitu berhasil masuk.**

## 5. Berkas hasil build

`public/build/` tidak ikut ke dalam git. Tanpa berkas ini halaman muncul
tanpa gaya sama sekali, atau gagal dengan pesan soal manifest Vite.

Di komputer Anda:

```bash
npm run build
```

lalu unggah seluruh isi `public/build/` ke server. Ulangi setiap kali ada
perubahan tampilan.

## 6. Izin berkas

```bash
chmod -R 755 storage bootstrap/cache
```

Folder `storage/` harus bisa ditulis — di situlah log, cache, dan sesi
disimpan. Bila tidak bisa ditulis, galatnya justru 500, bukan 403.

Terakhir, sambungkan folder unggahan publik:

```bash
php artisan storage:link
```

## 7. Setelah semuanya jalan

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Ketiganya mempercepat setiap permintaan. **Ulangi tiap kali `.env` atau
route berubah** — konfigurasi yang sudah ter-cache tidak lagi membaca
`.env`, sehingga perubahan yang tidak diikuti `config:cache` seolah-olah
tidak berpengaruh.

Bila suatu saat ada yang aneh setelah pembaruan, bersihkan dulu:

```bash
php artisan optimize:clear
```
