{{--
    Mengeluarkan barang di luar penjualan.

    Saldo asalnya dipilih lebih dulu lewat tombol di halaman daftar, bukan di
    dalam form ini. Dengan begitu daftar barangnya hanya berisi yang memang
    punya saldo tersebut, dan tindakan yang ditawarkan hanya yang masuk akal
    baginya — tidak ada pilihan yang harus dianggap tidak ada.

    Barangnya dicari, bukan digulir. Sebelumnya seluruh isi gudang ditulis
    sebagai baris input sekaligus, sehingga mengeluarkan dua barang menuntut
    operator melewati ratusan kotak kosong lebih dulu.
--}}
@php
    use App\Models\DamagedDisposal;
    use App\Models\StockMovement;

    $fromGood = $bucket === StockMovement::BUCKET_GOOD;
    $title = $fromGood ? 'Keluarkan Barang Layak Jual' : 'Tangani Barang Rusak';
    $balanceLabel = $fromGood ? 'layak jual' : 'rusak';

    // Katalog dikirim sekali sebagai data, lalu disaring di sisi peramban —
    // tidak ada permintaan tambahan setiap kali operator mengetik.
    $catalog = $products->map(fn ($product) => [
        'id' => $product->id,
        'sku' => $product->sku,
        'barcode' => $product->barcode,
        'name' => $product->name,
        'unit' => $product->unit,
        'available' => (int) ($fromGood ? $product->stock : $product->damaged_stock),
    ])->values();

    // Isi ulang setelah validasi gagal, tanpa kotak kosong ikut terbawa.
    // Dijadikan objek supaya id barang tetap menjadi kunci: sebagai larik biasa,
    // kunci berurutan akan hilang saat diubah menjadi JSON.
    $initial = (object) collect(old('quantities', []))
        ->filter(fn ($quantity) => (int) $quantity > 0)
        ->all();
@endphp

