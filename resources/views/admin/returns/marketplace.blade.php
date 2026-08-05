{{--
    Stasiun retur marketplace.

    Tata letaknya sengaja bertinggi tetap: rail langkah, panel scan, dan
    daftar barang masing-masing punya ruang sendiri yang tidak berubah ukuran
    saat tahap berganti. Yang berubah hanya isinya, sehingga kolom input tidak
    pernah berpindah posisi dan mata operator tidak perlu mencarinya lagi.
--}}
<x-app-layout title="Stasiun Retur">
    <div x-data="returnStation({
            urls: {
                start: '{{ route('admin.returns.marketplace.store') }}',
                lookup: '{{ route('admin.returns.marketplace.lookup') }}',
                manual: '{{ route('admin.returns.marketplace.manual') }}',
            },
            canFinish: {{ auth()->user()->can('returns.post') ? 'true' : 'false' }},
         })"
         class="mx-auto max-w-6xl">

        {{-- ─────────────── Rail langkah + ringkasan sesi ─────────────── --}}
        <div class="sticky top-16 z-20 -mx-4 mb-4 border-b border-ink-100 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border sm:shadow-card">
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                {{-- Tiga langkah, selalu terlihat --}}
                <ol class="flex min-w-0 items-center gap-1.5 sm:gap-2">
                    @foreach ([1 => 'Resi', 2 => 'Periksa', 3 => 'Terima'] as $number => $label)
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

                {{-- Hasil sesi, satu baris dan tidak pernah membungkus --}}
                <dl class="flex shrink-0 items-center gap-4 text-right sm:gap-5">
                    @foreach ([
                        ['completed.length', 'paket', 'text-ink-950'],
                        ['totalGood', 'layak', 'text-emerald-600'],
                        ['totalDamaged', 'rusak', 'text-red-600'],
                        ['totalMissing', 'hilang', 'text-amber-600'],
                    ] as [$binding, $label, $tone])
                        <div>
                            <dd class="text-base font-semibold leading-tight {{ $tone }}" x-text="{{ $binding }}"></dd>
                            <dt class="text-[10px] uppercase tracking-wider text-ink-400">{{ $label }}</dt>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            {{-- ─────────────── Panel scan: menempel, tinggi tetap ─────────────── --}}
            <div class="lg:col-span-5">
                <div class="space-y-4 lg:sticky lg:top-36">
                    <div class="flex h-auto flex-col overflow-hidden rounded-3xl lg:h-[22rem] border shadow-lift transition-colors"
                         :class="stage === 'finishing' ? 'border-emerald-600 bg-emerald-600' : 'border-ink-950 bg-ink-950'">

                        {{-- Judul: tinggi tetap, tidak menggeser apa pun --}}
                        <div class="px-6 pt-6">
                            <p class="flex h-5 items-center gap-2 text-[11px] font-medium uppercase tracking-wider text-white/50">
                                <span x-text="manualEntry ? 'Input manual' : 'Langkah ' + step + ' dari 3'"></span>
                                <span x-show="document" x-cloak class="truncate font-mono normal-case tracking-normal text-white/40"
                                      x-text="document?.code"></span>
                            </p>

                            <h1 class="mt-2 line-clamp-1 text-xl font-semibold tracking-tight text-white sm:text-2xl"
                                x-text="title"></h1>

                            <p class="mt-1 h-8 overflow-hidden text-xs leading-4 text-white/60" x-text="hint"></p>
                        </div>

                        {{-- Kolom input: posisinya tidak pernah berubah --}}
                        <div class="px-6 pt-4">
                            <form data-no-ajax @submit.prevent="submit()">
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex w-12 items-center justify-center text-white/30">
                                        <x-icon name="search" class="h-5 w-5" />
                                    </span>

                                    <input x-ref="input" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                           spellcheck="false" :disabled="busy || stage === 'finishing'"
                                           :placeholder="placeholder"
                                           class="block h-14 w-full rounded-2xl border-0 bg-white/10 pl-12 pr-24 font-mono text-base tracking-wide text-white placeholder:font-sans placeholder:text-sm placeholder:tracking-normal placeholder:text-white/30 ring-1 ring-inset ring-white/15 transition focus:bg-white/[0.15] focus:ring-2 focus:ring-white disabled:opacity-40">

                                    <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                        <button type="submit" :disabled="busy || stage === 'finishing'"
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
                        <template x-if="document || pendingTracking">
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-ink-950 text-white">
                                    <x-icon name="refresh" class="h-4 w-4" />
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate font-mono text-xs font-semibold text-ink-950"
                                       x-text="document ? document.tracking_number : pendingTracking"></p>
                                    <p class="truncate text-[11px] text-ink-400"
                                       x-text="document
                                            ? [document.marketplace, document.sender, document.order_number].filter(Boolean).join(' · ')
                                            : 'Belum ada di data import'"></p>
                                </div>
                            </div>
                        </template>

                        <template x-if="! document && ! pendingTracking">
                            <p class="flex items-center gap-2 text-xs text-ink-400">
                                <x-icon name="info" class="h-4 w-4 shrink-0 text-ink-300" />
                                Paket yang sedang dikerjakan tampil di sini.
                            </p>
                        </template>

                        <button type="button" x-on:click="reset()" x-show="document || pendingTracking" x-cloak
                                title="Batalkan paket ini"
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
                            <p class="text-sm font-semibold tracking-tight text-ink-950">Isi Paket</p>
                            <p class="truncate text-xs text-ink-500"
                               x-text="document
                                    ? `${items.length} baris · ${totalUnits} unit` + (manualEntry ? ' discan' : ' pada resi')
                                    : (isCollectStage ? 'Menunggu barang pertama discan' : 'Menunggu resi discan')"></p>
                        </div>

                        <button type="button" x-on:click="markEverythingIntact()" x-show="document" x-cloak
                                class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200 transition hover:bg-emerald-100">
                            <x-icon name="check" class="h-3.5 w-3.5" />
                            Semua utuh
                        </button>
                    </div>

                    {{-- Satu-satunya bagian yang menggulir --}}
                    <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">

                        {{-- Menunggu resi, atau menunggu barang pertama pada retur manual --}}
                        <template x-if="! document">
                            <div class="flex h-full flex-col items-center justify-center px-6 py-16 text-center">
                                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl transition"
                                      :class="isCollectStage ? 'bg-ink-950 text-white' : 'bg-ink-50 text-ink-300 ring-1 ring-ink-100'">
                                    <x-icon name="box" class="h-5 w-5" x-show="isCollectStage" x-cloak />
                                    <x-icon name="refresh" class="h-5 w-5" x-show="! isCollectStage" />
                                </span>

                                <p class="mt-3 text-sm font-medium text-ink-950"
                                   x-text="isCollectStage ? 'Scan barang pertama' : 'Belum ada paket aktif'"></p>

                                <p class="mt-1 max-w-xs text-xs leading-relaxed text-ink-500"
                                   x-text="isCollectStage
                                        ? 'Dokumen retur langsung dibuat begitu barang pertama discan. Barcode maupun SKU sama-sama diterima.'
                                        : 'Scan resi retur pada panel di samping. Bila resinya belum diimport, barangnya bisa discan sendiri.'"></p>
                            </div>
                        </template>

                        {{-- Isi paket: sekaligus tempat memeriksa kondisinya --}}
                        <template x-if="document">
                            <ul class="divide-y divide-ink-50">
                                <template x-for="item in items" :key="item.id">
                                    <li class="px-5 py-3.5 transition" :class="isOverChecked(item) && 'bg-red-50/70'">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0 flex-1">
                                                <span class="inline-flex max-w-full items-center gap-1 rounded-md bg-ink-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold leading-4 text-ink-800 ring-1 ring-inset ring-ink-200">
                                                    <span class="opacity-50">SKU</span>
                                                    <span class="truncate" x-text="item.sku"></span>
                                                </span>
                                                <p class="mt-0.5 truncate text-sm font-medium text-ink-950" x-text="item.name"></p>
                                            </div>

                                            <div class="flex shrink-0 items-center gap-1">
                                                {{-- Resi hasil import: jumlahnya dari marketplace, tidak diutak-atik. --}}
                                                <template x-if="! manualEntry">
                                                    <span class="rounded-lg bg-ink-50 px-2 py-1 text-[11px] font-medium text-ink-500 ring-1 ring-inset ring-ink-100"
                                                          x-text="`${item.quantity} ${item.unit} di resi`"></span>
                                                </template>

                                                {{--
                                                    Retur manual: tidak ada data resi sebagai pembanding, jadi
                                                    "seharusnya berapa" harus dinyatakan operator. Barang hilang
                                                    tidak mungkin discan — yang bisa dilakukan adalah menyebut
                                                    jumlah yang dijanjikan, lalu sisanya jadi selisih.
                                                --}}
                                                <template x-if="manualEntry">
                                                    <label class="flex items-center gap-1.5 rounded-lg bg-ink-50 px-2 py-1 ring-1 ring-inset ring-ink-100"
                                                           title="Jumlah yang seharusnya dikembalikan pembeli">
                                                        <span class="text-[10px] uppercase tracking-wider text-ink-400">seharusnya</span>
                                                        <input type="number" min="1" max="999" x-model.number="item.quantity"
                                                               class="w-10 border-0 bg-transparent p-0 text-center text-sm font-semibold text-ink-950 focus:ring-0">
                                                        <span class="text-[11px] text-ink-400" x-text="item.unit"></span>
                                                    </label>
                                                </template>

                                                {{-- Baris hasil scan sendiri boleh dibuang bila salah --}}
                                                <button type="button" x-on:click="removeItem(item)" x-show="manualEntry" x-cloak
                                                        title="Hapus baris ini" :disabled="busy"
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-ink-400 transition hover:bg-red-50 hover:text-red-600 disabled:opacity-40">
                                                    <x-icon name="trash" class="h-3.5 w-3.5" />
                                                </button>
                                            </div>
                                        </div>

                                        {{-- Proporsi kondisi: terbaca sekilas tanpa menambah tinggi --}}
                                        <div class="mt-2 flex h-1.5 overflow-hidden rounded-full bg-ink-100">
                                            <div class="bg-emerald-500 transition-all" :style="`width: ${goodOf(item) / item.quantity * 100}%`"></div>
                                            <div class="bg-red-500 transition-all" :style="`width: ${Math.min(100, (item.damaged || 0) / item.quantity * 100)}%`"></div>
                                            <div class="bg-amber-500 transition-all" :style="`width: ${Math.min(100, (item.missing || 0) / item.quantity * 100)}%`"></div>
                                        </div>

                                        <div class="mt-2.5 flex flex-wrap items-end gap-2">
                                            <div class="flex min-w-0 flex-1 items-center gap-2">
                                                <div class="w-16 shrink-0 rounded-xl bg-emerald-50 px-2 py-1.5 text-center ring-1 ring-inset ring-emerald-100">
                                                    <p class="text-sm font-semibold leading-tight text-emerald-700" x-text="goodOf(item)"></p>
                                                    <p class="text-[10px] uppercase tracking-wider text-emerald-600/70">layak</p>
                                                </div>

                                                <div class="w-16 shrink-0">
                                                    <label class="block text-center text-[10px] uppercase tracking-wider text-ink-400">rusak</label>
                                                    <input type="number" min="0" :max="item.quantity" x-model.number="item.damaged"
                                                           class="mt-0.5 block w-full rounded-xl border-ink-200 bg-white px-1 py-1 text-center text-sm font-semibold text-red-600 shadow-soft transition focus:border-red-400 focus:ring-1 focus:ring-red-400">
                                                </div>

                                                <div class="w-16 shrink-0">
                                                    <label class="block text-center text-[10px] uppercase tracking-wider text-ink-400">hilang</label>
                                                    <input type="number" min="0" :max="item.quantity" x-model.number="item.missing"
                                                           class="mt-0.5 block w-full rounded-xl border-ink-200 bg-white px-1 py-1 text-center text-sm font-semibold text-amber-600 shadow-soft transition focus:border-amber-400 focus:ring-1 focus:ring-amber-400">
                                                </div>
                                            </div>

                                            {{-- Aksi cepat: sebagian besar paket tidak perlu diketik --}}
                                            <div class="flex shrink-0 items-center gap-1">
                                                <button type="button" x-on:click="markIntact(item)" title="Semuanya utuh"
                                                        class="inline-flex h-7 items-center rounded-lg px-2 text-[11px] font-medium transition"
                                                        :class="goodOf(item) === item.quantity ? 'bg-emerald-600 text-white' : 'bg-ink-50 text-ink-500 hover:bg-ink-100'">Utuh</button>
                                                <button type="button" x-on:click="markAllDamaged(item)" title="Semuanya rusak"
                                                        class="inline-flex h-7 items-center rounded-lg px-2 text-[11px] font-medium transition"
                                                        :class="item.damaged === item.quantity ? 'bg-red-600 text-white' : 'bg-ink-50 text-ink-500 hover:bg-ink-100'">Rusak</button>
                                                <button type="button" x-on:click="markAllMissing(item)" title="Semuanya hilang"
                                                        class="inline-flex h-7 items-center rounded-lg px-2 text-[11px] font-medium transition"
                                                        :class="item.missing === item.quantity ? 'bg-amber-500 text-white' : 'bg-ink-50 text-ink-500 hover:bg-ink-100'">Hilang</button>
                                            </div>
                                        </div>

                                        <template x-if="isOverChecked(item)">
                                            <p class="mt-2 flex items-center gap-1.5 text-[11px] font-medium text-red-600">
                                                <x-icon name="warning" class="h-3.5 w-3.5 shrink-0" />
                                                Rusak + hilang melebihi jumlah pada resi.
                                            </p>
                                        </template>
                                    </li>
                                </template>
                            </ul>
                        </template>
                    </div>

                    {{-- Kaki kartu: aksi utama, posisinya tidak pernah berubah --}}
                    <div class="shrink-0 border-t border-ink-100 bg-white px-4 py-3">
                        <div class="mb-2.5 flex h-6 items-center justify-between gap-3 text-[11px]">
                            <div class="flex items-center gap-3" x-show="document" x-cloak>
                                <span class="font-medium text-emerald-600" x-text="`${goodUnits} layak jual`"></span>
                                <span class="font-medium text-red-600" x-show="damagedUnits > 0" x-text="`${damagedUnits} rusak`"></span>
                                <span class="font-medium text-amber-600" x-show="missingUnits > 0" x-text="`${missingUnits} hilang`"></span>
                            </div>

                            <select x-model="reason" data-plain x-show="document" x-cloak
                                    class="h-7 rounded-lg border-ink-200 py-0 pl-2 pr-7 text-[11px] text-ink-600 focus:border-ink-950 focus:ring-1 focus:ring-ink-950">
                                <option value="">Alasan retur (opsional)</option>
                                @foreach ($reasons as $reason)
                                    <option value="{{ $reason }}">{{ $reason }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Satu tombol, posisinya tidak pernah berubah --}}
                        <button type="button"
                                x-on:click="stage === 'finishing' ? reset() : finish()"
                                :disabled="busy || (stage !== 'finishing' && ! canReceive)"
                                class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-40"
                                :class="stage === 'finishing' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-ink-950 hover:bg-ink-800'">
                            <template x-if="stage === 'finishing'"><x-icon name="arrow-right" class="h-4 w-4" /></template>
                            <template x-if="stage !== 'finishing'"><x-icon name="check" class="h-4 w-4" /></template>
                            <span x-text="stage === 'finishing' ? 'Lanjut ke Resi Berikutnya' : 'Terima Retur'"></span>
                            <span class="text-white/50" x-show="stage !== 'finishing' && canReceive" x-cloak>&middot; Enter</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ─────────────── Riwayat sesi: tinggi tetap, gulir sendiri ─────────────── --}}
        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="flex h-56 flex-col overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                <div class="shrink-0 border-b border-ink-100 px-5 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-400">Diterima Sesi Ini</p>
                </div>
                <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
                    <template x-if="! completed.length">
                        <p class="px-5 py-10 text-center text-xs text-ink-400">Belum ada paket yang diterima.</p>
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
                                <div class="flex shrink-0 items-center gap-1.5 text-[11px] font-semibold">
                                    <span class="text-emerald-600" x-text="entry.good"></span>
                                    <span class="text-red-600" x-show="entry.damaged > 0" x-text="`/${entry.damaged}`"></span>
                                    <span class="text-amber-600" x-show="entry.missing > 0" x-text="`/${entry.missing}`"></span>
                                </div>
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

        {{-- Retur yang sempat tertunda --}}
        @if ($pending->isNotEmpty())
            <div class="mt-4 overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                <div class="border-b border-ink-100 px-5 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-400">Belum Selesai</p>
                </div>
                <ul class="divide-y divide-ink-50">
                    @foreach ($pending as $draft)
                        <li>
                            <a href="{{ route('admin.returns.show', $draft) }}"
                               class="flex items-center gap-3 px-5 py-2.5 transition hover:bg-ink-50/60">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-mono text-xs font-semibold text-ink-950">{{ $draft->tracking_number ?: 'Tanpa resi' }}</p>
                                    <p class="truncate text-[11px] text-ink-400">{{ $draft->code }} &middot; {{ $draft->sender }}</p>
                                </div>
                                <x-ui.badge :variant="$draft->statusVariant()">{{ $draft->statusLabel() }}</x-ui.badge>
                                <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-ink-300" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="mt-5 text-center text-xs text-ink-400">
            <a href="{{ route('admin.returns.index') }}" class="font-medium text-ink-600 underline-offset-4 hover:underline">
                Daftar penerimaan retur
            </a>
            &middot;
            <a href="{{ route('admin.returns.create') }}" class="font-medium text-ink-600 underline-offset-4 hover:underline">
                Buat dokumen retur manual
            </a>
        </p>
    </div>
</x-app-layout>
