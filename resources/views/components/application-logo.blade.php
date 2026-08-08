{{--
    Lambang perusahaan: huruf C, satu titik, lalu huruf K.

    Dibuat sebagai SVG alih-alih memuat berkas gambar, supaya tetap tajam di
    segala ukuran — dari lencana 20 piksel di menu samping sampai favicon —
    tanpa perlu menyiapkan beberapa ukuran raster.

    Warnanya dikunci pada warna merek, bukan mengikuti currentColor: lambang
    perusahaan tidak boleh berubah warna mengikuti tempatnya diletakkan. Karena
    itu ia selalu dipasang di atas alas terang.

    Perbandingan sisinya lebar (17:8), jadi wadahnya perlu lebih lebar daripada
    tinggi; dipaksa ke dalam kotak persegi, marknya akan menyusut sampai tidak
    terbaca.
--}}
<svg viewBox="0 0 136 64" fill="none" xmlns="http://www.w3.org/2000/svg"
     role="img" aria-label="{{ config('app.name', 'WMS') }}" {{ $attributes }}>

    {{-- C: busur tebal dengan celah menghadap kanan. --}}
    <path d="M49.1 14.2A24 24 0 1 0 49.1 49.8" stroke="#1B2A6B" stroke-width="14" />

    {{-- Titik jingga di antara kedua huruf. --}}
    <circle cx="78" cy="32" r="11" fill="#F5A623" />

    {{-- K: satu tiang tegak dengan dua lengan yang bertemu di tengahnya. --}}
    <path d="M97 1V63" stroke="#1B2A6B" stroke-width="13" />
    <path d="M103 32L126 5M103 32L126 59" stroke="#1B2A6B" stroke-width="13" />
</svg>
