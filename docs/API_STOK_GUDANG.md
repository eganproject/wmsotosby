# API Stok Gudang

```env
STOCK_API_ENABLED=true
STOCK_API_WAREHOUSE_CODE=WMSOTOSBY
STOCK_API_TOKEN=<token-rahasia>
STOCK_API_RATE_LIMIT_PER_MINUTE=60
```

API mengirim stok layak jual dari `products.stock`. `damaged_stock` tidak dimasukkan ke `qty`.

Jalankan `php artisan migrate`, buka **Pengaturan → Pengguna & Akses → Akses
API Stok** untuk menambahkan IP, jalankan `php artisan stock-api:backfill`, lalu
`php artisan optimize:clear`. Alternatif melalui terminal: `php artisan
stock-api:allow-ip 203.0.113.10 --label="Server pusat"`.

Semua endpoint membutuhkan `Authorization: Bearer <token>` dan IP sumber aktif:

- `GET /api/v1/health`
- `GET /api/v1/stocks?updated_since=&updated_until=&page=1&per_page=100`
- `GET /api/v1/stocks?as_of=YYYY-MM-DD&page=1&per_page=100`

`updated_since` dan `updated_until` wajib berformat ISO-8601 dengan offset zona
waktu. `as_of` memakai akhir tanggal dalam zona waktu Asia/Jakarta dan tidak
dapat digabung dengan kedua filter waktu tersebut. `per_page` maksimum 500.
