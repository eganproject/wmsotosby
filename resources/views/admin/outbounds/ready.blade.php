{{--
    Antrean paket yang isinya sudah diverifikasi di stasiun packing tetapi
    belum dikirim.

    Ada dua cara memprosesnya, dan keduanya memang perlu ada:

    - Memindai resi, di titik serah ke kurir. Yang tercatat terkirim adalah
      paket yang benar-benar ada di tangan.
    - Mencentang daftar, untuk borongan yang sudah pasti berangkat semua.

    Keduanya berbagi satu lingkup Alpine supaya paket yang barusan discan
    langsung hilang dari daftar sekaligus lepas dari centangnya.
--}}
<x-app-layout title="Siap Dikirim">
    <x-ui.page-header title="Siap Dikirim" icon="logout"
                      subtitle="Paket yang sudah lengkap discan dan tinggal diproses pengirimannya.">
    </x-ui.page-header>

    <x-ui.tabs group="outbound" />

    <div x-data="dispatchStation({
             url: '{{ route('admin.outbounds.ready.scan') }}',
             remaining: {{ $readyCount }},
             queued: @js($outbounds->pluck('id')->all()),
         })"
         {{-- Hasil pindaian kamera diperlakukan sama persis dengan scanner genggam. --}}
         x-on:camera-scan.window="code = $event.detail.code; submit()">

        @can('outbounds.post')
            {{-- ─────────────── Panel scan resi ─────────────── --}}
            <div class="mb-4 overflow-hidden rounded-3xl border border-ink-950 bg-ink-950 shadow-lift">
                <div class="flex items-start justify-between gap-4 px-5 pt-5 sm:px-6 sm:pt-6">
                    <div class="min-w-0">
                        <p class="text-[11px] font-medium uppercase tracking-wider text-white/50">
                            Serah ke kurir
                        </p>
                        <h2 class="mt-1 text-xl font-semibold tracking-tight text-white sm:text-2xl">
                            Scan resi untuk mengirim
                        </h2>
                        <p class="mt-1 text-xs leading-relaxed text-white/60">
                            {{ auth()->user()->can('outbounds.approve')
                                ? 'Paket yang resinya discan langsung dikirim dan stoknya berkurang.'
                                : 'Paket yang resinya discan diajukan untuk disetujui.' }}
                        </p>
                    </div>

                    <dl class="flex shrink-0 items-start gap-4 text-right sm:gap-5">
                        <div>
                            <dd class="text-lg font-semibold leading-tight text-emerald-400" x-text="sent.length"></dd>
                            <dt class="text-[10px] uppercase tracking-wider text-white/40">dikirim</dt>
                        </div>
                        <div>
                            <dd class="text-lg font-semibold leading-tight text-white" x-text="remaining"></dd>
                            <dt class="text-[10px] uppercase tracking-wider text-white/40">antre</dt>
                        </div>
                    </dl>
                </div>

                <div class="flex items-stretch gap-2 px-5 pt-4 sm:px-6">
                    @include('admin.partials.camera-scan', [
                        'scanTitle' => "'Scan resi untuk mengirim'",
                        'scanHint' => "remaining + ' paket menunggu di antrean.'",
                    ])

                    <form data-no-ajax @submit.prevent="submit()" class="min-w-0 flex-1">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-12 items-center justify-center text-white/30">
                                <x-icon name="search" class="h-5 w-5" />
                            </span>

                            <input x-ref="input" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                   spellcheck="false" :disabled="busy"
                                   placeholder="Scan atau ketik nomor resi…"
                                   class="block h-14 w-full rounded-2xl border-0 bg-white/10 pl-12 pr-24 font-mono text-base tracking-wide text-white placeholder:font-sans placeholder:text-sm placeholder:tracking-normal placeholder:text-white/30 ring-1 ring-inset ring-white/15 transition focus:bg-white/[0.15] focus:ring-2 focus:ring-white disabled:opacity-40">

                            <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                <button type="submit" :disabled="busy || ! code.trim()"
                                        class="inline-flex h-10 items-center justify-center rounded-xl bg-white px-4 text-sm font-semibold text-ink-950 transition hover:bg-white/90 disabled:opacity-40">
                                    <span x-show="! busy">Kirim</span>
                                    <span x-show="busy" x-cloak class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-ink-950/30 border-t-ink-950"></span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- Ruang umpan balik: selalu ada, hanya isinya berganti. --}}
                <div class="px-5 pb-5 pt-3 sm:px-6">
                    <div class="flex min-h-[3.25rem] items-center gap-2.5 rounded-xl px-3 py-2 transition"
                         :class="feedback
                            ? (feedback.type === 'success' ? 'bg-emerald-400/15 ring-1 ring-inset ring-emerald-400/25' : 'bg-red-500/15 ring-1 ring-inset ring-red-400/25')
                            : 'bg-white/[0.04]'">
                        <template x-if="feedback && feedback.type === 'success'">
                            <x-icon name="check-circle" class="h-4 w-4 shrink-0 text-emerald-300" />
                        </template>
                        <template x-if="feedback && feedback.type === 'error'">
                            <x-icon name="x-circle" class="h-4 w-4 shrink-0 text-red-300" />
                        </template>

                        <p class="overflow-hidden text-xs leading-4"
                           :class="feedback
                                ? (feedback.type === 'success' ? 'text-emerald-100' : 'text-red-100')
                                : 'text-white/25'"
                           x-text="feedback ? feedback.message : 'Hasil scan muncul di sini.'"></p>
                    </div>
                </div>
            </div>

            {{-- ─────────────── Terkirim pada sesi ini ─────────────── --}}
            <div x-show="sent.length" x-cloak
                 class="mb-4 overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-card">
                <div class="flex items-center justify-between gap-3 border-b border-emerald-100 bg-emerald-50/70 px-5 py-2.5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Terkirim Sesi Ini</p>
                    <p class="text-xs text-emerald-700" x-text="`${sent.length} paket · ${sessionUnits} unit`"></p>
                </div>

                <ul class="scrollbar-thin max-h-44 divide-y divide-ink-50 overflow-y-auto">
                    <template x-for="entry in sent" :key="entry.id">
                        <li class="flex items-center gap-3 px-5 py-2.5">
                            <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                                <x-icon name="check" class="h-3 w-3" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-mono text-xs font-semibold text-ink-950" x-text="entry.tracking_number"></p>
                                <p class="truncate text-[11px] text-ink-400"
                                   x-text="[entry.code, entry.marketplace].filter(Boolean).join(' · ')"></p>
                            </div>
                            <span class="shrink-0 text-[11px] font-semibold"
                                  :class="entry.shipped ? 'text-emerald-600' : 'text-amber-600'"
                                  x-text="entry.shipped ? `${entry.units} unit` : 'menunggu setuju'"></span>
                            <span class="shrink-0 font-mono text-[11px] text-ink-400" x-text="entry.at"></span>
                        </li>
                    </template>
                </ul>
            </div>
        @endcan

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
            {{-- Seluruh daftar berada di dalam satu form: centang beberapa paket, proses sekaligus. --}}
            <form method="POST" action="{{ route('admin.outbounds.ready.process') }}">
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
                        <input type="checkbox" :checked="allChosen" x-on:change="toggleAll()"
                               :disabled="! available.length"
                               class="h-4 w-4 rounded border-ink-300 text-ink-950 focus:ring-ink-950 disabled:opacity-40">
                        <span x-text="selected.length
                            ? `${selected.length} paket dipilih`
                            : `Pilih semua (${available.length} paket)`"></span>
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
                    {{--
                        Muncul saat seluruh isi halaman ini sudah discan satu per
                        satu. Tanpa ini kartunya menyusut menjadi bingkai kosong
                        tanpa penjelasan.
                    --}}
                    <div x-show="! available.length" x-cloak>
                        <x-ui.empty-state icon="check-circle" title="Semua paket di halaman ini sudah dikirim"
                                          description="Muat ulang halaman untuk melihat paket berikutnya dari antrean." />
                    </div>

                    <ul class="divide-y divide-ink-50">
                        @foreach ($outbounds as $outbound)
                            {{--
                                Tautan detail sengaja berada di luar <label>: elemen
                                interaktif di dalam label membuat kliknya ikut
                                mencentang baris, dan itu bukan yang dimaui orang
                                yang menekannya.
                            --}}
                            <li x-show="! isSent({{ $outbound->id }})"
                                class="flex items-start gap-3 px-5 py-4 transition hover:bg-ink-50/50"
                                :class="selected.includes({{ $outbound->id }}) && 'bg-ink-50/70'">
                                <label class="flex min-w-0 flex-1 cursor-pointer items-start gap-3">
                                    <input type="checkbox" name="ids[]" value="{{ $outbound->id }}" x-model.number="selected"
                                           :disabled="isSent({{ $outbound->id }})"
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
    </div>
</x-app-layout>
