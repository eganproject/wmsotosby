{{--
    Tombol kamera beserta layar pemindaiannya.

    Dipakai di ketiga layar scan. Hasil bacaannya dikirim sebagai event
    `camera-scan`, jadi halaman induk cukup mengisi kolom kodenya sendiri —
    tidak ada ketergantungan dua arah antara komponen ini dan stasiunnya.

    Kameranya dibiarkan menyala setelah satu kode terbaca supaya beberapa
    barang bisa dipindai berturut-turut tanpa membuka ulang.
--}}
<div x-data="cameraScanner()" x-on:camera-scan.window="last.code = $event.detail.code">
    <button type="button" x-on:click="start()"
            title="Pindai dengan kamera"
            class="inline-flex h-14 shrink-0 items-center justify-center gap-2 rounded-2xl bg-white/10 px-4 text-sm font-semibold text-white ring-1 ring-inset ring-white/15 transition hover:bg-white/20 lg:hidden">
        <x-icon name="search" class="h-5 w-5" />
        Kamera
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

            {{-- Kode terakhir tampil di sini supaya operator yakin yang terbaca benar. --}}
            <div class="shrink-0 space-y-3 px-4 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
                <div class="flex min-h-[3rem] items-center gap-2.5 rounded-xl px-3 py-2"
                     :class="last.code ? 'bg-emerald-400/15 ring-1 ring-inset ring-emerald-400/25' : 'bg-white/[0.06]'">
                    <template x-if="last.code">
                        <x-icon name="check-circle" class="h-4 w-4 shrink-0 text-emerald-300" />
                    </template>
                    <p class="truncate font-mono text-sm"
                       :class="last.code ? 'text-emerald-100' : 'text-white/30'"
                       x-text="last.code || 'Menunggu kode terbaca…'"></p>
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
