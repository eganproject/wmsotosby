@props(['title', 'subtitle' => null, 'icon' => null, 'back' => null])

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-4 sm:mb-8 sm:flex-row sm:items-center sm:justify-between']) }}>
    <div class="flex items-start gap-3">
        @if ($back)
            <a href="{{ $back }}"
               class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-ink-500 ring-1 ring-inset ring-ink-200 transition hover:bg-ink-50 hover:text-ink-950">
                <x-icon name="arrow-left" class="h-4 w-4" />
                <span class="sr-only">Kembali</span>
            </a>
        @elseif ($icon)
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-ink-950 text-white">
                <x-icon :name="$icon" class="h-5 w-5" />
            </span>
        @endif

        <div class="min-w-0">
            <h1 class="text-xl font-semibold tracking-tight text-ink-950 sm:text-2xl">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-1 text-sm text-ink-500">{{ $subtitle }}</p>
            @endif
        </div>
    </div>

    @isset($actions)
        {{--
            Di ponsel tombol aksi digulir mendatar, bukan dibungkus ke baris
            baru: tombol yang terpotong setengah masih bisa dilihat dan
            digapai, sedangkan tombol yang menyusut jadi terlalu kecil untuk
            ditekan dengan jempol.
        --}}
        <div class="-mx-4 flex items-center gap-2 overflow-x-auto px-4 pb-1 [&>*]:shrink-0
                    sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 sm:pb-0">{{ $actions }}</div>
    @endisset
</div>
