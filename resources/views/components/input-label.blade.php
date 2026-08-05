@props(['value' => null, 'required' => false])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-ink-800']) }}>
    {{ $value ?? $slot }}
    @if ($required)
        <span class="text-red-500">*</span>
    @endif
</label>
