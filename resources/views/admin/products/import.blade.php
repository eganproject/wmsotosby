<x-app-layout title="Import Barang">
    <x-ui.page-header title="Import Barang & Stok"
                      subtitle="Tambah banyak barang sekaligus dari Excel, lengkap dengan stoknya."
                      :back="route('admin.products.index')">
        <x-slot name="actions">
            {{-- data-no-ajax: unduhan berkas tidak boleh lewat navigasi AJAX. --}}
            <x-ui.button :href="route('admin.products.import.template')" variant="secondary" icon="document" data-no-ajax>
                Unduh Template
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Langkah 1 --}}
            <div class="flex items-start gap-4 rounded-2xl border border-ink-100 bg-white p-5 shadow-card">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ink-950 text-xs font-bold text-white">1</span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-ink-950">Unduh template</p>
                    <p class="mt-0.5 text-sm text-ink-500">
                        Berkas template sudah berisi judul kolom yang benar, dua baris contoh, dan lembar petunjuk pengisian.
                    </p>
                    <x-ui.button :href="route('admin.products.import.template')" variant="secondary" size="sm"
                                 icon="document" class="mt-3" data-no-ajax>
                        Unduh Template Excel
                    </x-ui.button>
                </div>
            </div>

            {{-- Langkah 2 --}}
            <form method="POST" action="{{ route('admin.products.import.store') }}" enctype="multipart/form-data" data-no-ajax
                  x-data="{ name: '', drag: false }">
                @csrf

                <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                    <div class="flex items-start gap-4 border-b border-ink-100 p-5">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ink-950 text-xs font-bold text-white">2</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-ink-950">Unggah berkas yang sudah diisi</p>
                            <p class="mt-0.5 text-sm text-ink-500">Format .xlsx, .xls, atau .csv — maksimal 20 MB.</p>
                        </div>
                    </div>

                    <div class="p-5 sm:p-6">
                        <label class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-12 text-center transition"
                               :class="drag ? 'border-ink-950 bg-ink-50' : 'border-ink-200 hover:border-ink-300 hover:bg-ink-50/50'"
                               @dragover.prevent="drag = true" @dragleave.prevent="drag = false"
                               @drop="drag = false; name = $event.dataTransfer.files[0]?.name ?? ''">
                            <input type="file" name="file" accept=".xlsx,.xls,.csv,.txt" class="sr-only" required
                                   @change="name = $event.target.files[0]?.name ?? ''">

                            <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-ink-950 text-white">
                                <x-icon name="box" class="h-6 w-6" />
                            </span>

                            <span class="mt-4 block text-sm font-semibold text-ink-950" x-show="! name">
                                Klik untuk memilih berkas Excel
                            </span>
                            <span class="mt-4 block break-all font-mono text-sm font-semibold text-ink-950" x-show="name" x-cloak x-text="name"></span>

                            <span class="mt-1 block text-xs text-ink-500" x-show="! name">
                                atau tarik berkasnya ke sini &middot; .xlsx, .xls, .csv
                            </span>
                            <span class="mt-1 block text-xs text-emerald-600" x-show="name" x-cloak>
                                Berkas siap diimport
                            </span>
                        </label>

                        <x-input-error :messages="$errors->get('file')" class="mt-3" />

                        <div class="mt-5 flex flex-col gap-2 sm:flex-row">
                            <x-ui.button type="submit" icon="check" class="flex-1">Proses Import</x-ui.button>
                            <x-ui.button :href="route('admin.products.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
                        </div>
                    </div>
                </div>
            </form>

            {{-- Riwayat --}}
            @if ($history->isNotEmpty())
                <x-ui.card title="Import Terakhir" padding="p-0">
                    <ul class="divide-y divide-ink-50">
                        @foreach ($history as $import)
                            <li class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:px-6">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-ink-950">{{ $import->filename }}</p>
                                    <p class="text-[11px] text-ink-400">
                                        {{ $import->created_at->translatedFormat('d M Y H:i') }}
                                        @if ($import->user) &middot; {{ $import->user->name }} @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-ui.badge variant="success">{{ $import->created_count }} baru</x-ui.badge>
                                    <x-ui.badge variant="outline">{{ $import->updated_count }} diperbarui</x-ui.badge>
                                    @if ($import->stock_adjusted_count > 0)
                                        <x-ui.badge variant="warning">{{ $import->stock_adjusted_count }} stok</x-ui.badge>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-6">
            <x-ui.card title="Kolom Berkas" subtitle="Nama kolom dikenali otomatis, urutannya bebas.">
                <ul class="space-y-2.5 text-sm">
                    @foreach ([
                        ['SKU', 'Kode unik barang. SKU yang sudah ada akan diperbarui.', true],
                        ['Nama Barang', 'Nama barang sehari-hari.', true],
                        ['Barcode', 'Kode untuk scanner. Boleh kosong.', false],
                        ['Kategori', 'Filter, Pelumas, Kelistrikan, dsb.', false],
                        ['Satuan', 'pcs, box, set. Kosong dianggap pcs.', false],
                        ['Lokasi Rak', 'Contoh: A-02-01.', false],
                        ['Stok Minimum', 'Batas peringatan stok menipis.', false],
                        ['Stok', 'Stok saat ini. Selisihnya masuk kartu stok.', false],
                    ] as [$column, $desc, $required])
                        <li class="flex items-start gap-2.5">
                            <x-icon :name="$required ? 'check-circle' : 'circle'"
                                    class="mt-0.5 h-4 w-4 shrink-0 {{ $required ? 'text-ink-950' : 'text-ink-300' }}" />
                            <span class="min-w-0">
                                <span class="block text-xs font-semibold text-ink-950">
                                    {{ $column }}
                                    @if ($required)
                                        <span class="ml-1 rounded bg-ink-950 px-1 py-px text-[10px] font-medium text-white">wajib</span>
                                    @endif
                                </span>
                                <span class="block text-xs text-ink-500">{{ $desc }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            <div class="rounded-2xl border border-ink-100 bg-ink-50/60 p-5">
                <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-ink-400">
                    <x-icon name="info" class="h-3.5 w-3.5" /> Cara kerja
                </p>
                <ul class="mt-3 space-y-2 text-xs leading-relaxed text-ink-600">
                    <li class="flex gap-2"><span class="font-semibold text-ink-950">1.</span> SKU jadi kuncinya — barang yang sudah ada diperbarui, bukan diduplikat.</li>
                    <li class="flex gap-2"><span class="font-semibold text-ink-950">2.</span> Kolom Stok bersifat opsional. Bila diisi, selisih dari stok saat ini dicatat sebagai pergerakan stok.</li>
                    <li class="flex gap-2"><span class="font-semibold text-ink-950">3.</span> Karena tercatat di kartu stok, penyesuaian ini tetap bisa ditelusuri.</li>
                    <li class="flex gap-2"><span class="font-semibold text-ink-950">4.</span> Bila ada baris yang gagal, seluruh berkas dibatalkan — tidak ada data setengah masuk.</li>
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
