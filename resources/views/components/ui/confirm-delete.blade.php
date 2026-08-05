@props([
    'action',
    'title' => 'Hapus data ini?',
    'description' => 'Tindakan ini tidak dapat dibatalkan.',
    'confirm' => 'Ya, hapus',
])

<div x-data="{ open: false }" class="inline-flex">
    @isset($trigger)
        <span @click="open = true">{{ $trigger }}</span>
    @else
        <button type="button" @click="open = true"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
            <x-icon name="trash" class="h-4 w-4" />
            <span class="sr-only">Hapus</span>
        </button>
    @endisset

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center" role="dialog" aria-modal="true">
            <div x-show="open"
                 x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0"
                 x-transition:leave="ease-in duration-150" x-transition:leave-end="opacity-0"
                 @click="open = false" class="fixed inset-0 bg-ink-950/40 backdrop-blur-sm"></div>

            <div x-show="open"
                 x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-4 sm:scale-95"
                 x-transition:leave="ease-in duration-150" x-transition:leave-end="opacity-0 translate-y-4 sm:scale-95"
                 @keydown.escape.window="open = false"
                 class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white p-6 shadow-lift">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600">
                        <x-icon name="warning" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold tracking-tight text-ink-950">{{ $title }}</h3>
                        <p class="mt-1 text-sm text-ink-500">{{ $description }}</p>
                    </div>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-ui.button type="button" variant="secondary" @click="open = false">Batal</x-ui.button>

                    <form method="POST" action="{{ $action }}">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger" icon="trash" class="w-full sm:w-auto">
                            {{ $confirm }}
                        </x-ui.button>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
