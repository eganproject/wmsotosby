<x-app-layout title="Scan Pesanan Marketplace">
    <div x-data="documentScanner({
            urls: {
                resi: '{{ route('admin.outbounds.scan.resi', $outbound) }}',
                item: '{{ route('admin.outbounds.scan.item', $outbound) }}',
            },
            progress: {{ Js::from($progress) }},
         })"
         x-on:camera-scan.window="code = $event.detail.code; submit()"
         class="mx-auto max-w-5xl">

        {{-- Identitas pesanan: informasi, bukan form --}}
        <div class="mb-5 flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-ink-100 bg-white p-5 shadow-card">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-sm font-semibold text-ink-950">{{ $outbound->code }}</span>
                    <x-ui.badge variant="dark" icon="sparkles">{{ $outbound->marketplace }}</x-ui.badge>
                </div>
                <p class="mt-1.5 break-all font-mono text-xs text-ink-500">
                    Resi {{ $outbound->tracking_number }}
                </p>
                <p class="mt-0.5 text-xs text-ink-500">
                    {{ $outbound->recipient }}
                    @if ($outbound->shipment_order_id && $outbound->shipmentOrder?->order_number)
                        &middot; Pesanan {{ $outbound->shipmentOrder->order_number }}
                    @endif
                </p>
            </div>

            <div class="text-right">
                <p class="text-2xl font-semibold tracking-tight text-ink-950">
                    <span x-text="progress.scanned"></span><span class="text-ink-300">/{{ $outbound->totalQuantity() }}</span>
                </p>
                <p class="text-[11px] uppercase tracking-wider text-ink-400">unit discan</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-5">
            {{-- Panel scan --}}
            <div class="space-y-5 lg:col-span-3">
                <div class="overflow-hidden rounded-3xl border shadow-lift transition"
                     :class="progress.ready ? 'border-emerald-200 bg-emerald-50' : 'border-ink-950 bg-ink-950'">

                    {{-- Instruksi besar: apa yang harus dilakukan sekarang --}}
                    <div class="px-6 pt-7 text-center sm:px-10">
                        <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium"
                              :class="progress.ready ? 'bg-emerald-600/10 text-emerald-700' : 'bg-white/10 text-white/70'">
                            <span class="inline-flex h-4 w-4 items-center justify-center rounded-full text-[10px] font-bold"
                                  :class="progress.ready ? 'bg-emerald-600 text-white' : 'bg-white text-ink-950'"
                                  x-text="isResiStage ? '1' : '2'"></span>
                            <span x-text="isResiStage ? 'Langkah 1 dari 2' : 'Langkah 2 dari 2'"></span>
                        </span>

                        <h1 class="mt-4 text-2xl font-semibold tracking-tight sm:text-3xl"
                            :class="progress.ready ? 'text-emerald-900' : 'text-white'"
                            x-text="progress.ready ? 'Semua barang sudah discan' : (isResiStage ? 'Scan resi pesanan' : 'Scan barcode barang')"></h1>

                        <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed"
                           :class="progress.ready ? 'text-emerald-800/80' : 'text-white/60'"
                           x-text="progress.ready
                                ? '{{ auth()->user()->can('outbounds.approve') ? 'Isi paket sudah sesuai pesanan. Tekan Proses & Kirim untuk mengurangi stok.' : 'Isi paket sudah sesuai. Ajukan persetujuan untuk diproses.' }}'
                                : (isResiStage
                                    ? 'Cocokkan label resi pada paket dengan dokumen ini.'
                                    : `Sisa ${progress.total - progress.scanned} unit lagi. Setiap scan menambah satu unit.`)"></p>
                    </div>

                    {{-- Input scan --}}
                    <div class="p-6 sm:p-10 sm:pt-7">
                        <div class="flex items-stretch gap-2" x-show="! progress.ready">
                            @include('admin.partials.camera-scan', [
                                'scanHint' => "isResiStage
                                    ? 'Langkah 1: pindai label resi pada paket.'
                                    : `\${progress.scanned}/\${progress.total} unit terpindai.`",
                            ])

                        <form data-no-ajax @submit.prevent="submit()" class="min-w-0 flex-1">
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-16 items-center justify-center text-white/30">
                                    <x-icon name="search" class="h-7 w-7" />
                                </span>

                                <input x-ref="input" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                       spellcheck="false" :disabled="busy"
                                       :placeholder="isResiStage ? 'Scan atau ketik nomor resi…' : 'Scan barcode atau ketik SKU barang…'"
                                       class="block h-20 w-full rounded-2xl border-0 bg-white/10 pl-16 pr-32 font-mono text-xl tracking-wider text-white placeholder:font-sans placeholder:text-base placeholder:tracking-normal placeholder:text-white/30 ring-1 ring-inset ring-white/15 transition focus:bg-white/[0.15] focus:ring-2 focus:ring-white disabled:opacity-60">

                                <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                                    <button type="submit" :disabled="busy || ! code.trim()"
                                            class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-white px-5 text-sm font-semibold text-ink-950 transition hover:bg-white/90 disabled:opacity-40">
                                        <span x-show="! busy">Proses</span>
                                        <span x-show="busy" x-cloak class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-ink-950/30 border-t-ink-950"></span>
                                    </button>
                                </div>
                            </div>
                        </form>
                        </div>

                        {{-- Umpan balik scan terakhir --}}
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
                                   :class="[feedback.type === 'success' ? 'text-emerald-200' : 'text-red-200',
                                            progress.ready && 'text-emerald-900']"
                                   x-text="feedback.message"></p>
                            </div>
                        </template>

                        {{-- Progres --}}
                        <div class="mt-6" x-show="! isResiStage || progress.ready">
                            <div class="mb-2 flex items-center justify-between text-xs">
                                <span :class="progress.ready ? 'text-emerald-800/70' : 'text-white/50'">Progres scan barang</span>
                                <span class="font-semibold" :class="progress.ready ? 'text-emerald-900' : 'text-white'">
                                    <span x-text="progress.percentage"></span>%
                                </span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full"
                                 :class="progress.ready ? 'bg-emerald-600/15' : 'bg-white/10'">
                                <div class="h-full rounded-full transition-all duration-300"
                                     :class="progress.ready ? 'bg-emerald-600' : 'bg-white'"
                                     :style="`width: ${progress.percentage}%`"></div>
                            </div>
                        </div>

                        {{-- Aksi penyelesaian --}}
                        <template x-if="progress.ready">
                            @can('outbounds.post')
                                <form method="POST" action="{{ route('admin.outbounds.submit', $outbound) }}" class="mt-6">
                                    @csrf
                                    <button type="submit"
                                            class="inline-flex h-14 w-full items-center justify-center gap-2 rounded-2xl bg-emerald-600 text-base font-semibold text-white shadow-soft transition hover:bg-emerald-700">
                                        <x-icon :name="auth()->user()->can('outbounds.approve') ? 'check' : 'clock'" class="h-5 w-5" />
                                        {{ auth()->user()->can('outbounds.approve') ? 'Proses & Kirim' : 'Ajukan Persetujuan' }}
                                    </button>
                                </form>
                            @endcan
                        </template>
                    </div>
                </div>

                {{-- Riwayat scan --}}
                <template x-if="history.length">
                    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                        <div class="border-b border-ink-100 px-5 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wider text-ink-400">Scan Terakhir</p>
                        </div>
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
                </template>
            </div>

            {{-- Daftar barang yang harus discan --}}
            <div class="lg:col-span-2">
                <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card lg:sticky lg:top-20">
                    <div class="border-b border-ink-100 px-5 py-4">
                        <p class="text-sm font-semibold tracking-tight text-ink-950">Barang yang Harus Discan</p>
                        <p class="mt-0.5 text-xs text-ink-500">
                            {{ $outbound->items->count() }} SKU &middot; {{ $outbound->totalQuantity() }} unit
                            @if ($outbound->shipment_order_id)
                                &middot; dari data import
                            @endif
                        </p>
                        <p class="mt-1.5 flex items-start gap-1.5 text-[11px] leading-relaxed text-ink-400">
                            <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0" />
                            Setiap barang bisa discan lewat barcode maupun SKU-nya.
                        </p>
                    </div>

                    <ul class="scrollbar-thin max-h-[28rem] divide-y divide-ink-50 overflow-y-auto">
                        @foreach ($outbound->items as $item)
                            <li class="px-5 py-3.5 transition" x-data="{ itemId: {{ $item->id }} }"
                                :class="itemProgress(itemId).completed && 'bg-emerald-50/60'">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full transition"
                                          :class="itemProgress(itemId).completed ? 'bg-emerald-600 text-white' : 'bg-ink-100 text-ink-400'">
                                        <template x-if="itemProgress(itemId).completed"><x-icon name="check" class="h-3 w-3" /></template>
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <x-ui.sku :value="$item->product->sku" />
                                        <p class="mt-1 text-sm font-medium text-ink-950">{{ $item->product->name }}</p>
                                        @if ($item->product->barcode)
                                            <p class="font-mono text-[11px] text-ink-400">Barcode {{ $item->product->barcode }}</p>
                                        @else
                                            <p class="text-[11px] text-ink-400">Tanpa barcode — scan pakai SKU</p>
                                        @endif

                                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
                                            <div class="h-full rounded-full transition-all duration-300"
                                                 :class="itemProgress(itemId).completed ? 'bg-emerald-500' : 'bg-ink-950'"
                                                 :style="`width: ${Math.min(100, itemProgress(itemId).scanned / {{ max($item->quantity, 1) }} * 100)}%`"></div>
                                        </div>
                                    </div>

                                    <div class="shrink-0 text-right">
                                        <p class="text-sm font-semibold text-ink-950">
                                            <span x-text="itemProgress(itemId).scanned"></span><span class="text-ink-300">/{{ $item->quantity }}</span>
                                        </p>
                                        <p class="text-[11px]"
                                           :class="itemProgress(itemId).completed ? 'text-emerald-600' : 'text-ink-400'"
                                           x-text="itemProgress(itemId).completed
                                                ? 'selesai'
                                                : `sisa ${ {{ $item->quantity }} - itemProgress(itemId).scanned}`"></p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        {{-- Aksi sekunder, sengaja dijauhkan dari alur utama --}}
        <div class="mt-6 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs text-ink-400">
            <a href="{{ route('admin.outbounds.show', $outbound) }}" class="font-medium text-ink-600 underline-offset-4 hover:underline">
                Lihat detail dokumen
            </a>

            @if ($outbound->isEditable())
                <form method="POST" action="{{ route('admin.outbounds.scan.reset', $outbound) }}">
                    @csrf
                    <button type="submit" class="font-medium text-ink-500 underline-offset-4 hover:text-ink-950 hover:underline">
                        Ulangi scan dari awal
                    </button>
                </form>
            @endif

            @can('outbounds.create')
                <a href="{{ route('admin.outbounds.marketplace') }}" class="font-medium text-ink-600 underline-offset-4 hover:underline">
                    Scan pesanan lain
                </a>
            @endcan
        </div>
    </div>
</x-app-layout>
