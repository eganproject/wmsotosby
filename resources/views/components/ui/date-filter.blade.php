@props(['label' => 'Rentang tanggal'])

{{--
    Rentang tanggal dalam satu kolom.

    Nilai sebenarnya tetap dikirim sebagai dua parameter, from dan to, lewat
    kolom tersembunyi — seluruh controller dan tautan yang sudah ada tidak
    perlu tahu bahwa tampilannya berubah. Kolom yang terlihat hanya cerminan
    yang diurus flatpickr.

    Isinya dirender dari sisi server juga, bukan hanya oleh JavaScript, supaya
    rentang yang sedang aktif tetap terbaca pada saat halaman baru dimuat —
    kolom kosong padahal daftarnya tersaring adalah kebohongan yang mahal.
--}}
@php
    /*
        Tanggal ditulis dalam angka, bukan nama bulan.

        Teks ini harus sama persis dengan yang ditulis flatpickr, sebab kolomnya
        dirender server lebih dulu lalu diambil alih flatpickr — teks yang
        berubah sendiri saat JavaScript selesai dimuat terlihat seperti
        kerusakan. Menyamakan nama bulan berarti menaruh dua daftar bulan di dua
        tempat yang bisa menyimpang: Carbon menulis "Agt", flatpickr menulis
        "Agu". Angka tidak punya masalah itu sama sekali.
    */
    $parse = fn (?string $value) => rescue(
        fn () => filled($value) ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $value) : null,
        null,
        report: false,
    );

    $from = $parse(request('from'));
    $to = $parse(request('to'));

    $day = fn (\Illuminate\Support\Carbon $date) => $date->format('d/m/Y');

    // Rentang sehari ditulis satu kali saja — flatpickr pun membuang tanggal
    // kembar pada mode rentang.
    $rangeCaption = match (true) {
        $from && $to => $day($from) === $day($to) ? $day($from) : $day($from).' – '.$day($to),
        (bool) $from => $day($from),
        (bool) $to => $day($to),
        default => '',
    };

    // Rentang bertepi terbuka hanya bisa datang dari alamat yang disunting
    // tangan; kalender tidak bisa menggambarkannya, jadi disebut dengan kata.
    $openEnd = match (true) {
        $from && ! $to => 'Tanpa batas akhir',
        $to && ! $from => 'Tanpa batas awal',
        default => null,
    };

    $presets = [
        'Hari ini' => [today(), today()],
        '7 hari' => [today()->subDays(6), today()],
        'Bulan ini' => [now()->startOfMonth(), today()],
    ];
@endphp

<div data-date-range {{ $attributes->merge(['class' => 'flex flex-col gap-2']) }}>
    <input type="hidden" name="from" value="{{ request('from') }}" data-date-range-from>
    <input type="hidden" name="to" value="{{ request('to') }}" data-date-range-to>

    <div class="relative sm:w-64">
        <span class="pointer-events-none absolute inset-y-0 left-0 z-10 flex w-10 items-center justify-center text-ink-300">
            <x-icon name="calendar" class="h-4 w-4" />
        </span>

        {{--
            readonly, bukan disabled: nilainya tetap terbaca dan kolomnya tetap
            bisa difokuskan, tetapi tidak ada tanggal ketikan tangan yang bisa
            masuk dalam bentuk yang tidak terbaca server.
        --}}
        <input type="text" data-date-range-input readonly autocomplete="off"
               value="{{ $rangeCaption }}" placeholder="Semua tanggal" title="{{ $label }}"
               class="block h-[2.625rem] w-full cursor-pointer rounded-xl border-ink-200 bg-white pl-10 pr-9 text-sm text-ink-950 placeholder:text-ink-300 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">

        <button type="button" data-date-range-clear
                @class([
                    'absolute inset-y-0 right-0 z-10 flex w-9 items-center justify-center text-ink-300 transition hover:text-ink-950',
                    'hidden' => $rangeCaption === '',
                ])>
            <x-icon name="close" class="h-4 w-4" />
            <span class="sr-only">Hapus rentang tanggal</span>
        </button>
    </div>

    @if ($openEnd)
        <p class="text-[11px] text-ink-400">{{ $openEnd }}</p>
    @endif

    {{--
        Pintasan periode sebagai tautan biasa, bukan tombol ber-JS: hampir tidak
        ada orang yang ingin memilih dua tanggal hanya untuk melihat hari ini,
        dan tautan tetap bekerja sebelum JavaScript sempat dimuat. Saringan lain
        yang sedang aktif ikut terbawa lewat fullUrlWithQuery, sedangkan nomor
        halaman dibuang — hasil yang menyusut membuat halaman ketiga sering
        kosong.
    --}}
    <div class="flex flex-wrap items-center gap-1.5">
        @foreach ($presets as $preset => [$start, $end])
            @php
                $active = request('from') === $start->toDateString() && request('to') === $end->toDateString();
            @endphp

            <a href="{{ request()->fullUrlWithQuery([
                   'from' => $start->toDateString(),
                   'to' => $end->toDateString(),
                   'page' => null,
               ]) }}"
               @class([
                   'inline-flex h-7 items-center rounded-lg px-2.5 text-[11px] font-medium transition',
                   'bg-ink-950 text-white' => $active,
                   'bg-ink-50 text-ink-600 ring-1 ring-inset ring-ink-200 hover:bg-ink-100' => ! $active,
               ])>{{ $preset }}</a>
        @endforeach
    </div>
</div>
