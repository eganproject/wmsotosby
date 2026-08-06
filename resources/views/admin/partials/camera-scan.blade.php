{{--
    Tombol kamera beserta layar pemindaiannya.

    Dipakai di ketiga layar scan. Hasil bacaannya dikirim sebagai event
    `camera-scan`, jadi halaman induk cukup mengisi kolom kodenya sendiri —
    tidak ada ketergantungan dua arah antara komponen ini dan stasiunnya.

    Kameranya dibiarkan menyala setelah satu kode terbaca supaya beberapa
    barang bisa dipindai berturut-turut tanpa membuka ulang.
--}}
<div x-data="cameraScanner()" x-on:camera-scan.window="last.code = $event.detail.code">
    {{--
        Tingginya mengikuti baris tempat tombol ini berada (self-stretch),
        supaya sejajar dengan kolom input di halaman mana pun — kolomnya
        setinggi 14 di stasiun dan 20 di halaman scan dokumen.

        Warnanya bisa ditimpa lewat $cameraButtonClass: sebagian panel scan
        berlatar gelap, sebagian berlatar putih, dan tombol yang sama di
        keduanya membuat salah satunya tidak terbaca.
    --}}
    <button type="button" x-on:click="start()"
            title="Pindai dengan kamera"
            class="inline-flex shrink-0 items-center justify-center gap-2 self-stretch rounded-2xl px-3.5 text-sm font-semibold transition sm:px-4 lg:hidden
                   {{ $cameraButtonClass ?? 'bg-white/10 text-white ring-1 ring-inset ring-white/15 hover:bg-white/20' }}">
        {{--
            Ikon kamera, bukan kaca pembesar. Kaca pembesar sudah dipakai
            kolom di sebelahnya untuk arti yang berbeda — "cari kode ini" —
            sehingga dua tombol bersebelahan terlihat mengerjakan hal sama.
            Di layar tersempit hanya ikonnya, supaya kolom kode tetap lapang.
        --}}
        <x-icon name="camera" class="h-5 w-5 shrink-0" />
        <span class="hidden sm:inline">Kamera</span>
        <span class="sr-only">Pindai dengan kamera</span>
    </button>

    {{-- Layar pemindaian menutupi seluruh layar: yang dilihat hanya kamera. --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak
             x-on:keydown.escape.window="stop()"
             class="fixed inset-0 z-[60] flex flex-col bg-ink-950">

            {{--
                Kepala layar: apa yang harus dipindai SEKARANG.

                Ini bagian yang paling menentukan. Rail langkah dan judul tahap
                ada di halaman induk, tetapi kamera menutupinya — sehingga
                setelah resi terbaca operator tidak tahu apakah harus lanjut ke
                barang, atau paketnya memang tidak bisa dilanjutkan. Keduanya
                dibawa masuk ke sini, dengan warna yang berganti mengikuti
                tahap agar terbaca sekilas tanpa perlu dibaca kata demi kata.
            --}}
            <div class="shrink-0 px-4 py-3 pt-[max(0.75rem,env(safe-area-inset-top))] transition-colors"
                 @isset($scanStep) :class="({{ $scanStep }}) > 1 ? 'bg-amber-500' : 'bg-ink-900'" @endisset>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        @isset($scanStep)
                            <div class="flex items-center gap-1.5">
                                @foreach ([1, 2] as $number)
                                    <span class="h-1.5 w-8 rounded-full transition"
                                          :class="({{ $scanStep }}) >= {{ $number }} ? 'bg-white' : 'bg-white/25'"></span>
                                @endforeach
                                <span class="ml-1 text-[11px] font-semibold uppercase tracking-wider text-white/70"
                                      x-text="`Langkah ${ {{ $scanStep }} } dari 2`"></span>
                            </div>
                        @endisset

                        <p class="mt-1.5 truncate text-lg font-semibold leading-tight text-white"
                           @isset($scanTitle) x-text="{{ $scanTitle }}" @endisset>
                            @empty($scanTitle) Pindai dengan Kamera @endempty
                        </p>
                    </div>

                    <button type="button" x-on:click="stop()"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/15 text-white transition hover:bg-white/25">
                        <x-icon name="close" class="h-5 w-5" />
                        <span class="sr-only">Tutup</span>
                    </button>
                </div>
            </div>

            <div class="relative min-h-0 flex-1 overflow-hidden">
                <video x-ref="video" playsinline muted autoplay
                       class="h-full w-full object-cover"></video>

                {{-- Bingkai bidik: memberi tahu ke mana kode harus diarahkan. --}}
                <div x-show="! error" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div class="h-48 w-72 max-w-[80vw] rounded-2xl border-2 border-white/70 shadow-[0_0_0_100vmax_rgba(10,10,10,0.45)]"></div>
                </div>

                <div x-show="busy" x-cloak class="absolute inset-0 flex items-center justify-center">
                    <span class="h-8 w-8 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                </div>

                <template x-if="error">
                    <div class="absolute inset-0 flex items-center justify-center px-8">
                        <div class="rounded-2xl bg-white/10 p-5 text-center ring-1 ring-inset ring-white/15">
                            <x-icon name="x-circle" class="mx-auto h-8 w-8 text-red-300" />
                            <p class="mt-3 text-sm leading-relaxed text-white" x-text="error"></p>
                        </div>
                    </div>
                </template>
            </div>

            {{--
                Bagian terpenting layar ini.

                Kamera menutupi seluruh halaman, jadi seluruh umpan balik
                stasiun — pesan hasil scan, langkah berikutnya, sisa barang —
                tidak terlihat di belakangnya. Tanpa bagian ini operator
                memindai tanpa tahu apakah bacaannya diterima.

                Nilai `feedback` diambil dari komponen stasiun yang membungkus
                partial ini; Alpine mewariskan lingkupnya ke komponen anak.
            --}}
            {{--
                Daftar barang yang harus dipindai, versi ringkas.

                Tanpa ini operator tahu "sekarang scan barang" tetapi tidak
                tahu barang apa dan berapa lagi — informasi yang ada di halaman
                induk namun tertutup kamera.
            --}}
            @isset($scanLines)
                <div class="max-h-32 shrink-0 overflow-y-auto border-t border-white/10 bg-ink-900/80">
                    <template x-for="line in {{ $scanLines }}" :key="line.id">
                        <div class="flex items-center gap-2.5 border-b border-white/5 px-4 py-2">
                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px]"
                                  :class="line.completed ? 'bg-emerald-500 text-white' : 'bg-white/15 text-white/60'">
                                <template x-if="line.completed"><x-icon name="check" class="h-3 w-3" /></template>
                            </span>

                            <p class="min-w-0 flex-1 truncate font-mono text-xs"
                               :class="line.completed ? 'text-white/40 line-through' : 'text-white'"
                               x-text="line.sku"></p>

                            {{--
                                Stasiun packing punya target per baris, jadi
                                terbaca "2/3". Retur tidak: yang tercatat
                                adalah apa yang ditemukan, bukan yang ditagih.
                            --}}
                            <p class="shrink-0 text-xs font-semibold tabular-nums"
                               :class="line.completed ? 'text-emerald-300' : 'text-white'"
                               x-text="line.label ?? `${line.scanned}/${line.quantity}`"></p>
                        </div>
                    </template>
                </div>
            @endisset

            <div class="shrink-0 space-y-3 px-4 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
                <div class="flex min-h-[4.5rem] items-start gap-3 rounded-2xl px-4 py-3 transition"
                     :class="feedback
                        ? (feedback.type === 'success'
                            ? 'bg-emerald-500 ring-1 ring-inset ring-emerald-300/40'
                            : 'bg-red-600 ring-1 ring-inset ring-red-300/40')
                        : 'bg-white/[0.06]'">
                    <template x-if="feedback && feedback.type === 'success'">
                        <x-icon name="check-circle" class="mt-0.5 h-6 w-6 shrink-0 text-white" />
                    </template>
                    <template x-if="feedback && feedback.type === 'error'">
                        <x-icon name="x-circle" class="mt-0.5 h-6 w-6 shrink-0 text-white" />
                    </template>
                    <template x-if="! feedback">
                        <x-icon name="camera" class="mt-0.5 h-6 w-6 shrink-0 text-white/30" />
                    </template>

                    {{--
                        Satu baris saja saat ada hasil.

                        Petunjuk arah dan kode terbaca sudah diulang oleh
                        daftar barang di atasnya; menumpuknya di sini membuat
                        operator harus membaca tiga baris untuk mengetahui
                        satu hal. Petunjuk hanya muncul selagi belum ada hasil,
                        yaitu saat kamera baru dibuka.
                    --}}
                    <div class="min-w-0 flex-1 self-center">
                        <p class="text-base font-semibold leading-snug"
                           :class="feedback ? 'text-white' : 'text-white/40'"
                           x-text="feedback ? feedback.message : 'Arahkan kamera ke kode'"></p>

                        @isset($scanHint)
                            <p class="mt-1 text-xs text-white/70" x-show="! feedback" x-cloak
                               x-text="{{ $scanHint }}"></p>
                        @endisset
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" x-on:click="toggleTorch()" x-show="stream"
                            class="inline-flex h-12 flex-1 items-center justify-center gap-2 rounded-2xl text-sm font-semibold transition"
                            :class="torchOn ? 'bg-white text-ink-950' : 'bg-white/10 text-white ring-1 ring-inset ring-white/15'">
                        <x-icon name="sparkles" class="h-4 w-4" />
                        <span x-text="torchOn ? 'Lampu Menyala' : 'Nyalakan Lampu'"></span>
                    </button>

                    <button type="button" x-on:click="stop()"
                            class="inline-flex h-12 flex-1 items-center justify-center rounded-2xl bg-white text-sm font-semibold text-ink-950 transition hover:bg-white/90">
                        Selesai
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
