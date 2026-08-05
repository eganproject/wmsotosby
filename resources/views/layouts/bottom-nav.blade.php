{{--
    Navigasi bawah khusus ponsel.

    Di gudang, layar dipegang satu tangan sambil tangan lain memegang barang
    atau scanner. Menu geser dari samping menuntut dua ketukan dan jangkauan
    ke sudut atas layar; bilah ini menaruh pekerjaan yang paling sering
    dibuka di jempol.

    Isinya sengaja lima slot dan tidak lebih — slot kelima selalu "Menu"
    supaya seluruh halaman lain tetap terjangkau.
--}}
@php
    $items = collect([
        [
            'label' => 'Beranda',
            'icon' => 'dashboard',
            'href' => route('admin.dashboard'),
            'active' => request()->routeIs('admin.dashboard'),
            'can' => null,
        ],
        [
            'label' => 'Packing',
            'icon' => 'search',
            'href' => auth()->user()->can('outbounds.scan') ? route('admin.outbounds.marketplace') : null,
            'active' => request()->routeIs('admin.outbounds.*'),
            'can' => 'outbounds.scan',
        ],
        [
            'label' => 'Retur',
            'icon' => 'refresh',
            'href' => auth()->user()->can('returns.scan') ? route('admin.returns.marketplace') : null,
            'active' => request()->routeIs('admin.returns.*'),
            'can' => 'returns.scan',
        ],
        [
            'label' => 'Stok',
            'icon' => 'box',
            'href' => auth()->user()->can('products.view') ? route('admin.products.index') : null,
            'active' => request()->routeIs('admin.products.*') || request()->routeIs('admin.movements.*'),
            'can' => 'products.view',
        ],
    ])->filter(fn (array $item) => $item['can'] === null || auth()->user()->can($item['can']));
@endphp

<nav data-bottom-nav aria-label="Navigasi utama"
     class="fixed inset-x-0 bottom-0 z-40 border-t border-ink-100 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden">
    <div class="flex items-stretch justify-around">
        @foreach ($items as $item)
            <a href="{{ $item['href'] }}" @if ($item['active']) aria-current="page" @endif
               @class([
                   'flex min-w-0 flex-1 flex-col items-center gap-0.5 px-1 py-2.5 text-[11px] font-medium transition',
                   'text-ink-950' => $item['active'],
                   'text-ink-400' => ! $item['active'],
               ])>
                <span @class([
                    'inline-flex h-8 w-14 items-center justify-center rounded-full transition',
                    'bg-ink-950 text-white' => $item['active'],
                ])>
                    <x-icon :name="$item['icon']" class="h-5 w-5" />
                </span>
                <span class="truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach

        <button type="button" @click="sidebarOpen = true"
                class="flex min-w-0 flex-1 flex-col items-center gap-0.5 px-1 py-2.5 text-[11px] font-medium text-ink-400 transition">
            <span class="inline-flex h-8 w-14 items-center justify-center rounded-full">
                <x-icon name="menu" class="h-5 w-5" />
            </span>
            <span class="truncate">Menu</span>
        </button>
    </div>
</nav>
