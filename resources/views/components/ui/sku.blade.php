@props(['value', 'variant' => 'soft', 'label' => true])

@php
    // SKU adalah kunci utama pencocokan barang, jadi selalu ditampilkan
    // dengan gaya yang sama di seluruh aplikasi agar mudah dikenali.
    $variants = [
        'soft' => 'bg-ink-100 text-ink-800 ring-ink-200',
        'solid' => 'bg-ink-950 text-white ring-ink-950',
        'outline' => 'bg-white text-ink-700 ring-ink-200',
        'danger' => 'bg-red-50 text-red-700 ring-red-200',
    ];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex max-w-full items-center gap-1 rounded-md px-1.5 py-0.5 font-mono text-[11px] font-semibold leading-4 tracking-tight ring-1 ring-inset '
        .($variants[$variant] ?? $variants['soft']),
]) }} title="SKU: {{ $value }}">
    @if ($label)
        <span class="opacity-50">SKU</span>
    @endif
    <span class="truncate">{{ $value ?: '—' }}</span>
</span>
