@props(['variant' => 'neutral', 'icon' => null])

@php
    $variants = [
        'neutral' => 'bg-ink-100 text-ink-700 ring-ink-200',
        'dark' => 'bg-ink-950 text-white ring-ink-950',
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'danger' => 'bg-red-50 text-red-700 ring-red-200',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'outline' => 'bg-white text-ink-600 ring-ink-200',
    ];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.($variants[$variant] ?? $variants['neutral']),
]) }}>
    @if ($icon)
        <x-icon :name="$icon" class="h-3.5 w-3.5" />
    @endif
    {{ $slot }}
</span>
