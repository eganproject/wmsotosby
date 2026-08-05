@props(['name' => '', 'size' => 'md'])

@php
    $sizes = [
        'sm' => 'h-8 w-8 text-[11px]',
        'md' => 'h-10 w-10 text-xs',
        'lg' => 'h-14 w-14 text-base',
        'xl' => 'h-20 w-20 text-xl',
    ];

    $initials = collect(preg_split('/\s+/', trim($name)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex shrink-0 items-center justify-center rounded-full bg-ink-950 font-semibold uppercase tracking-wide text-white ring-1 ring-ink-950/10 '.($sizes[$size] ?? $sizes['md']),
]) }}>
    {{ $initials ?: '?' }}
</span>
