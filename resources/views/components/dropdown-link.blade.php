@props(['icon' => null])

<a {{ $attributes->merge([
    'class' => 'flex w-full items-center gap-2.5 px-3 py-2 text-start text-sm text-ink-600 transition hover:bg-ink-50 hover:text-ink-950 focus:bg-ink-50 focus:outline-none',
]) }}>
    @if ($icon)
        <x-icon :name="$icon" class="h-4 w-4 text-ink-400" />
    @endif
    {{ $slot }}
</a>
