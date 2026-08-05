@props(['name', 'checked' => false, 'label' => null, 'description' => null])

<label class="flex cursor-pointer items-start gap-3">
    <input type="hidden" name="{{ $name }}" value="0">
    <span class="relative mt-0.5 inline-flex shrink-0">
        <input type="checkbox" name="{{ $name }}" value="1" @checked($checked)
               {{ $attributes->merge(['class' => 'peer h-6 w-11 cursor-pointer appearance-none rounded-full border-0 bg-ink-200 bg-none transition-colors checked:bg-ink-950 checked:bg-none focus:ring-2 focus:ring-ink-950 focus:ring-offset-2 focus:ring-offset-white']) }}>
        <span class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
    </span>

    @if ($label || $description)
        <span class="min-w-0">
            @if ($label)
                <span class="block text-sm font-medium text-ink-800">{{ $label }}</span>
            @endif
            @if ($description)
                <span class="block text-xs text-ink-500">{{ $description }}</span>
            @endif
        </span>
    @endif
</label>
