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

**Jangan `composer update` di server.** Keduanya terdengar mirip tetapi
berbeda jauh:

- `composer install` memasang versi yang persis tercatat di
  `composer.lock` — sama dengan yang sudah teruji di komputer Anda.
- `composer update` mengabaikan berkas kunci itu dan mencari versi
  terbaru. Artinya server menjalankan kombinasi pustaka yang belum pernah
  dilalui satu pun tes, dan pembaruan yang membawa perubahan tak sesuai
  bisa mematikan aplikasi tanpa ada yang menyentuh kode.

Perintah itu juga rakus memori dan sering gagal di shared hosting. Bila
tidak ada akses SSH, jalankan `composer install --no-dev` di komputer Anda
lalu unggah folder `vendor/` apa adanya.

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

## 5. Berkas hasil build — *Vite manifest not found*

Pesan itu berarti `public/build/manifest.json` tidak ada di server. Laravel
membaca berkas tersebut untuk mengetahui nama berkas CSS dan JS yang
sebenarnya, karena namanya ber-hash dan berubah setiap kali dibangun.

Hostinger tidak menyediakan Node, jadi `npm run build` tidak bisa
dijalankan di server. Karena itu hasil build **ikut masuk repositori** —
`.gitignore` sudah disesuaikan untuk itu.

Setiap kali ada perubahan tampilan:

```bash
npm run build          # di komputer Anda
git add public/build
git commit -m "build ulang aset"
```

lalu naikkan seperti biasa. Bila mengunggah lewat FTP, kirim seluruh isi
`public/build/` — folder `assets/` beserta `manifest.json` di sampingnya.
Ketiganya harus berpasangan: manifest menyebut nama berkas ber-hash, dan
memperbarui salah satu saja membuat rujukannya meleset.

Isi yang benar hanya tiga berkas, sekitar 300 KB seluruhnya:

```
public/build/manifest.json
public/build/assets/app-<hash>.css
public/build/assets/app-<hash>.js
```

Bila halaman tampil tanpa gaya sama sekali padahal berkasnya sudah ada,
periksa `public/hot`. Berkas itu hanya muncul saat `npm run dev` dan
membuat Laravel mencari server pengembangan yang tidak ada di hosting —
hapus bila ikut terunggah.

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

## 8. Pembaruan yang mengubah basis data

Urutan bakunya setiap kali menarik pembaruan:

```bash
git pull
composer install --no-dev --optimize-autoloader   # bila composer.lock berubah
php artisan migrate --force                       # bila ada migrasi baru
php artisan optimize:clear
```

`migrate` aman diulang: yang sudah pernah dijalankan dilewati.

### Izin baru

Sebagian pembaruan memperkenalkan izin baru — misalnya `reports.view` untuk
Laporan Stok, `imports.cancel` untuk menandai resi batal, atau
`opnames.export` untuk mengunduh hasil stok opname. Izin itu
tersimpan sebagai baris di basis data, bukan di kode, jadi ia belum ada di
server sampai seedernya dijalankan lagi:

```bash
php artisan db:seed --class=RolePermissionSeeder
```

Perintah itu aman diulang: izin yang sudah ada diperbarui, tidak digandakan.
Tetapi ada dua akibat sampingan yang perlu diketahui lebih dulu:

- **Izin ketiga role bawaan disetel ulang** ke daftar di dalam seeder. Bila
  Anda pernah menyesuaikan izin Admin atau Staff Gudang lewat halaman
  *Hak Akses*, penyesuaian itu kembali seperti semula. Role yang Anda buat
  sendiri tidak tersentuh.
- **Sandi akun `admin@wmsotosby.test` kembali menjadi `password`.** Bila akun
  itu masih dipakai, ganti sandinya lagi setelah seeder selesai.

### Resi batal yang sudah telanjur masuk

Status pesanan sudah ikut tersimpan sejak import pertama, tetapi dulu tidak
pernah dibaca. Import berikutnya membacanya sendiri; untuk data yang sudah
telanjur masuk ada perintah tersendiri:

```bash
php artisan waybills:flag-cancelled            # lihat dulu, tidak menulis apa pun
php artisan waybills:flag-cancelled --apply    # baru menandai
```

Tanpa `--apply` perintah itu hanya menampilkan seluruh status yang ada beserta
jumlahnya, dan mana yang akan terbaca sebagai batal. Jalankan yang pertama
lebih dulu: menandai resi batal berarti paketnya tidak bisa lagi discan maupun
dikirim, dan keputusan seperti itu diambil setelah melihat.

Pemegang role Super Admin tidak perlu menunggu seeder — role itu melewati
seluruh pemeriksaan izin, jadi halaman barunya langsung terbuka. Yang
membutuhkannya adalah role lain, dan setelah seeder dijalankan izinnya bisa
diatur seperti biasa lewat *Pengaturan → Pengguna & Akses → Hak Akses*.
