<button {{ $attributes->merge([
    'type' => 'submit',
    'class' => 'inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-red-600 px-4 text-sm font-medium text-white shadow-soft transition duration-150 hover:bg-red-700 active:bg-red-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 disabled:opacity-50',
]) }}>
    {{ $slot }}
</button>
