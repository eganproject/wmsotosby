<x-app-layout title="Menunggu Persetujuan">
    <x-ui.page-header title="Menunggu Persetujuan" icon="check-circle"
                      subtitle="Dokumen yang diajukan dan menunggu keputusan Anda. Menyetujui berarti stok langsung bergerak.">
        <x-slot name="actions">
            <x-ui.badge :variant="$total > 0 ? 'warning' : 'success'" :icon="$total > 0 ? 'clock' : 'check-circle'">
                {{ $total }} dokumen
            </x-ui.badge>
        </x-slot>
    </x-ui.page-header>

    {{-- Saring per jenis dokumen --}}
    <div class="mb-5 flex flex-wrap gap-2">
        <a href="{{ route('admin.approvals.index') }}"
           class="inline-flex h-9 items-center gap-1.5 rounded-xl px-3.5 text-xs font-medium transition {{ request('type') ? 'bg-white text-ink-600 ring-1 ring-inset ring-ink-200 hover:bg-ink-50' : 'bg-ink-950 text-white' }}">
            Semua
        </a>
        @foreach (\App\Http\Controllers\Admin\ApprovalController::TYPES as $key => $config)
            <a href="{{ route('admin.approvals.index', ['type' => $key]) }}"
               class="inline-flex h-9 items-center gap-1.5 rounded-xl px-3.5 text-xs font-medium transition {{ request('type') === $key ? 'bg-ink-950 text-white' : 'bg-white text-ink-600 ring-1 ring-inset ring-ink-200 hover:bg-ink-50' }}">
                <x-icon :name="$config['icon']" class="h-3.5 w-3.5" />
                {{ $config['label'] }}
            </a>
        @endforeach
    </div>

    @if ($total === 0)
        <div class="rounded-2xl border border-ink-100 bg-white shadow-card">
            <div class="flex flex-col items-center justify-center px-6 py-16 text-center">
                <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
                    <x-icon name="check-circle" class="h-6 w-6" />
                </span>
                <h3 class="mt-4 text-sm font-semibold tracking-tight text-ink-950">Tidak ada yang menunggu</h3>
                <p class="mt-1 max-w-sm text-sm text-ink-500">
                    Semua dokumen sudah diputuskan. Pengajuan baru akan muncul di sini.
                </p>
            </div>
        </div>
    @else
        <div class="space-y-6">
            @foreach ($groups as $group)
                @continue($group['documents']->isEmpty())

                <div>
                    <div class="mb-3 flex items-center gap-2">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-ink-950 text-white">
                            <x-icon :name="$group['icon']" class="h-4 w-4" />
                        </span>
                        <h2 class="text-sm font-semibold tracking-tight text-ink-950">{{ $group['label'] }}</h2>
                        <span class="text-xs text-ink-400">({{ $group['documents']->count() }})</span>
                    </div>

                    <div class="space-y-3">
                        @foreach ($group['documents'] as $document)
                            <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                                <div class="flex flex-wrap items-start justify-between gap-4 p-5">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route($group['route'], $document) }}"
                                               class="font-mono text-sm font-semibold text-ink-950 underline-offset-4 hover:underline">
                                                {{ $document->code }}
                                            </a>
                                            <x-ui.badge variant="warning" icon="clock">Menunggu persetujuan</x-ui.badge>
                                        </div>

                                        <p class="mt-1 text-xs text-ink-500">
                                            Diajukan {{ $document->submitted_at?->diffForHumans() }}
                                            @if ($document->submitter) oleh {{ $document->submitter->name }} @endif
                                            &middot; {{ $document->date->translatedFormat('d M Y') }}
                                        </p>

                                        {{-- Ringkasan isi dokumen: SKU sebagai identitas --}}
                                        <div class="mt-3 space-y-1.5">
                                            @foreach ($document->items as $item)
                                                <div class="flex flex-wrap items-center gap-2 rounded-lg border border-ink-100 px-2.5 py-1.5">
                                                    <x-ui.sku :value="$item->product->sku" />
                                                    <span class="min-w-0 flex-1 truncate text-xs text-ink-700">{{ $item->product->name }}</span>
                                                    @if ($item instanceof \App\Models\ReturnReceiptItem)
                                                        @if ($item->damaged_quantity > 0)
                                                            <x-ui.badge variant="danger">{{ $item->damaged_quantity }} rusak</x-ui.badge>
                                                        @endif
                                                        @if ($item->hasMissing())
                                                            <x-ui.badge variant="warning">{{ $item->missingQuantity() }} hilang</x-ui.badge>
                                                        @endif
                                                    @endif
                                                    <span class="shrink-0 text-xs font-semibold text-ink-950">
                                                        {{ $item->quantity }} {{ $item->product->unit }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    {{-- Keputusan --}}
                                    @can($group['permission'])
                                        <div class="flex w-full shrink-0 flex-col gap-2 sm:w-48"
                                             x-data="{ rejecting: false }">
                                            <form method="POST" action="{{ route('admin.approvals.approve', [$group['key'], $document->id]) }}"
                                                  x-show="! rejecting">
                                                @csrf
                                                <x-ui.button type="submit" icon="check" class="w-full">Setujui</x-ui.button>
                                            </form>

                                            <x-ui.button type="button" variant="danger-soft" icon="close"
                                                         x-show="! rejecting" x-on:click="rejecting = true" class="w-full">
                                                Tolak
                                            </x-ui.button>

                                            <form method="POST" action="{{ route('admin.approvals.reject', [$group['key'], $document->id]) }}"
                                                  x-show="rejecting" x-cloak class="space-y-2">
                                                @csrf
                                                <textarea name="rejection_reason" rows="2" required
                                                          placeholder="Alasan penolakan…"
                                                          class="block w-full rounded-xl border-ink-200 bg-white text-xs text-ink-950 placeholder:text-ink-300 shadow-soft transition focus:border-red-500 focus:ring-1 focus:ring-red-500"></textarea>
                                                <div class="flex gap-2">
                                                    <x-ui.button type="submit" variant="danger" size="sm" class="flex-1">Kirim</x-ui.button>
                                                    <x-ui.button type="button" variant="secondary" size="sm"
                                                                 x-on:click="rejecting = false" class="flex-1">Batal</x-ui.button>
                                                </div>
                                            </form>
                                        </div>
                                    @else
                                        <p class="shrink-0 text-xs text-ink-400">Anda tidak berwenang memutuskan dokumen ini.</p>
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-app-layout>
