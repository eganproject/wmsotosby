@props(['checked' => false])

<input type="checkbox" @checked($checked) {{ $attributes->merge([
    'class' => 'h-4 w-4 cursor-pointer rounded border-ink-300 text-ink-950 shadow-soft transition focus:ring-2 focus:ring-ink-950 focus:ring-offset-1',
]) }}>
