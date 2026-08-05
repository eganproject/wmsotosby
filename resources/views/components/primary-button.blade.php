<button {{ $attributes->merge([
    'type' => 'submit',
    'class' => 'inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-ink-950 px-4 text-sm font-medium text-white shadow-soft transition duration-150 hover:bg-ink-800 active:bg-black focus:outline-none focus-visible:ring-2 focus-visible:ring-ink-950 focus-visible:ring-offset-2 disabled:opacity-50',
]) }}>
    {{ $slot }}
</button>
