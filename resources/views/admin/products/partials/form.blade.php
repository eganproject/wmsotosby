@php
    $product = $product ?? null;
    $isEdit = (bool) $product;
    $catalog = $catalog ?? collect();

    $type = old('type', $product?->type ?? \App\Models\Product::TYPE_SINGLE);

    // Barang yang sudah punya saldo atau jejak mutasi tidak bisa lagi menjadi
    // paket — paket tidak punya saldo, dan yang tersisa akan menggantung tanpa
    // cara menggerakkannya. Pilihannya dikunci di layar, dan ditolak lagi di
    // server oleh UpdateProductRequest.
    $lockedToSingle = $isEdit
        && ! $product->isBundle()
        && ($product->stock > 0 || $product->damaged_stock > 0 || $product->movements()->exists());

    $initialComponents = collect(old('components', $product?->bundleComponents
        ->map(fn ($item) => ['component_id' => $item->component_id, 'quantity' => $item->quantity])
        ->all() ?? []))->values();
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.products.update', $product) : route('admin.products.store') }}"
      x-data="bundleRecipe({{ Js::from($initialComponents) }}, {{ Js::from($catalog) }}, '{{ $type }}')">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Jenis barang --}}
            <x-ui.card title="Jenis" subtitle="Paket bundling dijual sebagai satu SKU, tetapi yang ada di rak hanya barang isinya.">
                @if ($lockedToSingle)
                    <input type="hidden" name="type" value="single">
                    <div class="flex items-start gap-2.5 rounded-xl border border-ink-100 bg-ink-50/50 p-4 text-xs text-ink-600">
                        <x-icon name="info" class="mt-px h-4 w-4 shrink-0 text-ink-300" />
                        <span>
                            <span class="font-medium text-ink-950">{{ $product->sku }}</span> tidak bisa dijadikan paket
                            karena sudah punya stok atau riwayat pergerakan. Buat paketnya sebagai barang baru, lalu
                            nonaktifkan yang lama.
                        </span>
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ([
                            ['value' => 'single', 'icon' => 'box', 'label' => 'Barang', 'desc' => 'Punya wujud di rak dan punya stok sendiri.'],
                            ['value' => 'bundle', 'icon' => 'sparkles', 'label' => 'Paket Bundling', 'desc' => 'Tidak punya stok — dihitung dari isinya.'],
                        ] as $option)
                            <label class="relative flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
                                   :class="type === '{{ $option['value'] }}' ? 'border-ink-950 bg-ink-950 text-white shadow-soft' : 'border-ink-100 hover:border-ink-200 hover:bg-ink-50/60'">
                                <input type="radio" name="type" value="{{ $option['value'] }}" x-model="type" class="sr-only">
                                <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition"
                                      :class="type === '{{ $option['value'] }}' ? 'bg-white/10 text-white' : 'bg-ink-50 text-ink-950 ring-1 ring-ink-100'">
                                    <x-icon :name="$option['icon']" class="h-4 w-4" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold">{{ $option['label'] }}</span>
                                    <span class="block text-xs" :class="type === '{{ $option['value'] }}' ? 'text-white/60' : 'text-ink-500'">
                                        {{ $option['desc'] }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endif

                <x-input-error :messages="$errors->get('type')" class="mt-3" />
            </x-ui.card>

            <x-ui.card title="Identitas Barang" subtitle="SKU dan barcode dipakai saat proses scan barang keluar.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.field label="Nama Barang" for="name" :error="$errors->get('name')" required class="sm:col-span-2">
                        <x-text-input id="name" name="name" type="text" :value="old('name', $product?->name)"
                                      required autofocus placeholder="Contoh: Filter Oli Standar" />
                    </x-ui.field>

                    <x-ui.field label="SKU" for="sku" :error="$errors->get('sku')" required>
                        <x-text-input id="sku" name="sku" type="text" :value="old('sku', $product?->sku)"
                                      required class="font-mono uppercase" placeholder="FLT-OLI-STD" />
                        <p class="mt-1.5 text-[11px] text-ink-500" x-show="isBundle" x-cloak>
                            Isi persis seperti SKU paket di marketplace — inilah yang dicocokkan saat berkas Ginee diimport.
                        </p>
                        <p class="mt-1.5 text-[11px] text-ink-500" x-show="! isBundle">
                            Kode internal, otomatis dibuat huruf besar.
                        </p>
                    </x-ui.field>

                    {{--
                        Paket tidak punya wujud, jadi tidak ada yang bisa
                        ditempeli barcode. Kolomnya dimatikan, bukan sekadar
                        disembunyikan: barang yang berubah menjadi paket harus
                        benar-benar kehilangan barcodenya, supaya tidak ada
                        label lama yang masih bisa terbaca scanner.
                    --}}
                    <div x-show="! isBundle">
                        <x-ui.field label="Barcode" for="barcode" :error="$errors->get('barcode')"
                                    hint="Kode yang dibaca scanner saat verifikasi.">
                            <x-text-input id="barcode" name="barcode" type="text" :value="old('barcode', $product?->barcode)"
                                          class="font-mono" placeholder="8991234500035"
                                          x-bind:disabled="isBundle" />
                        </x-ui.field>
                    </div>
                </div>
            </x-ui.card>

            {{-- Isi paket --}}
            <div x-show="isBundle" x-cloak>
                @include('admin.products.partials.components')
            </div>

            <x-ui.card title="Klasifikasi & Penyimpanan">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                    <x-ui.field label="Kategori" for="category" :error="$errors->get('category')">
                        <x-text-input id="category" name="category" type="text" :value="old('category', $product?->category)"
                                      placeholder="Filter" list="category-options" />
                        <datalist id="category-options">
                            @foreach (['Pelumas', 'Filter', 'Pengereman', 'Kelistrikan', 'Aksesoris', 'Ban'] as $option)
                                <option value="{{ $option }}"></option>
                            @endforeach
                        </datalist>
                    </x-ui.field>

                    <x-ui.field label="Satuan" for="unit" :error="$errors->get('unit')" required>
                        <x-text-input id="unit" name="unit" type="text" :value="old('unit', $product?->unit ?? 'pcs')"
                                      required placeholder="pcs" list="unit-options" />
                        <datalist id="unit-options">
                            @foreach (['pcs', 'box', 'set', 'botol', 'unit', 'liter', 'paket'] as $option)
                                <option value="{{ $option }}"></option>
                            @endforeach
                        </datalist>
                    </x-ui.field>

                    <div x-show="! isBundle">
                        <x-ui.field label="Lokasi Rak" for="location" :error="$errors->get('location')">
                            <x-text-input id="location" name="location" type="text" :value="old('location', $product?->location)"
                                          class="font-mono" placeholder="A-01-01"
                                          x-bind:disabled="isBundle" />
                        </x-ui.field>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Pengaturan Stok">
                <div class="space-y-5">
                    {{--
                        Batas menipis adalah setelan atas saldo di rak. Paket
                        tidak punya rak, jadi kolomnya tidak ditampilkan —
                        tetapi tetap terkirim sebagai nol supaya aturan
                        validasinya tidak perlu bercabang.
                    --}}
                    <div x-show="! isBundle">
                        <x-ui.field label="Stok Minimum" for="min_stock" :error="$errors->get('min_stock')"
                                    hint="Sistem menandai barang sebagai menipis bila stok mencapai angka ini." required>
                            <x-text-input id="min_stock" name="min_stock" type="number" min="0"
                                          :value="old('min_stock', $product?->min_stock ?? 0)"
                                          x-bind:disabled="isBundle" x-bind:required="! isBundle" />
                        </x-ui.field>
                    </div>
                    {{--
                        Dua kolom bernama sama, dan tepat satu di antaranya
                        yang aktif: kolom yang disabled tidak ikut terkirim.
                        Menyembunyikannya saja tidak cukup — x-show hanya
                        menyembunyikan, isinya tetap dikirim, dan dua nilai
                        untuk satu nama membuat yang menang bergantung pada
                        urutan penulisan.
                    --}}
                    <input type="hidden" name="min_stock" value="0" x-bind:disabled="! isBundle">

                    <template x-if="isBundle">
                        <div class="rounded-xl border border-ink-100 bg-ink-50/50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wider text-ink-400">Bisa dirakit sekarang</p>
                            <p class="mt-1 text-2xl font-semibold tracking-tight text-ink-950">
                                <span x-text="availability"></span>
                                <span class="text-sm font-normal text-ink-400">paket</span>
                            </p>
                            <p class="mt-2 flex items-start gap-1.5 text-[11px] text-ink-500">
                                <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
                                Dihitung dari stok komponen, tidak pernah disimpan. Angkanya ikut berubah setiap kali komponennya bergerak.
                            </p>
                        </div>
                    </template>

                    @if ($isEdit)
                        <div class="rounded-xl border border-ink-100 bg-ink-50/50 p-4" x-show="! isBundle">
                            <p class="text-xs font-medium uppercase tracking-wider text-ink-400">Stok saat ini</p>
                            <p class="mt-1 text-2xl font-semibold tracking-tight text-ink-950">
                                {{ number_format($product->stock, 0, ',', '.') }}
                                <span class="text-sm font-normal text-ink-400">{{ $product->unit }}</span>
                            </p>
                            <p class="mt-2 flex items-start gap-1.5 text-[11px] text-ink-500">
                                <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
                                Stok hanya berubah lewat dokumen barang masuk dan barang keluar.
                            </p>
                        </div>
                    @endif

                    <div class="rounded-xl border border-ink-100 bg-ink-50/50 p-4">
                        <x-ui.toggle name="is_active" :checked="(bool) old('is_active', $product?->is_active ?? true)"
                                     label="Barang aktif"
                                     description="Barang nonaktif tidak muncul di dokumen baru." />
                    </div>
                </div>
            </x-ui.card>

            <div class="flex flex-col gap-2 sm:flex-row">
                <x-ui.button type="submit" icon="check" class="flex-1">
                    {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Barang' }}
                </x-ui.button>
                <x-ui.button :href="route('admin.products.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
            </div>
        </div>
    </div>
</form>
