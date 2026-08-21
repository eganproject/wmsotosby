{{--
    Stasiun hitung stok opname.

    Petugas berjalan di gudang membawa layar ini: scan barang, ketik jumlah,
    lanjut ke rak berikutnya. Tidak ada daftar yang harus digulir dan tidak ada
    halaman yang dimuat ulang di antara dua barang.

    Saldo tercatat sengaja tidak muncul di mana pun. Angka sistem yang terlihat
    membuat petugas menyalinnya alih-alih menghitung, dan opname kehilangan
    satu-satunya gunanya: mengetahui isi rak yang sebenarnya.
--}}
<x-app-layout title="Stasiun Hitung">
    <x-ui.page-header :title="$opname->code"
                      :subtitle="$opname->scopeLabel().' · '.$opname->date->translatedFormat('d F Y')"
                      :back="route('admin.opnames.index')">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.opnames.show', $opname)" variant="secondary" icon="document">
                Daftar &amp; Hasil
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <div x-data="opnameStation({
            urls: {
                lookup: '{{ route('admin.opnames.station.lookup', $opname) }}',
                count: '{{ route('admin.opnames.station.count', $opname) }}',
                progress: '{{ route('admin.opnames.station.progress', $opname) }}',
            },
            progress: {{ Js::from($progress) }},
         })"
         x-on:camera-scan.window="code = $event.detail.code; submit()"
         class="mx-auto max-w-3xl">

        {{-- Panel hitung: satu kolom untuk kode maupun jumlah. --}}
        <div class="overflow-hidden rounded-3xl border border-ink-950 bg-ink-950 shadow-lift">
            <div class="px-6 pt-7 text-center sm:px-10">
                <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-white/70">
                    <span x-text="`${progress.counted} dari ${progress.total} SKU dihitung`"></span>
                </span>

                <h1 class="mt-4 text-2xl font-semibold tracking-tight text-white sm:text-3xl" x-text="title"></h1>

                <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed"
                   :class="conflict ? 'text-amber-300' : 'text-white/60'"
                   x-text="hint"></p>
            </div>

            <div class="p-6 sm:p-10 sm:pt-7">
                <div class="flex items-stretch gap-2">
                    @include('admin.partials.camera-scan', [
                        'scanTitle' => "isOpen ? 'Isi jumlah untuk ' + card.sku : 'Scan barcode barang'",
                        'scanHint' => "progress.remaining + ' SKU lagi belum dihitung.'",
                    ])

                    <form data-no-ajax @submit.prevent="submit()" class="min-w-0 flex-1">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-16 items-center justify-center text-white/30">
                                <x-icon name="search" class="h-7 w-7" />
                            </span>

                            <input x-ref="input" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                   spellcheck="false" :disabled="busy" :placeholder="placeholder"
                                   class="block h-20 w-full rounded-2xl border-0 bg-white/10 pl-16 pr-32 font-mono text-xl tracking-wider text-white placeholder:font-sans placeholder:text-base placeholder:tracking-normal placeholder:text-white/30 ring-1 ring-inset ring-white/15 transition focus:bg-white/[0.15] focus:ring-2 focus:ring-white disabled:opacity-60">

                            <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                                <button type="submit" :disabled="busy"
                                        class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-white px-5 text-sm font-semibold text-ink-950 transition hover:bg-white/90 disabled:opacity-40">
                                    <span x-show="! busy" x-text="actionLabel"></span>
                                    <span x-show="busy" x-cloak class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-ink-950/30 border-t-ink-950"></span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- Kartu barang yang sedang dihitung. --}}
                <template x-if="card">
                    <div class="mt-5 rounded-2xl bg-white/[0.06] p-5 ring-1 ring-inset ring-white/15">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-semibold tracking-wider text-white" x-text="card.sku"></p>
                                <p class="mt-1 text-sm text-white/70" x-text="card.name"></p>

                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <template x-if="card.location">
                                        <span class="inline-flex items-center gap-1 rounded-lg bg-white/10 px-2 py-1 text-[11px] text-white/70">
                                            <x-icon name="box" class="h-3 w-3" />
                                            <span x-text="card.location"></span>
                                        </span>
                                    </template>
                                    <template x-if="card.category">
                                        <span class="rounded-lg bg-white/10 px-2 py-1 text-[11px] text-white/70" x-text="card.category"></span>
                                    </template>
                                </div>
                            </div>

                            <button type="button" x-on:click="cancel()"
                                    class="inline-flex h-9 items-center rounded-lg px-3 text-xs font-medium text-white/60 transition hover:bg-white/10 hover:text-white">
                                Batal
                            </button>
                        </div>

                        {{--
                            Barang yang ditemukan di rak tetapi tidak ikut
                            terpotret saat sesi dibuka. Temuannya tidak dibuang:
                            barisnya ditambahkan begitu jumlahnya disimpan.
                        --}}
                        <template x-if="isOutOfScope">
                            <p class="mt-4 flex items-start gap-2 rounded-xl bg-amber-400/10 px-3 py-2.5 text-xs leading-relaxed text-amber-200 ring-1 ring-inset ring-amber-400/30">
                                <x-icon name="warning" class="mt-px h-4 w-4 shrink-0" />
                                <span>Barang ini di luar cakupan sesi. Menyimpan hitungannya sekaligus menambahkan barisnya ke sesi ini.</span>
                            </p>
                        </template>

                        {{--
                            Kabar yang datang selagi kartunya terbuka: rekan
                            menghitung barang yang sama. Diberitahukan sekarang,
                            supaya petugas bisa memutuskan sebelum mengetik —
                            bukan setelah raknya ditinggalkan.
                        --}}
                        <template x-if="taken && ! conflict">
                            <p class="mt-4 flex items-start gap-2 rounded-xl bg-amber-400/10 px-3 py-2.5 text-xs leading-relaxed text-amber-200 ring-1 ring-inset ring-amber-400/30">
                                <x-icon name="users" class="mt-px h-4 w-4 shrink-0" />
                                <span x-text="taken"></span>
                            </p>
                        </template>

                        {{--
                            Bentrokan dengan rekan sesama petugas: keputusannya
                            diserahkan ke yang sedang berdiri di depan rak.
                        --}}
                        <template x-if="conflict">
                            <div class="mt-4 rounded-xl bg-amber-400/10 p-3 ring-1 ring-inset ring-amber-400/30">
                                <p class="text-xs leading-relaxed text-amber-200" x-text="conflict"></p>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button type="button" x-on:click="overwrite()"
                                            class="inline-flex h-9 items-center rounded-lg bg-amber-400 px-3 text-xs font-semibold text-ink-950 transition hover:bg-amber-300">
                                        <span x-text="`Timpa dengan ${counted === '' ? 'kosong' : counted}`"></span>
                                    </button>
                                    <button type="button" x-on:click="keepTheirs()"
                                            class="inline-flex h-9 items-center rounded-lg bg-white/10 px-3 text-xs font-semibold text-white transition hover:bg-white/20">
                                        Pakai angka rekan
                                    </button>
                                </div>
                            </div>
                        </template>

                        {{--
                            Kolomnya untuk yang memakai sentuhan. Dengan scanner
                            dan papan ketik, jumlahnya cukup diketik di kolom
                            kode di atas — fokusnya tidak perlu berpindah.
                        --}}
                        <div class="mt-4 flex gap-3">
                            <div class="flex-1">
                                <label for="station-counted"
                                       class="mb-1 block text-[10px] font-medium uppercase tracking-wider text-white/50">Bagus</label>
                                <input id="station-counted" x-model="counted" type="number" min="0" max="999999"
                                       inputmode="numeric" placeholder="—"
                                       @keydown.enter.prevent="save()"
                                       class="h-12 w-full rounded-xl border-0 bg-white/10 text-center text-base font-semibold tabular-nums text-white ring-1 ring-inset ring-white/15 focus:ring-2 focus:ring-white">
                            </div>

                            {{--
                                Rusak dicatat terpisah, bukan dikurangkan dari
                                hitungan bagus: selisih yang tidak dijelaskan
                                terbaca sebagai barang hilang, padahal hilang
                                perlu diselidiki dan rusak bisa diklaim.
                            --}}
                            <div class="flex-1">
                                <label for="station-damaged"
                                       class="mb-1 block text-[10px] font-medium uppercase tracking-wider text-red-300">Rusak</label>
                                <input id="station-damaged" x-model="damaged" type="number" min="0" max="999999"
                                       inputmode="numeric" placeholder="0"
                                       @keydown.enter.prevent="save()"
                                       class="h-12 w-full rounded-xl border-0 bg-white/10 text-center text-base font-semibold tabular-nums text-red-200 ring-1 ring-inset ring-white/15 focus:ring-2 focus:ring-red-300">
                            </div>

                            <div class="flex items-end">
                                <button type="button" x-on:click="save()" :disabled="busy"
                                        class="inline-flex h-12 items-center gap-1.5 rounded-xl bg-white px-5 text-sm font-semibold text-ink-950 transition hover:bg-white/90 disabled:opacity-40">
                                    <x-icon name="check" class="h-4 w-4" />
                                    <span x-text="isOutOfScope ? 'Tambah &amp; Simpan' : 'Simpan'"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                {{-- Hasil scan terakhir. --}}
                <template x-if="feedback">
                    <div class="mt-5 flex items-start gap-3 rounded-2xl p-4 ring-1 ring-inset"
                         :class="feedback.type === 'success'
                            ? 'bg-emerald-400/10 ring-emerald-400/30'
                            : 'bg-red-500/10 ring-red-400/30'">
                        <template x-if="feedback.type === 'success'">
                            <x-icon name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-400" />
                        </template>
                        <template x-if="feedback.type === 'error'">
                            <x-icon name="x-circle" class="mt-0.5 h-5 w-5 shrink-0 text-red-400" />
                        </template>
                        <p class="text-sm font-medium"
                           :class="feedback.type === 'success' ? 'text-emerald-200' : 'text-red-200'"
                           x-text="feedback.message"></p>
                    </div>
                </template>

                {{-- Kemajuan sesi, bukan selisihnya. --}}
                <div class="mt-6">
                    <div class="flex items-baseline justify-between gap-3 text-xs text-white/50">
                        <span>Kemajuan sesi</span>
                        <span class="tabular-nums" x-text="`${progress.percentage}%`"></span>
                    </div>
                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-white/10">
                        <div class="h-full rounded-full bg-white transition-all duration-500"
                             :style="`width: ${progress.percentage}%`"></div>
                    </div>
                    <p class="mt-2 text-[11px] text-white/40">
                        <span x-text="`Anda menghitung ${progress.mine} SKU`"></span>
                        &middot;
                        <span x-text="`${progress.remaining} belum dihitung siapa pun`"></span>
                    </p>
                </div>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
            {{--
                Riwayat: satu SKU satu baris. Scan ulang mengganti angkanya,
                jadi tidak pernah ada dua hitungan untuk barang yang sama —
                dan salah ketik cukup dipanggil ulang dari sini.
            --}}
            <x-ui.card title="Baru Saja Dihitung" subtitle="Tekan barisnya untuk menghitung ulang">
                <template x-if="! history.length">
                    <p class="py-6 text-center text-xs text-ink-400">Belum ada barang yang dihitung di layar ini.</p>
                </template>

                <ul class="divide-y divide-ink-50">
                    <template x-for="entry in history" :key="entry.id">
                        <li>
                            <button type="button" x-on:click="recount(entry)"
                                    class="flex w-full items-center gap-3 py-3 text-left transition hover:bg-ink-50/60">
                                <div class="min-w-0 flex-1">
                                    <p class="font-mono text-xs font-semibold text-ink-950" x-text="entry.sku"></p>
                                    <p class="truncate text-[11px] text-ink-400" x-text="entry.name"></p>
                                </div>

                                <div class="shrink-0 text-right">
                                    <p class="text-sm font-semibold tabular-nums text-ink-950">
                                        <span x-text="entry.counted === null ? '—' : entry.counted"></span>
                                        <span class="text-[11px] font-normal text-ink-400" x-text="entry.unit"></span>
                                    </p>
                                    <p class="text-[10px] text-ink-400">
                                        <span x-text="entry.at"></span>
                                        <template x-if="entry.damaged > 0">
                                            <span class="text-red-500" x-text="` · ${entry.damaged} rusak`"></span>
                                        </template>
                                    </p>
                                </div>
                            </button>
                        </li>
                    </template>
                </ul>
            </x-ui.card>

            {{--
                Siapa lagi yang sedang mengerjakan batch ini. Tanpa panel ini
                dua petugas bisa menyisir rak yang sama sepanjang pagi tanpa
                pernah tahu — dan bentrokannya baru ketahuan saat menyimpan.
            --}}
            <x-ui.card title="Petugas di Sesi Ini" subtitle="Ikut berubah saat rekan menghitung">
                <x-slot name="actions">
                    {{-- Penanda bahwa angkanya hidup, bukan potret saat halaman dibuka. --}}
                    <span class="inline-flex items-center gap-1.5 text-[11px] font-medium"
                          :class="polling ? 'text-emerald-600' : 'text-ink-400'">
                        <span class="relative flex h-2 w-2">
                            <span x-show="polling" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full"
                                  :class="polling ? 'bg-emerald-500' : 'bg-ink-300'"></span>
                        </span>
                        Langsung
                    </span>
                </x-slot>

                <template x-if="! progress.counters.length">
                    <p class="py-6 text-center text-xs text-ink-400">Belum ada yang menghitung di sesi ini.</p>
                </template>

                <ul class="divide-y divide-ink-50">
                    <template x-for="counter in progress.counters" :key="counter.name">
                        <li class="flex items-center gap-3 py-3">
                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ink-100 text-[11px] font-semibold uppercase text-ink-600"
                                  x-text="counter.name.slice(0, 2)"></span>

                            <p class="min-w-0 flex-1 truncate text-sm font-medium text-ink-950" x-text="counter.name"></p>

                            <span class="shrink-0 rounded-lg bg-ink-50 px-2 py-1 text-xs font-semibold tabular-nums text-ink-700"
                                  x-text="`${counter.counted} SKU`"></span>
                        </li>
                    </template>
                </ul>

                <p class="mt-4 border-t border-ink-100 pt-3 text-[11px] leading-relaxed text-ink-400">
                    Angkanya disegarkan sendiri tiap sepuluh detik selama layar ini terlihat.
                    Bila rekan menghitung barang yang sedang Anda pegang, kabarnya muncul di
                    kartu sebelum Anda sempat mengetik.
                </p>
            </x-ui.card>
        </div>

        <p class="mt-5 text-center text-xs text-ink-400">
            Selesai menghitung?
            <a href="{{ route('admin.opnames.show', $opname) }}" class="font-medium text-ink-950 underline underline-offset-2">
                Buka daftar hasil
            </a>
            untuk memeriksa sisa yang belum dihitung dan mengajukannya.
        </p>
    </div>
</x-app-layout>
