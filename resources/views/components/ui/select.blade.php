@props(['disabled' => false])

<select @disabled($disabled) {{ $attributes->merge([
    'class' => 'block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950 disabled:bg-ink-50 disabled:text-ink-400',
]) }}>
    {{ $slot }}
</select>
