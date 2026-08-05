@php
    $messages = array_filter([
        'success' => session('success'),
        'error' => session('error'),
        'status' => session('status'),
    ]);

    $styles = [
        'success' => ['icon' => 'check-circle', 'ring' => 'ring-emerald-200', 'text' => 'text-emerald-600'],
        'error' => ['icon' => 'x-circle', 'ring' => 'ring-red-200', 'text' => 'text-red-600'],
        'status' => ['icon' => 'info', 'ring' => 'ring-ink-200', 'text' => 'text-ink-600'],
    ];
@endphp

@if (! empty($messages))
    <div class="pointer-events-none fixed inset-x-0 top-4 z-50 flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6">
        @foreach ($messages as $type => $message)
            <div x-data="{ show: true }"
                 x-show="show"
                 x-init="setTimeout(() => show = false, 5000)"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-end="opacity-0 -translate-y-2"
                 class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-2xl bg-white px-4 py-3 shadow-lift ring-1 {{ $styles[$type]['ring'] }}">
                <x-icon :name="$styles[$type]['icon']" class="mt-0.5 h-5 w-5 shrink-0 {{ $styles[$type]['text'] }}" />
                <p class="flex-1 text-sm text-ink-700">{{ $message }}</p>
                <button type="button" @click="show = false" class="text-ink-300 transition hover:text-ink-600">
                    <x-icon name="close" class="h-4 w-4" />
                    <span class="sr-only">Tutup</span>
                </button>
            </div>
        @endforeach
    </div>
@endif
