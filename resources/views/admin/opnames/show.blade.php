{{--
    Layar hitung stok opname.

    Petugas berjalan di gudang sambil membawa layar ini: scan barcode untuk
    melompat ke barangnya, ketik hasil hitung, lanjut. Yang belum dihitung
    tetap NULL — rak kosong ("0") adalah temuan, dan itu berbeda dari
    "belum sempat dihitung".
--}}
<x-app-layout title="Hitung Stok Opname">
    <x-ui.page-header :title="$opname->code"
                      :subtitle="$opname->scopeLabel().' · '.$opname->date->translatedFormat('d F Y')"
                      :back="route('admin.opnames.index')">
        <x-slot name="actions">
            @if ($opname->isEditable())
                @can('opnames.post')
                    <form method="POST" action="{{ route('admin.opnames.submit', $opname) }}">
                        @csrf
                        <x-ui.button type="submit" :icon="auth()->user()->can('opnames.approve') ? 'check' : 'clock'">
                            {{ auth()->user()->can('opnames.approve') ? 'Terapkan Hasil' : 'Ajukan Persetujuan' }}
                        </x-ui.button>
                    </form>
                @endcan
            @else
                @include('admin.partials.approval-actions', ['document' => $opname, 'prefix' => 'opnames'])
            @endif
        </x-slot>
    </x-ui.page-header>

    @php
        // Satu query agregat, dipakai seluruh halaman.
        $summary = $opname->summary();
        $contributors = $opname->contributors();

        $total = $summary['total'];
        $counted = $summary['counted'];
        $variance = $summary['variance'];
        $accuracy = $opname->accuracyPercentage();
        $accuracyTone = $accuracy >= 95 ? 'emerald' : ($accuracy >= 80 ? 'amber' : 'red');
    @endphp

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Progres Hitung" :value="$counted.'/'.$total" icon="check-circle" accent
                        :hint="$opname->progressPercentage().'% SKU sudah dihitung'" />
        <x-ui.stat-card label="Belum Dihitung" :value="$total - $counted" icon="clock"
                        hint="Barisnya tidak akan mengubah stok" />
        <x-ui.stat-card label="Lebih" :value="'+'.number_format($summary['surplus'], 0, ',', '.')"
                        icon="trending-up" :hint="$variance.' SKU berselisih'" />
        <x-ui.stat-card label="Kurang" :value="'-'.number_format($summary['shortage'], 0, ',', '.')"
                        icon="warning" hint="Unit yang hilang dari catatan" />
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @if ($opname->isEditable())
                {{-- Scan melompat ke barisnya; tidak ada perjalanan ke server. --}}
                <div x-data="opnameCounter()" class="space-y-4">
                    <div class="rounded-2xl border border-ink-950 bg-ink-950 p-4 shadow-lift">
                        <form data-no-ajax @submit.prevent="jump()">
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-12 items-center justify-center text-white/30">
                                    <x-icon name="search" class="h-5 w-5" />
                                </span>
                                <input x-ref="scan" x-model="code" type="text" autocomplete="off" autocapitalize="off"
                                       spellcheck="false" placeholder="Scan barcode atau ketik SKU untuk melompat…"
                                       class="block h-12 w-full rounded-xl border-0 bg-white/10 pl-12 pr-4 font-mono text-sm text-white placeholder:font-sans placeholder:text-sm placeholder:text-white/30 ring-1 ring-inset ring-white/15 focus:bg-white/[0.15] focus:ring-2 focus:ring-white">
                            </div>
                        </form>
                        <p class="mt-2 h-4 text-[11px]" :class="message.type === 'error' ? 'text-red-300' : 'text-white/50'"
                           x-text="message.text || 'Barangnya langsung disorot dan kursor pindah ke kolom hitung.'"></p>
                    </div>

                    <form method="POST" action="{{ route('admin.opnames.count', $opname) }}"
                          @submit="pruneUntouched()">
                        @csrf

                        <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-3">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @foreach ([
                                        '' => 'Semua',
                                        'uncounted' => 'Belum dihitung',
                                        'variance' => 'Berselisih',
                                    ] as $value => $label)
                                        <a href="{{ route('admin.opnames.show', array_filter(['opname' => $opname->id, 'filter' => $value, 'search' => request('search')])) }}"
                                           @class([
                                               'rounded-lg px-2.5 py-1 text-xs font-medium transition',
                                               'bg-ink-950 text-white' => request('filter', '') === $value,
                                               'text-ink-500 hover:bg-ink-100' => request('filter', '') !== $value,
                                           ])>{{ $label }}</a>
                                    @endforeach
                                </div>

                                @can('opnames.update')
                                    <button type="submit"
                                            class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-ink-950 px-3 text-xs font-semibold text-white transition hover:bg-ink-800">
                                        <x-icon name="check" class="h-3.5 w-3.5" /> Simpan Hitungan
                                    </button>
                                @endcan
                            </div>

                            @if ($items->isEmpty())
                                <x-ui.empty-state icon="check-circle" title="Tidak ada baris pada saringan ini"
                                                  description="Ganti saringan di atas untuk melihat baris lainnya." />
                            @else
                                <ul class="divide-y divide-ink-50">
                                    @foreach ($items as $item)
                                        <li class="px-5 py-3.5 transition"
                                            data-sku="{{ strtoupper($item->product->sku) }}"
                                            data-barcode="{{ strtoupper($item->product->barcode ?? '') }}"
                                            :class="highlighted === {{ $item->id }} && 'bg-amber-50'">
                                            {{--
                                                Di ponsel barisnya menumpuk: nama barang di atas,
                                                lalu selisih dan kolom hitung berdampingan dengan
                                                kolom yang cukup lebar untuk jempol.
                                            --}}
                                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
                                                <div class="min-w-0 flex-1">
                                                    <x-ui.sku :value="$item->product->sku" />
                                                    <p class="mt-1 truncate text-sm font-medium text-ink-950">{{ $item->product->name }}</p>
                                                    <p class="text-[11px] text-ink-400">
                                                        Tercatat {{ $item->system_quantity }} {{ $item->product->unit }}
                                                        @if ($item->counted_at)
                                                            &middot; dihitung {{ $item->counted_at->format('H:i') }}
                                                            @if ($item->counter) oleh {{ $item->counter->name }} @endif
                                                        @endif
                                                    </p>
                                                </div>

                                                <div class="flex items-center gap-3">
                                                    @php $difference = $item->difference(); @endphp
                                                    <div class="w-20 shrink-0 text-right sm:w-24">
                                                        <span @class([
                                                            'inline-flex rounded-lg px-2 py-1 text-sm font-semibold tabular-nums',
                                                            'bg-ink-50 text-ink-300' => ! $item->isCounted(),
                                                            'bg-ink-50 text-ink-500' => $item->isCounted() && $difference === 0,
                                                            'bg-emerald-50 text-emerald-700' => $difference > 0,
                                                            'bg-red-50 text-red-700' => $difference < 0,
                                                        ])>{{ $item->differenceLabel() }}</span>
                                                    </div>

                                                    {{-- Nilai awal ikut dikirim agar baris yang sudah
                                                         dihitung rekan lain tidak tertimpa. --}}
                                                    <input type="hidden" name="baseline[{{ $item->id }}]"
                                                           value="{{ $item->counted_quantity }}">

                                                    <div class="flex flex-1 gap-2 sm:flex-none">
                                                        <div class="flex-1 sm:w-24 sm:flex-none">
                                                            <label class="mb-1 block text-center text-[10px] font-medium uppercase tracking-wider text-ink-400"
                                                                   for="count-{{ $item->id }}">Bagus</label>
                                                            <input id="count-{{ $item->id }}"
                                                                   type="number" min="0" max="999999" inputmode="numeric"
                                                                   name="counts[{{ $item->id }}]"
                                                                   value="{{ $item->counted_quantity }}"
                                                                   data-count-input="{{ $item->id }}"
                                                                   data-baseline="{{ $item->counted_quantity }}"
                                                                   placeholder="—"
                                                                   @disabled(! auth()->user()->can('opnames.update'))
                                                                   class="h-12 w-full rounded-xl border-ink-200 text-center text-base font-semibold tabular-nums shadow-sm focus:border-ink-950 focus:ring-ink-950 sm:h-11 sm:text-sm">
                                                        </div>

                                                        {{--
                                                            Rusak dicatat terpisah, bukan dikurangkan diam-diam
                                                            dari hitungan bagus. Selisih yang tidak dijelaskan
                                                            terbaca sebagai barang hilang — padahal hilang perlu
                                                            diselidiki, sedangkan rusak bisa diklaim ke pemasok.
                                                        --}}
                                                        <div class="flex-1 sm:w-24 sm:flex-none">
                                                            <label class="mb-1 block text-center text-[10px] font-medium uppercase tracking-wider text-red-400"
                                                                   for="damaged-{{ $item->id }}">Rusak</label>
                                                            <input id="damaged-{{ $item->id }}"
                                                                   type="number" min="0" max="999999" inputmode="numeric"
                                                                   name="damaged[{{ $item->id }}]"
                                                                   value="{{ $item->damaged_quantity ?: '' }}"
                                                                   data-damaged-input="{{ $item->id }}"
                                                                   data-baseline="{{ $item->damaged_quantity ?: '' }}"
                                                                   placeholder="0"
                                                                   @disabled(! auth()->user()->can('opnames.update'))
                                                                   class="h-12 w-full rounded-xl border-ink-200 text-center text-base font-semibold tabular-nums text-red-700 shadow-sm placeholder:font-normal placeholder:text-ink-300 focus:border-red-500 focus:ring-red-500 sm:h-11 sm:text-sm">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <x-ui.pagination :paginator="$items" />
                        </div>
                    </form>
                </div>
            @else
                {{-- Sesi terkunci: hasilnya dibaca, tidak diubah. --}}
                <x-ui.card title="Hasil Hitung" :subtitle="$counted.' dari '.$total.' SKU dihitung'" padding="p-0">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-ink-100 text-left">
                            <thead class="bg-ink-50/60">
                                <tr>
                                    <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                                    <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Tercatat</th>
                                    <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Hitung</th>
                                    <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Selisih</th>
                                    <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Dihitung Oleh</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-ink-50">
                                @foreach ($items as $item)
                                    <tr>
                                        <td class="px-6 py-3.5">
                                            <p class="text-sm font-medium text-ink-950">{{ $item->product->name }}</p>
                                            <div class="mt-1"><x-ui.sku :value="$item->product->sku" /></div>
                                        </td>
                                        <td class="px-6 py-3.5 text-right text-sm tabular-nums text-ink-500">{{ $item->system_quantity }}</td>
                                        <td class="px-6 py-3.5 text-right text-sm font-semibold tabular-nums text-ink-950">
                                            {{ $item->isCounted() ? $item->counted_quantity : '—' }}
                                        </td>
                                        <td class="px-6 py-3.5 text-right">
                                            <span @class([
                                                'text-sm font-semibold tabular-nums',
                                                'text-emerald-600' => $item->difference() > 0,
                                                'text-red-600' => $item->difference() < 0,
                                                'text-ink-400' => $item->difference() === 0,
                                            ])>{{ $item->differenceLabel() }}</span>

                                            @if ($item->wasAppliedDifferently())
                                                <p class="text-[11px] text-amber-600">dibukukan {{ $item->applied_difference }}</p>
                                            @endif

                                            {{-- Rusak disebut terpisah: ia bukan bagian dari selisih. --}}
                                            @if ($item->hasDamaged())
                                                <p class="text-[11px] font-medium text-red-600">{{ $item->damaged_quantity }} rusak</p>
                                            @endif
                                        </td>
                                        <td class="px-6 py-3.5">
                                            <p class="text-xs text-ink-600">{{ $item->counter?->name ?? '—' }}</p>
                                            @if ($item->counted_at)
                                                <p class="text-[11px] text-ink-400">{{ $item->counted_at->translatedFormat('d M, H:i') }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <x-ui.pagination :paginator="$items" />
                </x-ui.card>
            @endif
        </div>

        <x-ui.card title="Status Sesi">
            <div class="space-y-5">
                @include('admin.partials.approval-status', ['document' => $opname])

                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Cakupan</dt>
                        <dd class="text-right font-medium text-ink-950">{{ $opname->scopeLabel() }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Barang dipotret</dt>
                        <dd class="font-medium text-ink-950">{{ $total }} SKU</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Berselisih</dt>
                        <dd class="font-medium text-ink-950">{{ $variance }} SKU</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Dibuka oleh</dt>
                        <dd class="font-medium text-ink-950">{{ $opname->user?->name ?? '—' }}</dd>
                    </div>
                </dl>

                {{--
                    Akurasi menjawab pertanyaan yang sebenarnya dicari dari
                    opname: seberapa bisa dipercaya catatan stok selama ini.
                --}}
                @if ($counted > 0)
                    <div class="border-t border-ink-100 pt-4">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-xs font-semibold uppercase tracking-wider text-ink-400">Akurasi Catatan</p>
                            <p class="text-2xl font-semibold tracking-tight
                                      text-{{ $accuracyTone }}-600">
                                {{ $accuracy }}%
                            </p>
                        </div>

                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
                            <div class="h-full rounded-full bg-{{ $accuracyTone }}-500"
                                 style="width: {{ $accuracy }}%"></div>
                        </div>

                        <p class="mt-1.5 text-[11px] leading-relaxed text-ink-400">
                            {{ $summary['matched'] }} dari {{ $counted }} SKU yang dihitung sudah sesuai catatan.
                        </p>

                        <dl class="mt-3 space-y-2 text-xs">
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-ink-500">Akurasi per unit</dt>
                                <dd class="font-semibold text-ink-950">{{ $opname->unitAccuracyPercentage() }}%</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-ink-500">Tercatat vs ditemukan</dt>
                                <dd class="font-medium tabular-nums text-ink-950">
                                    {{ number_format($opname->countedSystemUnits(), 0, ',', '.') }}
                                    &rarr;
                                    {{ number_format($opname->countedUnits(), 0, ',', '.') }} unit
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-ink-500">Total meleset</dt>
                                <dd class="font-medium tabular-nums text-ink-950">{{ number_format($opname->absoluteVariance(), 0, ',', '.') }} unit</dd>
                            </div>
                        </dl>
                    </div>
                @endif

                @if ($opname->isEditable() && $total > $counted)
                    <p class="flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-[11px] leading-relaxed text-amber-800 ring-1 ring-inset ring-amber-200">
                        <x-icon name="warning" class="mt-px h-3.5 w-3.5 shrink-0" />
                        <span>
                            {{ $total - $counted }} SKU belum dihitung. Barisnya dilewati saat hasil diterapkan —
                            stoknya dibiarkan apa adanya, bukan dianggap nol.
                        </span>
                    </p>
                @endif

                @if ($opname->note)
                    <p class="border-t border-ink-100 pt-4 text-xs leading-relaxed text-ink-500">{{ $opname->note }}</p>
                @endif
            </div>
        </x-ui.card>

        {{--
            Satu sesi boleh dikerjakan beberapa orang sekaligus; rekap ini
            yang membuat pembagian kerjanya terbaca tanpa membuka tiap baris.
        --}}
        @if ($contributors->isNotEmpty())
            <x-ui.card title="Petugas yang Menghitung"
                       :subtitle="$contributors->count().' orang mengerjakan sesi ini'"
                       class="lg:col-start-3">
                <ul class="divide-y divide-ink-50">
                    @foreach ($contributors as $contributor)
                        <li class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                            <x-ui.avatar :name="$contributor['name']" size="sm" />

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-ink-950">{{ $contributor['name'] }}</p>
                                <p class="text-[11px] text-ink-400">
                                    {{ $contributor['counted'] }} SKU dihitung
                                    &middot; {{ $contributor['variance'] }} berselisih
                                    @if ($contributor['last_at'])
                                        &middot; terakhir {{ \Illuminate\Support\Carbon::parse($contributor['last_at'])->format('H:i') }}
                                    @endif
                                </p>
                            </div>

                            <span class="shrink-0 rounded-lg bg-ink-50 px-2 py-1 text-xs font-semibold tabular-nums text-ink-700">
                                {{ $counted > 0 ? round($contributor['counted'] / $counted * 100) : 0 }}%
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </div>
</x-app-layout>
