@props(['href' => '#', 'icon' => 'circle', 'active' => false, 'badge' => null, 'disabled' => false])

@php
    $state = $active
        ? 'bg-ink-950 text-white shadow-soft'
        : ($disabled ? 'cursor-not-allowed text-ink-300' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-950');

    $iconState = $active
        ? 'text-white'
        : ($disabled ? 'text-ink-300' : 'text-ink-400 group-hover:text-ink-950');

    $classes = 'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition duration-150 '.$state;
@endphp

@if ($disabled)
    <span {{ $attributes->merge(['class' => $classes]) }}>
        <x-icon :name="$icon" class="h-[18px] w-[18px] shrink-0 {{ $iconState }}" />
        <span class="flex-1 truncate">{{ $slot }}</span>
        @if ($badge)
            <span class="rounded-full bg-ink-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-400">{{ $badge }}</span>
        @endif
    </span>
@else
    <a href="{{ $href }}" @if ($active) aria-current="page" @endif {{ $attributes->merge(['class' => $classes]) }}>
        <x-icon :name="$icon" class="h-[18px] w-[18px] shrink-0 {{ $iconState }}" />
        <span class="flex-1 truncate">{{ $slot }}</span>
        @if ($badge)
            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $active ? 'bg-white/15 text-white' : 'bg-ink-100 text-ink-500' }}">{{ $badge }}</span>
        @endif
    </a>
@endif
