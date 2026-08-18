<x-app-layout title="Barang & Stok">
    <x-ui.page-header title="Barang & Stok" icon="box"
                      subtitle="Master data barang sekaligus saldo stoknya. Tambah dan ubah barang dilakukan di sini.">
        <x-slot name="actions">
            @can('products.import')
                <x-ui.button :href="route('admin.products.import')" variant="secondary" icon="login">
                    Import Excel
                </x-ui.button>
            @endcan
            @can('products.view')
                {{-- data-no-ajax: unduhan berkas tidak boleh lewat navigasi AJAX. --}}
                <x-ui.button :href="route('admin.products.export', request()->query())"
                             variant="secondary" icon="document" data-no-ajax>
                    Export Excel
                </x-ui.button>
            @endcan
            @can('products.create')
                <x-ui.button :href="route('admin.products.create')" icon="plus">Tambah Barang</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="stock" />

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Jenis Barang" :value="$summary['total']" icon="box" accent
                        :hint="$summary['bundles'] > 0 ? 'SKU barang · '.$summary['bundles'].' paket bundling' : 'SKU terdaftar'" />
        <x-ui.stat-card label="Total Unit" :value="number_format($summary['units'], 0, ',', '.')" icon="chart" hint="Seluruh stok tersedia" />
        <x-ui.stat-card label="Stok Menipis" :value="$summary['low']" icon="warning" hint="Di bawah batas minimum" />
        <x-ui.stat-card label="Stok Habis" :value="$summary['out']" icon="x-circle" hint="Perlu segera diisi" />
    </div>

    <form method="GET" action="{{ route('admin.products.index') }}" data-auto-submit
          class="my-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nama, SKU, atau barcode..." class="pl-10" />
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <x-ui.select name="category" class="sm:w-44">
                <option value="">Semua kategori</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="stock" class="sm:w-40">
                <option value="">Semua stok</option>
                <option value="safe" @selected(request('stock') === 'safe')>Aman</option>
                <option value="low" @selected(request('stock') === 'low')>Menipis</option>
                <option value="out" @selected(request('stock') === 'out')>Habis</option>
            </x-ui.select>

            <x-ui.select name="type" class="sm:w-36">
                <option value="">Semua jenis</option>
                <option value="single" @selected(request('type') === 'single')>Barang</option>
                <option value="bundle" @selected(request('type') === 'bundle')>Paket</option>
            </x-ui.select>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'category', 'stock', 'status', 'type']))
                <x-ui.button :href="route('admin.products.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    @if ($products->isEmpty())
        <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
            <x-ui.empty-state icon="box" title="Barang tidak ditemukan"
                              description="Tambahkan barang satu per satu, atau import banyak sekaligus dari Excel beserta stoknya.">
                <x-slot name="action">
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        @can('products.create')
                            <x-ui.button :href="route('admin.products.create')" icon="plus">Tambah Barang</x-ui.button>
                        @endcan
                        @can('products.import')
                            <x-ui.button :href="route('admin.products.import')" variant="secondary" icon="login">
                                Import dari Excel
                            </x-ui.button>
                        @endcan
                    </div>
                </x-slot>
            </x-ui.empty-state>
        </div>
    @else
        {{--
            Seluruh daftar berada di dalam satu form: centang beberapa barang,
            ubah batas menipisnya sekaligus.

            Yang bisa diubah massal sengaja hanya batas menipis. Batas adalah
            setelan kapan barang mulai disebut menipis; ia tidak pernah menambah
            atau mengurangi saldo, jadi tidak ada angka gudang yang bisa menjadi
            rancu karenanya. Stok sendiri hanya boleh bergerak lewat dokumen.
        --}}
        <form method="POST" action="{{ route('admin.products.bulk.min-stock') }}"
              x-data="productBulkEdit({
                  ids: @js($products->pluck('id')->all()),
                  total: {{ $products->total() }},
                  key: @js(collect(request()->query())->except('page')->sortKeys()->toJson()),
              })"
              {{-- Pilihan yang sudah dipakai tidak perlu diingat lagi. --}}
              x-on:submit="forget()">
            @csrf
            @method('PATCH')

            {{--
                Barang terpilih dikirim dari daftar pilihan, bukan dari kotak
                centang di layar: sebagian di antaranya bisa berada di halaman
                yang sedang tidak terlihat, dan kotak centangnya pun tidak ada
                di dokumen ini.
            --}}
            <template x-for="id in selected" :key="id">
                <input type="hidden" name="ids[]" :value="id">
            </template>

            {{--
                Saringan yang sedang aktif ikut terkirim: ia menentukan barang
                mana saja yang termasuk saat pilihan berlaku untuk seluruh hasil
                saringan, dan sekaligus membawa daftar kembali ke tampilan yang
                sama setelah selesai.
            --}}
            @foreach (['search', 'category', 'stock', 'status', 'type'] as $filter)
                @if (request()->filled($filter))
                    <input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">
                @endif
            @endforeach

            <input type="hidden" name="scope" :value="allFiltered ? 'filtered' : 'selected'">

            @can('products.update')
                {{--
                    Bilah aksi berada di luar kartu daftarnya: kartu itu
                    ber-overflow-hidden, dan di dalamnya `sticky` tidak pernah
                    bekerja karena elemen ber-overflow menjadi wadah gulirnya
                    sendiri.
                --}}
                <div x-show="count > 0" x-cloak
                     class="sticky top-16 z-20 mb-3 flex flex-col gap-3 rounded-2xl border border-ink-950 bg-ink-950 px-5 py-3.5 shadow-lift lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-white">
                            <span x-text="allFiltered
                                ? `Seluruh ${total} barang sesuai saringan dipilih`
                                : `${selected.length} barang dipilih`"></span>
                        </p>

                        {{--
                            Halaman ini memuat sepuluh baris, sedangkan saringan
                            bisa mengenai ratusan. Tanpa jalan memilih seluruh
                            hasil saringan, penyuntingan massal harus diulang
                            sepuluh baris sekali — jadi jalannya disediakan,
                            tetapi sebagai tindakan tersendiri yang jumlahnya
                            disebut, bukan sesuatu yang terjadi diam-diam.
                        --}}
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-white/60">
                            {{-- Yang terpilih di halaman lain tetap disebut; kalau
                                 tidak, jumlahnya terbaca lebih besar dari yang
                                 terlihat tanpa penjelasan. --}}
                            <template x-if="! allFiltered && offPage > 0">
                                <span x-text="`${offPage} di antaranya dari halaman lain`"></span>
                            </template>

                            <template x-if="! allFiltered && pageChosen && total > ids.length">
                                <button type="button" x-on:click="chooseEverything()"
                                        class="font-medium text-white underline underline-offset-4 hover:text-white/80"
                                        x-text="`Pilih seluruh ${total} barang sesuai saringan`"></button>
                            </template>

                            <template x-if="allFiltered">
                                <button type="button" x-on:click="clearAll()"
                                        class="font-medium text-white underline underline-offset-4 hover:text-white/80">
                                    Batalkan pilihan
                                </button>
                            </template>

                            <span>Hanya batas menipis yang diubah — stok tidak tersentuh.</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <label class="flex items-center gap-2 rounded-xl bg-white/10 px-3 py-2 ring-1 ring-inset ring-white/15">
                            <span class="whitespace-nowrap text-xs text-white/60">Batas menipis</span>
                            <input type="number" name="min_stock" min="0" max="999999" required
                                   inputmode="numeric" placeholder="0"
                                   class="w-16 border-0 bg-transparent p-0 text-center text-base font-semibold text-white placeholder:text-white/30 focus:ring-0">
                        </label>

                        <button type="submit"
                                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-white px-4 text-sm font-semibold text-ink-950 transition hover:bg-white/90">
                            <x-icon name="check" class="h-4 w-4" />
                            <span x-text="`Terapkan ke ${count} barang`"></span>
                        </button>

                        <button type="button" x-on:click="clearAll()" title="Batalkan pilihan"
                                class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-white/60 transition hover:bg-white/10 hover:text-white">
                            <x-icon name="close" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            @endcan

            <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            @can('products.update')
                                <th class="w-12 py-3.5 pl-6 pr-0">
                                    <input type="checkbox" :checked="pageChosen" x-on:change="togglePage()"
                                           title="Pilih semua di halaman ini"
                                           class="h-4 w-4 rounded border-ink-300 text-ink-950 focus:ring-ink-950">
                                    <span class="sr-only">Pilih semua barang di halaman ini</span>
                                </th>
                            @endcan
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Kategori</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Lokasi</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Stok</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Status</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($products as $product)
                            <tr class="transition hover:bg-ink-50/50"
                                :class="selected.includes({{ $product->id }}) && 'bg-ink-50/70'">
                                @can('products.update')
                                    <td class="w-12 py-4 pl-6 pr-0 align-top">
                                        <input type="checkbox" value="{{ $product->id }}"
                                               x-model.number="selected" x-on:change="allFiltered = false"
                                               class="mt-0.5 h-4 w-4 rounded border-ink-300 text-ink-950 focus:ring-ink-950">
                                        <span class="sr-only">Pilih {{ $product->name }}</span>
                                    </td>
                                @endcan
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <x-ui.sku :value="$product->sku" />
                                        <p class="truncate text-sm font-medium text-ink-950">{{ $product->name }}</p>
                                        @if ($product->isBundle())
                                            <x-ui.badge variant="dark" icon="sparkles">Paket</x-ui.badge>
                                        @endif
                                    </div>
                                    @if ($product->barcode)
                                        <p class="mt-1 font-mono text-[11px] text-ink-400">Barcode {{ $product->barcode }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-ink-600">{{ $product->category ?: '—' }}</td>
                                <td class="px-6 py-4 font-mono text-xs text-ink-500">{{ $product->location ?: '—' }}</td>
                                <td class="px-6 py-4 text-right">
                                    {{-- Paket tidak punya saldo; yang berarti baginya adalah berapa
                                         yang masih bisa dirakit dari komponen yang tersedia. --}}
                                    <span class="text-sm font-semibold text-ink-950">{{ number_format($product->availableStock(), 0, ',', '.') }}</span>
                                    <span class="text-xs text-ink-400">{{ $product->unit }}</span>
                                    <p class="text-[11px] text-ink-400">
                                        {{ $product->isBundle() ? 'dari komponen' : 'min. '.$product->min_stock }}
                                    </p>
                                </td>
                                <td class="px-6 py-4">
                                    <x-ui.stock-badge :product="$product" />
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.products.show', $product) }}" title="Kartu stok"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="document" class="h-4 w-4" />
                                        </a>
                                        @can('products.update')
                                            <a href="{{ route('admin.products.edit', $product) }}" title="Ubah"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                            </a>
                                        @endcan
                                        @can('products.delete')
                                            <x-ui.confirm-delete :action="route('admin.products.destroy', $product)"
                                                                 title="Hapus barang ini?"
                                                                 :description="$product->name.' akan dihapus dari master data.'" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>

                <div class="divide-y divide-ink-50 md:hidden">
                    @foreach ($products as $product)
                        <div class="flex items-start gap-3 p-4"
                             :class="selected.includes({{ $product->id }}) && 'bg-ink-50/70'">
                            @can('products.update')
                                <label class="mt-1 shrink-0">
                                    <input type="checkbox" value="{{ $product->id }}"
                                           x-model.number="selected" x-on:change="allFiltered = false"
                                           class="h-4 w-4 rounded border-ink-300 text-ink-950 focus:ring-ink-950">
                                    <span class="sr-only">Pilih {{ $product->name }}</span>
                                </label>
                            @endcan

                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <x-ui.sku :value="$product->sku" />
                                            @if ($product->isBundle())
                                                <x-ui.badge variant="dark" icon="sparkles">Paket</x-ui.badge>
                                            @endif
                                        </div>
                                        <p class="mt-1 truncate text-sm font-semibold text-ink-950">{{ $product->name }}</p>
                                    </div>
                                    <x-ui.stock-badge :product="$product" />
                                </div>

                                <div class="mt-3 flex items-end justify-between gap-3">
                                    <div>
                                        <p class="text-2xl font-semibold tracking-tight text-ink-950">
                                            {{ number_format($product->availableStock(), 0, ',', '.') }}
                                            <span class="text-xs font-normal text-ink-400">{{ $product->unit }}</span>
                                        </p>
                                        <p class="text-[11px] text-ink-400">
                                            @if ($product->isBundle())
                                                bisa dirakit dari komponen
                                            @else
                                                min. {{ $product->min_stock }} &middot; {{ $product->location ?: 'tanpa lokasi' }}
                                            @endif
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-1">
                                        <x-ui.button :href="route('admin.products.show', $product)" variant="ghost" size="sm" icon="document">Kartu</x-ui.button>
                                        @can('products.update')
                                            <x-ui.button :href="route('admin.products.edit', $product)" variant="secondary" size="sm" icon="pencil">Ubah</x-ui.button>
                                        @endcan
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <x-ui.pagination :paginator="$products" />
            </div>
        </form>
    @endif
</x-app-layout>
