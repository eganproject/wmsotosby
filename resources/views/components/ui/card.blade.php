@props(['title' => null, 'subtitle' => null, 'padding' => 'p-5 sm:p-6'])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-ink-100 bg-white shadow-card']) }}>
    @if ($title || $subtitle || isset($actions))
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-ink-100 px-5 py-4 sm:px-6">
            <div>
                @if ($title)
                    <h2 class="text-sm font-semibold tracking-tight text-ink-950">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 text-xs text-ink-500">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div class="{{ $padding }}">
        {{ $slot }}
    </div>
</div>
