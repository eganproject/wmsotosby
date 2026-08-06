<x-app-layout title="Scan Resi Retur">
    <x-ui.page-header :title="'Verifikasi ' . $return->code"
                      :subtitle="($return->isMarketplace() ? $return->marketplace : 'Non-marketplace').' · '.$return->sender"
                      :back="route('admin.returns.show', $return)">
        <x-slot name="actions">
            @if ($return->isMarketplace())
                <x-ui.badge variant="dark" icon="sparkles">{{ $return->marketplace }}</x-ui.badge>
            @else
                <x-ui.badge variant="outline">Non-marketplace</x-ui.badge>
            @endif
        </x-slot>
    </x-ui.page-header>

    <div x-data="documentScanner({
            urls: { resi: '{{ route('admin.returns.scan.resi', $return) }}' },
            progress: {{ Js::from($progress) }},
         })"
         {{-- Hasil pindaian kamera diperlakukan sama persis dengan scanner genggam. --}}
         x-on:camera-scan.window="code = $event.detail.code; submit()"
         class="grid grid-cols-1 gap-6 lg:grid-cols-5">

        {{-- Panel scan --}}
        <div class="space-y-6 lg:col-span-3">
            {{-- Status langkah --}}
            <div class="rounded-2xl border p-5 transition"
                 :class="progress.resi_verified ? 'border-emerald-200 bg-emerald-50' : 'border-ink-950 bg-ink-950 text-white'">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                          :class="progress.resi_verified ? 'bg-emerald-600 text-white' : 'bg-white/10 text-white'">
                        <template x-if="progress.resi_verified"><x-icon name="check" class="h-5 w-5" /></template>
                        <template x-if="! progress.resi_verified"><x-icon name="key" class="h-5 w-5" /></template>
                    </span>
                    <div>
                        <p class="text-sm font-semibold" :class="progress.resi_verified ? 'text-emerald-800' : 'text-white'"
                           x-text="progress.resi_verified ? 'Resi terverifikasi' : 'Menunggu scan resi retur'"></p>
                        <p class="mt-0.5 text-xs" :class="progress.resi_verified ? 'text-emerald-700/80' : 'text-white/60'"
                           x-text="progress.resi_verified
                                ? 'Paket sudah dipastikan milik dokumen ini. Barang siap diterima ke stok.'
                                : 'Paket retur harus dicocokkan dengan dokumen sebelum barang diterima.'"></p>
                    </div>
                </div>
            </div>

            {{-- Input scan --}}
            <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                <div class="border-b border-ink-100 px-5 py-4 sm:px-6">
                    <h2 class="text-sm font-semibold tracking-tight text-ink-950">Scan nomor resi retur</h2>
                    <p class="mt-0.5 text-xs text-ink-500">
                        Arahkan scanner ke label pada paket retur, atau ketik nomornya lalu tekan Enter.
                    </p>
                </div>

                <div class="p-5 sm:p-6">
                    {{--
                        Baris scan: tombol kamera dan kolom kode berdampingan.
                        Umpan balik di bawahnya sengaja berada di luar baris ini
                        — sebagai sesama anak flex ia akan berdiri menyamping
                        alih-alih di bawah kolomnya.
                    --}}
                    <div class="flex items-stretch gap-2">
                        {{--
                            Tombol kamera sempat tidak ada di halaman ini, padahal
                            retur justru sering diterima sambil berdiri di depan
                            tumpukan paket dengan ponsel di tangan. Disembunyikan
                            setelah resi terverifikasi: kolomnya pun sudah mati,
                            dan kamera yang terbuka tanpa bisa memproses apa pun
                            lebih membingungkan daripada tidak ada tombolnya.
                        --}}
                        <template x-if="! isDone">
                            @include('admin.partials.camera-scan', [
                                'scanTitle' => "'Scan resi retur'",
                                'scanHint' => "'Arahkan ke label pada paket retur.'",
                                'cameraButtonClass' => 'bg-ink-950 text-white hover:bg-ink-800',
                            ])
                        </template>

                        <form data-no-ajax @submit.prevent="submit()" class="min-w-0 flex-1">
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-14 items-center justify-center text-ink-300">
                                    <x-icon name="search" class="h-6 w-6" />
                                </span>

                                <input x-ref="input" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                       spellcheck="false" :disabled="busy || isDone"
                                       placeholder="Scan atau ketik nomor resi retur…"
                                       class="block h-16 w-full rounded-2xl border-ink-200 bg-white pl-14 pr-28 font-mono text-lg tracking-wide text-ink-950 placeholder:font-sans placeholder:text-sm placeholder:tracking-normal placeholder:text-ink-300 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950 disabled:bg-ink-50">

                                <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                    <x-ui.button type="submit" size="md" x-bind:disabled="busy || isDone || ! code.trim()">
                                        <span x-show="! busy">Proses</span>
                                        <span x-show="busy" x-cloak>…</span>
                                    </x-ui.button>
                                </div>
                            </div>
                        </form>
                    </div>

                    {{-- Umpan balik scan terakhir --}}
                    <template x-if="feedback">
                        <div class="mt-4 flex items-start gap-3 rounded-xl p-4"
                             :class="feedback.type === 'success'
                                ? 'bg-emerald-50 ring-1 ring-inset ring-emerald-200'
                                : 'bg-red-50 ring-1 ring-inset ring-red-200'">
                            <template x-if="feedback.type === 'success'">
                                <x-icon name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                            </template>
                            <template x-if="feedback.type === 'error'">
                                <x-icon name="x-circle" class="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                            </template>
                            <p class="text-sm font-medium"
                               :class="feedback.type === 'success' ? 'text-emerald-800' : 'text-red-800'"
                               x-text="feedback.message"></p>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Riwayat scan --}}
            <template x-if="history.length">
                <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                    <div class="border-b border-ink-100 px-5 py-3.5 sm:px-6">
                        <h2 class="text-sm font-semibold tracking-tight text-ink-950">Riwayat Scan</h2>
                    </div>
                    <ul class="divide-y divide-ink-50">
                        <template x-for="entry in history" :key="entry.id">
                            <li class="flex items-center gap-3 px-5 py-2.5 sm:px-6">
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

        {{-- Sisi kanan --}}
        <div class="space-y-6 lg:col-span-2">
            {{-- Isi paket --}}
            <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                <div class="border-b border-ink-100 px-5 py-4 sm:px-6">
                    <h2 class="text-sm font-semibold tracking-tight text-ink-950">Isi Paket Retur</h2>
                    <p class="mt-0.5 text-xs text-ink-500">
                        {{ $return->goodQuantity() }} layak jual &middot; {{ $return->damagedQuantity() }} rusak &middot; {{ $return->missingQuantity() }} hilang
                    </p>
                </div>

                <ul class="divide-y divide-ink-50">
                    @foreach ($return->items as $item)
                        <li class="flex items-start gap-3 px-5 py-3.5 sm:px-6">
                            <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ $item->hasMissing() || $item->damaged_quantity > 0 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' }}">
                                <x-icon :name="$item->hasMissing() || $item->damaged_quantity > 0 ? 'warning' : 'check'" class="h-3 w-3" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <x-ui.sku :value="$item->product->sku" />
                                <p class="mt-1 text-sm font-medium text-ink-950">{{ $item->product->name }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold text-ink-950">{{ $item->quantity }}</p>
                                <p class="text-[11px] {{ $item->hasMissing() || $item->damaged_quantity > 0 ? 'text-amber-600' : 'text-emerald-600' }}">
                                    {{ $item->conditionSummary() }}
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Penyelesaian --}}
            <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                <div class="p-5 sm:p-6">
                    <template x-if="progress.ready">
                        <div>
                            <div class="flex items-start gap-3 rounded-xl bg-emerald-50 p-4 ring-1 ring-inset ring-emerald-200">
                                <x-icon name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                                <div>
                                    <p class="text-sm font-semibold text-emerald-800">Siap diterima</p>
                                    <p class="mt-0.5 text-xs text-emerald-700/80">
                                        {{ $return->goodQuantity() }} unit layak jual akan kembali ke stok.
                                    </p>
                                </div>
                            </div>

                            @can('returns.post')
                                <form method="POST" action="{{ route('admin.returns.submit', $return) }}" class="mt-4">
                                    @csrf
                                    <x-ui.button type="submit" :icon="auth()->user()->can('returns.approve') ? 'check' : 'clock'"
                                                 size="lg" class="w-full">
                                        {{ auth()->user()->can('returns.approve') ? 'Terima Retur' : 'Ajukan Persetujuan' }}
                                    </x-ui.button>
                                </form>
                            @endcan
                        </div>
                    </template>

                    <template x-if="! progress.ready">
                        <div class="flex items-start gap-3 rounded-xl bg-ink-50 p-4 ring-1 ring-inset ring-ink-100">
                            <x-icon name="lock" class="mt-0.5 h-5 w-5 shrink-0 text-ink-400" />
                            <div>
                                <p class="text-sm font-semibold text-ink-950">Belum bisa diterima</p>
                                <p class="mt-0.5 text-xs text-ink-500">
                                    Scan resi retur terlebih dahulu untuk memastikan paket sesuai dokumen.
                                </p>
                            </div>
                        </div>
                    </template>

                    {{-- Dokumen yang sudah disetujui tidak bisa diutak-atik lagi. --}}
                    @if ($return->isEditable())
                        <template x-if="progress.resi_verified">
                            <form method="POST" action="{{ route('admin.returns.scan.reset', $return) }}" class="mt-3">
                                @csrf
                                <x-ui.button type="submit" variant="ghost" icon="refresh" class="w-full">
                                    Batalkan Verifikasi
                                </x-ui.button>
                            </form>
                        </template>
                    @endif
                </div>
            </div>

            {{-- Nomor resi dokumen --}}
            <div class="rounded-2xl border border-ink-100 bg-ink-50/60 p-5">
                <p class="text-xs font-medium uppercase tracking-wider text-ink-400">Nomor resi retur</p>
                <p class="mt-1 break-all font-mono text-sm font-semibold text-ink-950">{{ $return->tracking_number }}</p>
                <p class="mt-2 flex items-start gap-1.5 text-[11px] leading-relaxed text-ink-500">
                    <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
                    Scan harus cocok persis dengan nomor ini. Huruf besar/kecil dan spasi diabaikan.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
