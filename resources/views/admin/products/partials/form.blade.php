@php
    $product = $product ?? null;
    $isEdit = (bool) $product;
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.products.update', $product) : route('admin.products.store') }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Identitas Barang" subtitle="SKU dan barcode dipakai saat proses scan barang keluar.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.field label="Nama Barang" for="name" :error="$errors->get('name')" required class="sm:col-span-2">
                        <x-text-input id="name" name="name" type="text" :value="old('name', $product?->name)"
                                      required autofocus placeholder="Contoh: Filter Oli Standar" />
                    </x-ui.field>

                    <x-ui.field label="SKU" for="sku" :error="$errors->get('sku')"
                                hint="Kode internal, otomatis dibuat huruf besar." required>
                        <x-text-input id="sku" name="sku" type="text" :value="old('sku', $product?->sku)"
                                      required class="font-mono uppercase" placeholder="FLT-OLI-STD" />
                    </x-ui.field>

                    <x-ui.field label="Barcode" for="barcode" :error="$errors->get('barcode')"
                                hint="Kode yang dibaca scanner saat verifikasi.">
                        <x-text-input id="barcode" name="barcode" type="text" :value="old('barcode', $product?->barcode)"
                                      class="font-mono" placeholder="8991234500035" />
                    </x-ui.field>
                </div>
            </x-ui.card>

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
                            @foreach (['pcs', 'box', 'set', 'botol', 'unit', 'liter'] as $option)
                                <option value="{{ $option }}"></option>
                            @endforeach
                        </datalist>
                    </x-ui.field>

                    <x-ui.field label="Lokasi Rak" for="location" :error="$errors->get('location')">
                        <x-text-input id="location" name="location" type="text" :value="old('location', $product?->location)"
                                      class="font-mono" placeholder="A-01-01" />
                    </x-ui.field>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Pengaturan Stok">
                <div class="space-y-5">
                    <x-ui.field label="Stok Minimum" for="min_stock" :error="$errors->get('min_stock')"
                                hint="Sistem menandai barang sebagai menipis bila stok mencapai angka ini." required>
                        <x-text-input id="min_stock" name="min_stock" type="number" min="0"
                                      :value="old('min_stock', $product?->min_stock ?? 0)" required />
                    </x-ui.field>

                    @if ($isEdit)
                        <div class="rounded-xl border border-ink-100 bg-ink-50/50 p-4">
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
