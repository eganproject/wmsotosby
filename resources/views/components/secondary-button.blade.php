<button {{ $attributes->merge([
    'type' => 'button',
    'class' => 'inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-white px-4 text-sm font-medium text-ink-950 shadow-soft ring-1 ring-inset ring-ink-200 transition duration-150 hover:bg-ink-50 hover:ring-ink-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-ink-950 focus-visible:ring-offset-2 disabled:opacity-50',
]) }}>
    {{ $slot }}
</button>
