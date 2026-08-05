@props(['label' => null, 'for' => null, 'hint' => null, 'error' => null, 'required' => false])

<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    @if ($label)
        <x-input-label :for="$for" :value="$label" :required="$required" />
    @endif

    {{ $slot }}

    @if ($hint)
        <p class="text-xs text-ink-400">{{ $hint }}</p>
    @endif

    <x-input-error :messages="$error" />
</div>
