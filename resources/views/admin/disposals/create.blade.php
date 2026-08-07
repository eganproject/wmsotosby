{{--
    Mengeluarkan barang di luar penjualan.

    Saldo asalnya dipilih lebih dulu lewat tombol di halaman daftar, bukan di
    dalam form ini. Dengan begitu daftar barangnya hanya berisi yang memang
    punya saldo tersebut, dan tindakan yang ditawarkan hanya yang masuk akal
    baginya — tidak ada pilihan yang harus dianggap tidak ada.
--}}
@php
    use App\Models\DamagedDisposal;
    use App\Models\StockMovement;

    $fromGood = $bucket === StockMovement::BUCKET_GOOD;
    $title = $fromGood ? 'Keluarkan Barang Layak Jual' : 'Tangani Barang Rusak';
    $balanceLabel = $fromGood ? 'layak jual' : 'rusak';
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
                    <x-ui.card title="Barang yang Dikeluarkan"
                               subtitle="Isi jumlahnya; kosongkan yang tidak ikut." padding="p-0">
                        <ul class="divide-y divide-ink-50">
                            @foreach ($products as $product)
                                @php $available = $fromGood ? $product->stock : $product->damaged_stock; @endphp
                                <li class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:gap-4">
                                    <div class="min-w-0 flex-1">
                                        <x-ui.sku :value="$product->sku" />
                                        <p class="mt-1 truncate text-sm font-medium text-ink-950">{{ $product->name }}</p>
                                        <p class="text-[11px] text-ink-400">
                                            Tersedia {{ $available }} {{ $product->unit }} {{ $balanceLabel }}
                                        </p>
                                    </div>

                                    <div class="w-full sm:w-32">
                                        <input type="number" min="0" max="{{ $available }}" inputmode="numeric"
                                               name="quantities[{{ $product->id }}]"
                                               value="{{ old('quantities.'.$product->id) }}"
                                               placeholder="0"
                                               class="h-12 w-full rounded-xl border-ink-200 text-center text-base font-semibold tabular-nums shadow-sm focus:border-ink-950 focus:ring-ink-950 sm:h-11 sm:text-sm">
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>

                    <x-input-error :messages="$errors->get('quantities')" />
                </div>

                <div class="space-y-5">
                    <x-ui.card title="Tindakan" subtitle="Menentukan ke mana barangnya pergi.">
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
