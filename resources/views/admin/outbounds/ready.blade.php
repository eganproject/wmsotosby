{{--
    Antrean paket yang isinya sudah diverifikasi di stasiun packing tetapi
    belum dikirim. Satu layar untuk memproses banyak paket sekaligus, supaya
    stasiun packing tidak pernah berhenti untuk mengurus dokumen.
--}}
<x-app-layout title="Siap Dikirim">
    <x-ui.page-header title="Siap Dikirim" icon="logout"
                      subtitle="Paket yang sudah lengkap discan dan tinggal diproses pengirimannya.">
    </x-ui.page-header>

    <x-ui.tabs group="outbound" />

    <form method="GET" action="{{ route('admin.outbounds.ready') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nomor dokumen, penerima, atau resi..." class="pl-10" />
        </div>

        <x-ui.select name="marketplace" class="sm:w-44">
            <option value="">Semua toko</option>
            @foreach ($marketplaces as $marketplace)
                <option value="{{ $marketplace }}" @selected(request('marketplace') === $marketplace)>{{ $marketplace }}</option>
            @endforeach
        </x-ui.select>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'marketplace']))
                <x-ui.button :href="route('admin.outbounds.ready')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    @if ($outbounds->isEmpty())
        <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
            <x-ui.empty-state icon="check-circle" title="Tidak ada paket menunggu"
                              description="Semua paket yang sudah discan sudah diproses. Paket baru muncul di sini begitu selesai discan di stasiun packing.">
                @can('outbounds.scan')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.outbounds.marketplace')" icon="search">Buka Stasiun Packing</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        </div>
    @else
        {{--
            Seluruh daftar berada di dalam satu form: centang beberapa paket,
            proses sekaligus. Alpine hanya mengurus keadaan centangnya.
        --}}
        <form method="POST" action="{{ route('admin.outbounds.ready.process') }}"
              x-data="{
                  selected: [],
                  get all() { return this.selected.length === {{ $outbounds->count() }} },
                  toggleAll() {
                      this.selected = this.all ? [] : @js($outbounds->pluck('id')->all());
                  },
              }">
            @csrf

            {{--
                Filter yang sedang aktif ikut terkirim.

                Tanpa ini pemrosesan mengandalkan `back()`, yang membaca header
                Referer — dan header itu tidak selalu ada, sedangkan cadangan
                sesinya tidak pernah diperbarui pada navigasi AJAX. Akibatnya
                operator terlempar ke daftar tanpa filter setelah memproses,
                lalu harus menyaring ulang dari awal.
            --}}
            @foreach (['search', 'marketplace'] as $filter)
                @if (request()->filled($filter))
                    <input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">
                @endif
            @endforeach

            {{--
                Bilah aksi berada di luar kartu daftarnya. Sempat diletakkan
                di dalam kartu ber-overflow-hidden, dan di sana `sticky` tidak
                pernah bekerja: elemen ber-overflow menjadi wadah gulirnya
                sendiri, sehingga bilahnya ikut hanyut saat halaman digulir.
            --}}
            <div class="sticky top-16 z-20 mb-3 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-ink-100 bg-white/95 px-5 py-3 shadow-card backdrop-blur">
                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-ink-700">
                    <input type="checkbox" :checked="all" x-on:change="toggleAll()"
                           class="h-4 w-4 rounded border-ink-300 text-ink-950 focus:ring-ink-950">
                    <span x-text="selected.length
                        ? `${selected.length} paket dipilih`
                        : 'Pilih semua ({{ $outbounds->count() }} paket)'"></span>
                </label>

                @can('outbounds.post')
                    <button type="submit" :disabled="! selected.length"
                            class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-ink-950 px-4 text-sm font-semibold text-white transition hover:bg-ink-800 disabled:cursor-not-allowed disabled:opacity-40">
                        <x-icon :name="auth()->user()->can('outbounds.approve') ? 'check' : 'clock'" class="h-4 w-4" />
                        {{ auth()->user()->can('outbounds.approve') ? 'Proses & Kirim' : 'Ajukan Persetujuan' }}
                    </button>
                @endcan
            </div>

            <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                <ul class="divide-y divide-ink-50">
                    @foreach ($outbounds as $outbound)
                        {{--
                            Tautan detail sengaja berada di luar <label>: elemen
                            interaktif di dalam label membuat kliknya ikut
                            mencentang baris, dan itu bukan yang dimaui orang
                            yang menekannya.
                        --}}
                        <li class="flex items-start gap-3 px-5 py-4 transition hover:bg-ink-50/50"
                            :class="selected.includes({{ $outbound->id }}) && 'bg-ink-50/70'">
                            <label class="flex min-w-0 flex-1 cursor-pointer items-start gap-3">
                                <input type="checkbox" name="ids[]" value="{{ $outbound->id }}" x-model.number="selected"
                                       class="mt-1 h-4 w-4 shrink-0 rounded border-ink-300 text-ink-950 focus:ring-ink-950">

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-sm font-semibold text-ink-950">{{ $outbound->code }}</span>
                                        @if ($outbound->marketplace)
                                            <x-ui.badge variant="dark" icon="sparkles">{{ $outbound->marketplace }}</x-ui.badge>
                                        @endif
                                        <x-ui.badge variant="success" icon="check">
                                            {{ number_format((int) $outbound->items_sum_quantity, 0, ',', '.') }} unit discan
                                        </x-ui.badge>
                                    </div>

                                    <p class="mt-1 truncate font-mono text-xs text-ink-500">{{ $outbound->tracking_number }}</p>
                                    <p class="truncate text-xs text-ink-400">
                                        {{ $outbound->recipient }}
                                        &middot; {{ $outbound->items_count }} SKU
                                        &middot; discan {{ $outbound->resi_verified_at?->diffForHumans() }}
                                        @if ($outbound->user) &middot; oleh {{ $outbound->user->name }} @endif
                                    </p>
                                </div>
                            </label>

                            <a href="{{ route('admin.outbounds.show', $outbound) }}" title="Detail dokumen"
                               class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                <x-icon name="eye" class="h-4 w-4" />
                            </a>
                        </li>
                    @endforeach
                </ul>

                <x-ui.pagination :paginator="$outbounds" />
            </div>
        </form>
    @endif
</x-app-layout>
