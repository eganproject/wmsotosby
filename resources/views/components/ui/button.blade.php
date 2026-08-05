@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'submit',
    'icon' => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-xl font-medium tracking-tight transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-ink-950 disabled:opacity-50 disabled:pointer-events-none whitespace-nowrap';

    $variants = [
        'primary' => 'bg-ink-950 text-white shadow-soft hover:bg-ink-800 active:bg-black',
        'secondary' => 'bg-white text-ink-950 ring-1 ring-inset ring-ink-200 shadow-soft hover:bg-ink-50 hover:ring-ink-300',
        'ghost' => 'text-ink-500 hover:bg-ink-100 hover:text-ink-950',
        'danger' => 'bg-red-600 text-white shadow-soft hover:bg-red-700 focus-visible:ring-red-600',
        'danger-soft' => 'bg-white text-red-600 ring-1 ring-inset ring-red-200 hover:bg-red-50 focus-visible:ring-red-600',
    ];

    $sizes = [
        'sm' => 'h-9 px-3 text-xs',
        'md' => 'h-10 px-4 text-sm',
        'lg' => 'h-12 px-6 text-sm',
        'icon' => 'h-9 w-9',
    ];

    $classes = implode(' ', [$base, $variants[$variant] ?? $variants['primary'], $sizes[$size] ?? $sizes['md']]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)
            <x-icon :name="$icon" class="h-4 w-4" />
        @endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)
            <x-icon :name="$icon" class="h-4 w-4" />
        @endif
        {{ $slot }}
    </button>
@endif
