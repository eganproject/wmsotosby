@props(['paginator', 'bare' => false])

{{--
    Navigasi halaman.

    Pembungkusnya hanya dirender bila memang ada halaman lain. Sebelumnya
    bilah bergaris ini selalu ikut tercetak, jadi daftar yang muat dalam satu
    halaman berakhir dengan strip kosong menggantung di bawahnya.
--}}
@if ($paginator->hasPages())
    @if ($bare)
        <div {{ $attributes->merge(['class' => 'mt-6']) }}>
            {{ $paginator->links() }}
        </div>
    @else
        <div {{ $attributes->merge(['class' => 'border-t border-ink-100 px-4 py-3.5 sm:px-6']) }}>
            {{ $paginator->links() }}
        </div>
    @endif
@endif
