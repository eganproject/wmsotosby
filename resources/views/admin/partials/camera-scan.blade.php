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
    --}}
    <button type="button" x-on:click="start()"
            title="Pindai dengan kamera"
            class="inline-flex shrink-0 items-center justify-center gap-2 self-stretch rounded-2xl bg-white/10 px-3.5 text-sm font-semibold text-white ring-1 ring-inset ring-white/15 transition hover:bg-white/20 sm:px-4 lg:hidden">
        {{-- Di layar tersempit hanya ikon, supaya kolom kode tetap lapang. --}}
        <x-icon name="search" class="h-5 w-5 shrink-0" />
        <span class="hidden sm:inline">Kamera</span>
        <span class="sr-only">Pindai dengan kamera</span>
    </button>

    {{-- Layar pemindaian menutupi seluruh layar: yang dilihat hanya kamera. --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak
             x-on:keydown.escape.window="stop()"
             class="fixed inset-0 z-[60] flex flex-col bg-ink-950">

            <div class="flex shrink-0 items-center justify-between gap-3 px-4 py-3 pt-[max(0.75rem,env(safe-area-inset-top))]">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-white">Pindai dengan Kamera</p>
                    <p class="truncate text-[11px] text-white/50">
                        Arahkan ke barcode atau QR. Kamera tetap menyala untuk kode berikutnya.
                    </p>
                </div>

                <button type="button" x-on:click="stop()"
                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20">
                    <x-icon name="close" class="h-5 w-5" />
                    <span class="sr-only">Tutup</span>
                </button>
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
                        <x-icon name="search" class="mt-0.5 h-6 w-6 shrink-0 text-white/30" />
                    </template>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold leading-snug"
                           :class="feedback ? 'text-white' : 'text-white/40'"
                           x-text="feedback ? feedback.message : 'Arahkan kamera ke kode. Hasilnya muncul di sini.'"></p>

                        @isset($scanHint)
                            <p class="mt-1 text-xs text-white/70" x-text="{{ $scanHint }}"></p>
                        @endisset

                        <p class="mt-1 truncate font-mono text-[11px]"
                           :class="feedback ? 'text-white/60' : 'text-white/25'"
                           x-show="last.code" x-cloak x-text="last.code"></p>
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