<x-app-layout :title="$title">
    <x-ui.page-header :title="$title" :icon="$fromGood ? 'logout' : 'trash'"
                      :subtitle="'Nomor dokumen '.$code.' · diambil dari stok '.$balanceLabel"
                      :back="route('admin.disposals.index')" />

    @if ($products->isEmpty())
        <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
            <x-ui.empty-state icon="check-circle" :title="'Tidak ada stok '.$balanceLabel"
                              :description="$fromGood
                                  ? 'Belum ada barang dengan saldo layak jual. Catat barang masuk terlebih dahulu.'
                                  : 'Belum ada unit rusak yang tercatat. Barang rusak masuk dari penerimaan retur, atau dari pemindahan stok layak jual di sini.'">
                <x-slot name="action">
                    <x-ui.button :href="route('admin.disposals.index')" variant="secondary">Kembali</x-ui.button>
                </x-slot>
            </x-ui.empty-state>
        </div>
    @else
        <form method="POST" action="{{ route('admin.disposals.store') }}" class="space-y-5"
              x-data="{ action: '{{ old('action', DamagedDisposal::ACTION_DISCARD) }}' }">
            @csrf

            {{-- Saldo asal ikut terkirim: ia menentukan tindakan apa yang sah. --}}
            <input type="hidden" name="bucket" value="{{ $bucket }}">

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div class="space-y-5 lg:col-span-2">
                    {{-- Tanpa overflow-hidden: daftar hasil pencarian menjulur
                         keluar kartunya dan akan terpotong bila dikurung. --}}
                    <div x-data="stockPicker({{ Js::from($catalog) }}, {{ Js::from($initial) }})"
                         class="rounded-2xl border border-ink-100 bg-white shadow-card">

                        {{-- Kepala: pencarian --}}
                        <div class="border-b border-ink-100 px-5 py-4 sm:px-6">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h2 class="text-sm font-semibold tracking-tight text-ink-950">Barang yang Dikeluarkan</h2>
                                <p class="text-xs text-ink-500">
                                    <span class="font-medium text-ink-950" x-text="picked.length"></span> barang
                                    &middot; <span class="font-medium text-ink-950" x-text="totalUnits"></span> unit
                                </p>
                            </div>

                            <div class="relative mt-3" x-on:click.outside="open = false">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-ink-400">
                                    <x-icon name="search" class="h-4 w-4" />
                                </div>

                                <input type="search" x-ref="search" x-model="term" autocomplete="off"
                                       placeholder="Cari SKU, barcode, atau nama barang…"
                                       x-on:input="search()" x-on:focus="open = true"
                                       x-on:keydown.arrow-down.prevent="move(1)"
                                       x-on:keydown.arrow-up.prevent="move(-1)"
                                       x-on:keydown.enter.prevent="pickHighlighted()"
                                       x-on:keydown.escape.prevent="open = false"
                                       class="h-12 w-full rounded-xl border-ink-200 pl-10 pr-4 text-sm text-ink-950 placeholder:text-ink-300 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">

                                {{-- Hasil pencarian --}}
                                <div x-show="open" x-cloak x-transition.opacity.duration.120ms
                                     class="absolute z-20 mt-1.5 w-full overflow-hidden rounded-xl border border-ink-100 bg-white shadow-lift">
                                    <template x-if="noMatch">
                                        <p class="px-4 py-3.5 text-sm text-ink-400">
                                            Tidak ada barang bersaldo {{ $balanceLabel }} yang cocok.
                                        </p>
                                    </template>

                                    <ul class="max-h-72 divide-y divide-ink-50 overflow-y-auto">
                                        <template x-for="(item, index) in matches" :key="item.id">
                                            <li>
                                                <button type="button" x-on:click="choose(item)"
                                                        x-on:mouseenter="highlight = index"
                                                        class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition"
                                                        :class="index === highlight ? 'bg-ink-50' : 'hover:bg-ink-50/60'">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate text-sm font-medium text-ink-950" x-text="item.name"></p>
                                                        <p class="mt-0.5 flex items-center gap-2">
                                                            <span class="inline-flex items-center gap-1 rounded-md bg-ink-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold leading-4 text-ink-800 ring-1 ring-inset ring-ink-200">
                                                                <span class="opacity-50">SKU</span>
                                                                <span x-text="item.sku"></span>
                                                            </span>
                                                            <template x-if="isPicked(item.id)">
                                                                <span class="text-[11px] font-medium text-ink-400">sudah dipilih</span>
                                                            </template>
                                                        </p>
                                                    </div>

                                                    <div class="shrink-0 text-right">
                                                        <p class="text-sm font-semibold tabular-nums text-ink-950" x-text="item.available"></p>
                                                        <p class="text-[10px] uppercase tracking-wider text-ink-400"
                                                           x-text="item.unit"></p>
                                                    </div>
                                                </button>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </div>

                            <p class="mt-2 text-[11px] text-ink-400">
                                Ketik lalu tekan Enter untuk menambahkan. Pemindai barcode juga bisa langsung dipakai di kotak ini.
                            </p>
                        </div>

                        {{-- Baris terpilih --}}
                        <template x-if="picked.length === 0">
                            <div class="px-5 py-12 text-center sm:px-6">
                                <span class="mx-auto inline-flex h-11 w-11 items-center justify-center rounded-xl bg-ink-50 text-ink-400">
                                    <x-icon name="box" class="h-5 w-5" />
                                </span>
                                <p class="mt-3 text-sm font-medium text-ink-950">Belum ada barang dipilih</p>
                                <p class="mt-1 text-xs text-ink-500">
                                    Cari barangnya di kotak di atas, lalu isi jumlah yang dikeluarkan.
                                </p>
                            </div>
                        </template>

                        <ul class="divide-y divide-ink-50">
                            <template x-for="row in picked" :key="row.id">
                                <li class="flex flex-col gap-3 px-5 py-3.5 transition last:rounded-b-2xl sm:flex-row sm:items-center sm:gap-4 sm:px-6"
                                    :class="isFlashed(row.id) && 'bg-ink-50'">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-ink-950" x-text="product(row.id).name"></p>
                                        <p class="mt-1 flex flex-wrap items-center gap-2">
                                            <span class="inline-flex items-center gap-1 rounded-md bg-ink-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold leading-4 text-ink-800 ring-1 ring-inset ring-ink-200">
                                                <span class="opacity-50">SKU</span>
                                                <span x-text="product(row.id).sku"></span>
                                            </span>
                                            <span class="text-[11px] text-ink-400"
                                                  x-text="`Tersedia ${availableOf(row.id)} ${product(row.id).unit} {{ $balanceLabel }}`"></span>
                                        </p>
                                    </div>

                                    {{-- Jumlah: tombol untuk layar sentuh, ketikan untuk angka besar. --}}
                                    <div class="flex items-center gap-2">
                                        <div class="flex items-center rounded-xl border border-ink-200 shadow-soft"
                                             :class="isOverBalance(row) && 'border-red-300'">
                                            <button type="button" x-on:click="step(row, -1)" tabindex="-1"
                                                    class="inline-flex h-11 w-10 items-center justify-center rounded-l-xl text-ink-500 transition hover:bg-ink-50 hover:text-ink-950">
                                                <x-icon name="minus" class="h-4 w-4" />
                                                <span class="sr-only">Kurangi</span>
                                            </button>

                                            {{-- min 0, bukan 1: nol berarti "tidak
                                                 jadi", dan peramban tidak boleh
                                                 memblokir kirim dengan pesannya
                                                 sendiri — peringatan di bawah
                                                 sudah menjelaskannya. --}}
                                            <input type="number" min="0" inputmode="numeric"
                                                   :data-quantity="row.id"
                                                   :name="`quantities[${row.id}]`"
                                                   :max="availableOf(row.id)"
                                                   x-model.number="row.quantity"
                                                   x-on:keydown.enter.prevent="backToSearch()"
                                                   class="h-11 w-20 border-0 bg-transparent p-0 text-center text-base font-semibold tabular-nums text-ink-950 focus:ring-0 sm:text-sm"
                                                   :class="isOverBalance(row) && 'text-red-600'">

                                            <button type="button" x-on:click="step(row, 1)" tabindex="-1"
                                                    class="inline-flex h-11 w-10 items-center justify-center rounded-r-xl text-ink-500 transition hover:bg-ink-50 hover:text-ink-950">
                                                <x-icon name="plus" class="h-4 w-4" />
                                                <span class="sr-only">Tambah</span>
                                            </button>
                                        </div>

                                        <button type="button" x-on:click="remove(row.id)" title="Hapus baris"
                                                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-ink-400 transition hover:bg-red-50 hover:text-red-600">
                                            <x-icon name="trash" class="h-4 w-4" />
                                            <span class="sr-only">Hapus dari daftar</span>
                                        </button>
                                    </div>
                                </li>
                            </template>
                        </ul>

                        {{-- Peringatan yang bisa langsung ditindaklanjuti. --}}
                        <template x-if="overBalanceCount > 0">
                            <div class="flex items-start gap-2.5 rounded-b-2xl border-t border-red-100 bg-red-50 px-5 py-3.5 text-sm text-red-700 sm:px-6">
                                <x-icon name="warning" class="mt-0.5 h-4 w-4 shrink-0" />
                                <span x-text="`${overBalanceCount} baris melebihi saldo {{ $balanceLabel }} yang tercatat. Perbaiki jumlahnya sebelum menyimpan.`"></span>
                            </div>
                        </template>

                        <template x-if="overBalanceCount === 0 && blankCount > 0">
                            <div class="flex items-start gap-2.5 rounded-b-2xl border-t border-amber-100 bg-amber-50 px-5 py-3.5 text-sm text-amber-700 sm:px-6">
                                <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0" />
                                <span x-text="`${blankCount} baris belum diisi jumlahnya dan tidak akan ikut tersimpan.`"></span>
                            </div>
                        </template>
                    </div>

                    <x-input-error :messages="$errors->get('quantities')" />
                </div>

                <div class="space-y-5">
                    <x-ui.card title="Tindakan"
                               :subtitle="$fromGood
                                   ? 'Menentukan ke mana barangnya pergi: keluar dari gudang, atau sekadar pindah ke saldo rusak.'
                                   : 'Menentukan ke mana barangnya pergi.'">
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="date" value="Tanggal" />
                                <x-text-input id="date" name="date" type="date" class="mt-1.5 w-full"
                                              :value="old('date', now()->toDateString())" required />
                                <x-input-error :messages="$errors->get('date')" class="mt-1.5" />
                            </div>

                            <div class="space-y-2">
                                @foreach (DamagedDisposal::actionsFor($bucket) as $value => $label)
                                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition
                                                  has-[:checked]:border-ink-950 has-[:checked]:bg-ink-50/70 border-ink-100">
                                        <input type="radio" name="action" value="{{ $value }}" x-model="action" required
                                               class="mt-0.5 h-4 w-4 shrink-0 border-ink-300 text-ink-950 focus:ring-ink-950">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium text-ink-950">{{ $label }}</span>
                                            <span class="block text-[11px] leading-relaxed text-ink-500">
                                                @switch($value)
                                                    @case(DamagedDisposal::ACTION_REPAIR)
                                                        Unitnya pindah ke saldo layak jual, tetap di gudang.
                                                        @break
                                                    @case(DamagedDisposal::ACTION_WRITE_OFF)
                                                        Unitnya pindah ke saldo rusak, tetap di gudang.
                                                        @break
                                                    @default
                                                        Unitnya keluar dari gudang untuk selamanya.
                                                @endswitch
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                                <x-input-error :messages="$errors->get('action')" class="mt-1" />
                            </div>

                            <div x-show="action === '{{ DamagedDisposal::ACTION_RETURN }}'" x-cloak>
                                <x-input-label for="supplier_id" value="Pemasok tujuan" />
                                <x-ui.select id="supplier_id" name="supplier_id" class="mt-1.5 w-full">
                                    <option value="">Pilih pemasok…</option>
                                    @foreach ($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>
                                            {{ $supplier->name }}
                                        </option>
                                    @endforeach
                                </x-ui.select>
                            </div>

                            <div>
                                <x-input-label for="note" value="Catatan (opsional)" />
                                <textarea id="note" name="note" rows="2"
                                          class="mt-1.5 w-full rounded-xl border-ink-200 text-sm shadow-sm focus:border-ink-950 focus:ring-ink-950"
                                          placeholder="Misal: nomor berita acara pemusnahan.">{{ old('note') }}</textarea>
                            </div>
                        </div>
                    </x-ui.card>

                    <div class="flex flex-col gap-2 sm:flex-row">
                        <x-ui.button type="submit" icon="check" class="flex-1">Simpan Dokumen</x-ui.button>
                        <x-ui.button :href="route('admin.disposals.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
                    </div>
                </div>
            </div>
        </form>
    @endif
</x-app-layout>
