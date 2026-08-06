{{--
    Stasiun packing pesanan marketplace.

    Tata letaknya sama dengan stasiun retur: rail langkah, panel scan, dan
    daftar barang punya ruang bertinggi tetap. Yang berubah antar tahap hanya
    isinya, sehingga kolom input tidak pernah berpindah posisi.
--}}
<x-app-layout title="Stasiun Packing">
    <div x-data="packingStation({
            url: '{{ route('admin.outbounds.marketplace.store') }}',
            readyUrl: '{{ route('admin.outbounds.ready') }}',
         })"
         {{-- Hasil pindaian kamera diperlakukan sama persis dengan scanner genggam. --}}
         x-on:camera-scan.window="code = $event.detail.code; submit()"
         class="mx-auto max-w-6xl">

        {{-- ─────────────── Rail langkah + ringkasan sesi ─────────────── --}}
        <div class="sticky top-16 z-20 -mx-4 mb-4 border-b border-ink-100 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border sm:shadow-card">
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                <ol class="flex min-w-0 items-center gap-1.5 sm:gap-2">
                    @foreach ([1 => 'Resi', 2 => 'Barang', 3 => 'Selesai'] as $number => $label)
                        @if (! $loop->first)
                            <li aria-hidden="true" class="h-px w-3 shrink-0 bg-ink-200 sm:w-6"></li>
                        @endif

                        <li class="flex shrink-0 items-center gap-1.5">
                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold transition"
                                  :class="step > {{ $number }} ? 'bg-emerald-600 text-white'
                                        : (step === {{ $number }} ? 'bg-ink-950 text-white' : 'bg-ink-100 text-ink-400')">
                                <template x-if="step > {{ $number }}"><x-icon name="check" class="h-3 w-3" /></template>
                                <template x-if="step <= {{ $number }}"><span>{{ $number }}</span></template>
                            </span>
                            <span class="text-xs font-medium transition"
                                  :class="step === {{ $number }} ? 'text-ink-950' : 'text-ink-400'">{{ $label }}</span>
                        </li>
                    @endforeach
                </ol>

                <dl class="flex shrink-0 items-center gap-4 text-right sm:gap-5">
                    <div>
                        <dd class="text-base font-semibold leading-tight text-ink-950" x-text="completed.length"></dd>
                        <dt class="text-[10px] uppercase tracking-wider text-ink-400">paket</dt>
                    </div>
                    <div>
                        <dd class="text-base font-semibold leading-tight text-emerald-600" x-text="sessionUnits"></dd>
                        <dt class="text-[10px] uppercase tracking-wider text-ink-400">unit discan</dt>
                    </div>

                    <a href="{{ route('admin.outbounds.ready') }}"
                       class="inline-flex items-center gap-1.5 rounded-xl bg-ink-950 px-3 py-2 text-xs font-semibold text-white transition hover:bg-ink-800">
                        <x-icon name="logout" class="h-3.5 w-3.5" />
                        Siap Dikirim
                        <span class="rounded-md bg-white/15 px-1.5 py-0.5 text-[11px] tabular-nums">{{ $readyCount }}</span>
                    </a>
                </dl>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            {{-- ─────────────── Panel scan: menempel, tinggi tetap ─────────────── --}}
            <div class="lg:col-span-5">
                <div class="space-y-4 lg:sticky lg:top-36">
                    <div class="flex h-auto flex-col overflow-hidden rounded-3xl lg:h-[22rem] border shadow-lift transition-colors"
                         :class="stage === 'done' ? 'border-emerald-600 bg-emerald-600' : 'border-ink-950 bg-ink-950'">

                        <div class="px-6 pt-6">
                            <p class="flex h-5 items-center gap-2 text-[11px] font-medium uppercase tracking-wider text-white/50">
                                <span x-text="'Langkah ' + step + ' dari 3'"></span>
                                <span x-show="outbound" x-cloak class="truncate font-mono normal-case tracking-normal text-white/40"
                                      x-text="outbound?.code"></span>
                            </p>

                            <h1 class="mt-2 line-clamp-1 text-xl font-semibold tracking-tight text-white sm:text-2xl"
                                x-text="title"></h1>

                            <p class="mt-1 h-8 overflow-hidden text-xs leading-4 text-white/60" x-text="hint"></p>
                        </div>

                        <div class="flex items-stretch gap-2 px-6 pt-4">
                            @include('admin.partials.camera-scan', [
                                'scanHint' => "progress
                                    ? (progress.ready
                                        ? 'Semua barang terpindai. Paket masuk antrean siap kirim.'
                                        : `Sisa \${remaining} unit lagi pada paket ini.`)
                                    : 'Langkah 1: pindai label resi pada paket.'",
                            ])

                            <form data-no-ajax @submit.prevent="submit()" class="min-w-0 flex-1">
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex w-12 items-center justify-center text-white/30">
                                        <x-icon name="search" class="h-5 w-5" />
                                    </span>

                                    <input x-ref="input" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                           spellcheck="false" :disabled="busy || stage === 'done'"
                                           :placeholder="placeholder"
                                           class="block h-14 w-full rounded-2xl border-0 bg-white/10 pl-12 pr-24 font-mono text-base tracking-wide text-white placeholder:font-sans placeholder:text-sm placeholder:tracking-normal placeholder:text-white/30 ring-1 ring-inset ring-white/15 transition focus:bg-white/[0.15] focus:ring-2 focus:ring-white disabled:opacity-40">

                                    <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                        <button type="submit" :disabled="busy || stage === 'done' || ! code.trim()"
                                                class="inline-flex h-10 items-center justify-center rounded-xl bg-white px-4 text-sm font-semibold text-ink-950 transition hover:bg-white/90 disabled:opacity-40">
                                            <span x-show="! busy" x-text="actionLabel"></span>
                                            <span x-show="busy" x-cloak class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-ink-950/30 border-t-ink-950"></span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        {{-- Ruang umpan balik: selalu ada, hanya isinya berganti --}}
                        <div class="mt-3 flex flex-1 items-start px-6 pb-5">
                            <div class="flex min-h-[3.25rem] w-full items-center gap-2.5 rounded-xl px-3 py-2 transition"
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

                    {{-- Identitas paket: tinggi tetap, kosong saat menunggu --}}
                    <div class="flex h-20 items-center gap-3 rounded-2xl border border-ink-100 bg-white px-4 shadow-card">
                        <template x-if="outbound">
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-ink-950 text-white">
                                    <x-icon name="logout" class="h-4 w-4" />
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate font-mono text-xs font-semibold text-ink-950" x-text="outbound.tracking_number"></p>
                                    <p class="truncate text-[11px] text-ink-400"
                                       x-text="[outbound.marketplace, outbound.recipient, outbound.order_number].filter(Boolean).join(' · ')"></p>
                                </div>
                            </div>
                        </template>

                        <template x-if="! outbound">
                            <p class="flex items-center gap-2 text-xs text-ink-400">
                                <x-icon name="info" class="h-4 w-4 shrink-0 text-ink-300" />
                                Paket yang sedang dikerjakan tampil di sini.
                            </p>
                        </template>

                        <button type="button" x-on:click="reset()" x-show="outbound" x-cloak
                                title="Lepas paket ini"
                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                            <x-icon name="close" class="h-4 w-4" />
                        </button>
                    </div>

                    <label class="flex cursor-pointer items-center gap-2.5 px-1 text-xs text-ink-500">
                        <input type="checkbox" x-model="autoContinue"
                               class="h-4 w-4 rounded border-ink-300 text-ink-950 focus:ring-ink-950">
                        Lanjut otomatis ke resi berikutnya
                    </label>
                </div>
            </div>

            {{-- ─────────────── Daftar barang: tinggi tetap, gulir sendiri ─────────────── --}}
            <div class="lg:col-span-7">
                <div class="flex max-h-[70vh] flex-col overflow-hidden rounded-2xl lg:h-[34rem] lg:max-h-none border border-ink-100 bg-white shadow-card">

                    <div class="flex shrink-0 items-center justify-between gap-3 border-b border-ink-100 px-5 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold tracking-tight text-ink-950">Barang yang Harus Discan</p>
                            <p class="truncate text-xs text-ink-500"
                               x-text="progress
                                    ? `${items.length} SKU · ${progress.scanned}/${totalUnits} unit discan`
                                    : 'Menunggu resi discan'"></p>
                        </div>

                        <span class="shrink-0 rounded-lg bg-ink-50 px-2.5 py-1 text-[11px] font-medium text-ink-500 ring-1 ring-inset ring-ink-100"
                              x-show="progress" x-cloak x-text="`${progress.percentage}%`"></span>
                    </div>

                    {{--
                        Stok kurang diberitahukan begitu resi discan, bukan saat
                        paket hendak diproses. Scan tetap boleh jalan; yang
                        dihindari adalah operator baru tahu setelah selesai.
                    --}}
                    <template x-if="shortages.length">
                        <div class="shrink-0 border-b border-red-100 bg-red-50 px-5 py-2.5">
                            <p class="flex items-start gap-2 text-[11px] leading-relaxed text-red-800">
                                <x-icon name="x-circle" class="mt-px h-3.5 w-3.5 shrink-0 text-red-500" />
                                <span>
                                    <span class="font-semibold">Stok tidak mencukupi:</span>
                                    <span class="font-mono" x-text="shortages.map((line) => line.sku).join(', ')"></span>.
                                    Barang ini tidak bisa discan sampai stoknya dicatat lewat barang masuk.
                                </span>
                            </p>
                        </div>
                    </template>

                    {{-- Satu-satunya bagian yang menggulir --}}
                    <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
                        <template x-if="! progress">
                            <div class="flex h-full flex-col items-center justify-center px-6 py-16 text-center">
                                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-ink-50 text-ink-300 ring-1 ring-ink-100">
                                    <x-icon name="box" class="h-5 w-5" />
                                </span>
                                <p class="mt-3 text-sm font-medium text-ink-950">Belum ada paket aktif</p>
                                <p class="mt-1 max-w-xs text-xs leading-relaxed text-ink-500">
                                    Scan resi pada panel di samping. Daftar barangnya diambil otomatis dari data import.
                                </p>
                            </div>
                        </template>

                        <template x-if="progress">
                            <ul class="divide-y divide-ink-50">
                                <template x-for="line in lines" :key="line.id">
                                    <li class="px-5 py-3.5 transition"
                                        :class="line.completed ? 'bg-emerald-50/60' : (line.short && 'bg-red-50/40')">
                                        <div class="flex items-start gap-3">
                                            <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full transition"
                                                  :class="line.completed ? 'bg-emerald-600 text-white'
                                                        : (line.short ? 'bg-red-100 text-red-600' : 'bg-ink-100 text-ink-400')">
                                                <template x-if="line.completed"><x-icon name="check" class="h-3 w-3" /></template>
                                            </span>

                                            <div class="min-w-0 flex-1">
                                                <span class="inline-flex max-w-full items-center gap-1 rounded-md bg-ink-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold leading-4 text-ink-800 ring-1 ring-inset ring-ink-200">
                                                    <span class="opacity-50">SKU</span>
                                                    <span class="truncate" x-text="line.sku"></span>
                                                </span>
                                                <p class="mt-0.5 truncate text-sm font-medium text-ink-950" x-text="line.name"></p>

                                                <p class="flex flex-wrap items-center gap-x-1.5 text-[11px]">
                                                    <span class="truncate font-mono text-ink-400"
                                                          x-text="line.barcode ? `Barcode ${line.barcode}` : 'Tanpa barcode — scan pakai SKU'"></span>
                                                    <span class="text-ink-200">&middot;</span>
                                                    <span :class="line.short ? 'font-semibold text-red-600' : 'text-ink-400'"
                                                          x-text="line.short
                                                                ? `stok ${line.stock} ${line.unit}, kurang ${line.quantity - line.stock}`
                                                                : `stok ${line.stock} ${line.unit}`"></span>
                                                </p>

                                                <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
                                                    <div class="h-full rounded-full transition-all duration-300"
                                                         :class="line.completed ? 'bg-emerald-500' : 'bg-ink-950'"
                                                         :style="`width: ${Math.min(100, line.scanned / line.quantity * 100)}%`"></div>
                                                </div>
                                            </div>

                                            <div class="shrink-0 text-right">
                                                <p class="text-sm font-semibold text-ink-950">
                                                    <span x-text="line.scanned"></span><span class="text-ink-300" x-text="`/${line.quantity}`"></span>
                                                </p>
                                                <p class="text-[11px]" :class="line.completed ? 'text-emerald-600' : 'text-ink-400'"
                                                   x-text="line.completed ? 'selesai' : `sisa ${line.quantity - line.scanned}`"></p>
                                            </div>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </template>
                    </div>

                    {{-- Kaki kartu: aksi utama, posisinya tidak pernah berubah --}}
                    <div class="shrink-0 border-t border-ink-100 bg-white px-4 py-3">
                        <div class="mb-2.5 flex h-6 items-center justify-between gap-3 text-[11px]">
                            <span x-show="progress" x-cloak
                                  :class="shortages.length ? 'font-medium text-red-600' : 'text-ink-500'"
                                  x-text="shortages.length
                                        ? `${shortages.length} barang stoknya kurang`
                                        : (progress.ready ? 'Lengkap — masuk antrean siap kirim' : `Sisa ${remaining} unit`)"></span>
                        </div>

                        {{--
                            Tidak ada tombol proses di sini: stasiun ini hanya
                            memverifikasi isi paket. Tombolnya cuma untuk
                            melepas paket lebih awal bila perlu.
                        --}}
                        <button type="button" x-on:click="reset()"
                                :disabled="busy || ! outbound"
                                class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-40"
                                :class="stage === 'done' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-ink-950 hover:bg-ink-800'">
                            <x-icon name="arrow-right" class="h-4 w-4" />
                            <span x-text="stage === 'done' ? 'Lanjut ke Resi Berikutnya' : 'Lewati Paket Ini'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ─────────────── Riwayat sesi: tinggi tetap, gulir sendiri ─────────────── --}}
        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="flex h-56 flex-col overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                <div class="flex shrink-0 items-center justify-between gap-3 border-b border-ink-100 px-5 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-400">Selesai Sesi Ini</p>
                    <a href="{{ route('admin.outbounds.ready') }}"
                       class="text-[11px] font-medium text-ink-500 underline-offset-4 hover:text-ink-950 hover:underline">
                        Lihat antrean siap kirim
                    </a>
                </div>
                <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
                    <template x-if="! completed.length">
                        <p class="px-5 py-10 text-center text-xs text-ink-400">Belum ada paket yang selesai discan.</p>
                    </template>

                    <ul class="divide-y divide-ink-50">
                        <template x-for="entry in completed" :key="entry.id">
                            <li class="flex items-center gap-3 px-5 py-2.5">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                                    <x-icon name="check" class="h-3 w-3" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-mono text-xs font-semibold text-ink-950" x-text="entry.code"></p>
                                    <p class="truncate font-mono text-[11px] text-ink-400" x-text="entry.tracking_number"></p>
                                </div>
                                <span class="shrink-0 text-[11px] font-semibold text-emerald-600" x-text="`${entry.units} unit`"></span>
                                <span class="shrink-0 font-mono text-[11px] text-ink-400" x-text="entry.at"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            <div class="flex h-56 flex-col overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                <div class="shrink-0 border-b border-ink-100 px-5 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-400">Scan Terakhir</p>
                </div>
                <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
                    <template x-if="! history.length">
                        <p class="px-5 py-10 text-center text-xs text-ink-400">Riwayat scan muncul di sini.</p>
                    </template>

                    <ul class="divide-y divide-ink-50">
                        <template x-for="entry in history" :key="entry.id">
                            <li class="flex items-center gap-3 px-5 py-2.5">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                                      :class="entry.type === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">
                                    <template x-if="entry.type === 'success'"><x-icon name="check" class="h-3 w-3" /></template>
                                    <template x-if="entry.type === 'error'"><x-icon name="close" class="h-3 w-3" /></template>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs text-ink-700" x-text="entry.message"></p>
                                    <p class="truncate font-mono text-[11px] text-ink-400" x-text="entry.code"></p>
                                </div>
                                <span class="shrink-0 font-mono text-[11px] text-ink-400" x-text="entry.at"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Pesanan yang sempat tertunda --}}
        @if ($pending->isNotEmpty())
            <div class="mt-4 overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                <div class="border-b border-ink-100 px-5 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-400">Belum Selesai Discan</p>
                </div>
                <ul class="divide-y divide-ink-50">
                    @foreach ($pending as $draft)
                        <li>
                            <a href="{{ route('admin.outbounds.scan', $draft) }}"
                               class="flex items-center gap-3 px-5 py-2.5 transition hover:bg-ink-50/60">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-mono text-xs font-semibold text-ink-950">{{ $draft->tracking_number }}</p>
                                    <p class="truncate text-[11px] text-ink-400">{{ $draft->code }} &middot; {{ $draft->recipient }}</p>
                                </div>
                                <x-ui.badge :variant="$draft->isResiVerified() ? 'warning' : 'neutral'">
                                    {{ $draft->isResiVerified() ? 'Scan barang' : 'Perlu scan resi' }}
                                </x-ui.badge>
                                <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-ink-300" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="mt-5 text-center text-xs text-ink-400">
            <a href="{{ route('admin.outbounds.index') }}" class="font-medium text-ink-600 underline-offset-4 hover:underline">
                Daftar barang keluar
            </a>
            &middot;
            <a href="{{ route('admin.outbounds.create', ['type' => 'marketplace']) }}" class="font-medium text-ink-600 underline-offset-4 hover:underline">
                Buat dokumen manual
            </a>
        </p>
    </div>
</x-app-layout>
