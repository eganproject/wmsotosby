@props(['group'])

@php
    $tabs = \App\Support\SectionTabs::for($group);
@endphp

{{--
    Tab antar halaman sekelompok. Setiap tab adalah tautan ke route-nya
    sendiri, jadi hanya data tab yang dibuka yang di-query.
--}}
@if (count($tabs) > 1)
    <div class="mb-5 flex items-center gap-1 overflow-x-auto rounded-2xl border border-ink-100 bg-white p-1.5 shadow-card">
        @foreach ($tabs as $tab)
            <a href="{{ $tab['href'] }}" @if ($tab['active']) aria-current="page" @endif
               @class([
                   'inline-flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium transition',
                   'bg-ink-950 text-white shadow-soft' => $tab['active'],
                   'text-ink-600 hover:bg-ink-100 hover:text-ink-950' => ! $tab['active'],
               ])>
                <x-icon :name="$tab['icon']" class="h-4 w-4 shrink-0 {{ $tab['active'] ? 'text-white' : 'text-ink-400' }}" />
                {{ $tab['label'] }}

                @if ($tab['badge'])
                    <span @class([
                        'rounded-md px-1.5 py-0.5 text-[11px] font-semibold tabular-nums',
                        'bg-white/15 text-white' => $tab['active'],
                        'bg-ink-100 text-ink-600' => ! $tab['active'],
                    ])>{{ $tab['badge'] }}</span>
                @endif
            </a>
        @endforeach
    </div>
@endif
