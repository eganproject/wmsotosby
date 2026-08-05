@props(['product'])

@php
    $config = match ($product->stockStatus()) {
        'habis' => ['variant' => 'danger', 'icon' => 'x-circle', 'label' => 'Habis'],
        'menipis' => ['variant' => 'warning', 'icon' => 'warning', 'label' => 'Menipis'],
        default => ['variant' => 'success', 'icon' => 'check-circle', 'label' => 'Aman'],
    };
@endphp

<x-ui.badge :variant="$config['variant']" :icon="$config['icon']" {{ $attributes }}>
    {{ $config['label'] }}
</x-ui.badge>
