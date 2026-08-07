@props(['label' => 'Tanggal dokumen'])

{{--
    Rentang tanggal untuk bilah saringan.

    Pintasan periode disediakan sebagai tautan biasa, bukan tombol ber-JS:
    hampir tidak ada orang yang ingin mengetik dua tanggal hanya untuk melihat
    hari ini. Saringan lain yang sedang aktif ikut terbawa lewat fullUrlWithQuery,
    dan nomor halaman sengaja dibuang — hasil yang menyusut membuat halaman
    ketiga sering kosong.
--}}
@php
    $presets = [
        'Hari ini' => [today(), today()],
        '7 hari' => [today()->subDays(6), today()],
        'Bulan ini' => [now()->startOfMonth(), today()],
    ];
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col gap-2']) }}>
    <div class="grid grid-cols-2 gap-2 sm:flex sm:items-center">
        <x-text-input type="date" name="from" :value="request('from')" class="sm:w-40"
                      :title="$label.' — dari'" aria-label="Dari tanggal" />
        <x-text-input type="date" name="to" :value="request('to')" class="sm:w-40"
                      :title="$label.' — sampai'" aria-label="Sampai tanggal" />
    </div>

    <div class="flex flex-wrap items-center gap-1.5">
        @foreach ($presets as $caption => [$start, $end])
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
               ])>{{ $caption }}</a>
        @endforeach

        @if (request()->hasAny(['from', 'to']))
            <a href="{{ request()->fullUrlWithQuery(['from' => null, 'to' => null, 'page' => null]) }}"
               class="inline-flex h-7 items-center gap-1 rounded-lg px-2 text-[11px] font-medium text-ink-500 transition hover:bg-ink-100 hover:text-ink-950">
                <x-icon name="close" class="h-3 w-3" />
                Semua tanggal
            </a>
        @endif
    </div>
</div>
