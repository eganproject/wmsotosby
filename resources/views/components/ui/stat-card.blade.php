@props(['label', 'value', 'icon' => 'chart', 'hint' => null, 'accent' => false])

<div {{ $attributes->merge([
    'class' => 'group relative overflow-hidden rounded-2xl border p-5 shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-lift '
        .($accent ? 'border-ink-950 bg-ink-950 text-white' : 'border-ink-100 bg-white'),
]) }}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-xs font-medium uppercase tracking-wider {{ $accent ? 'text-white/60' : 'text-ink-400' }}">
                {{ $label }}
            </p>
            <p class="mt-2 text-3xl font-semibold tracking-tight {{ $accent ? 'text-white' : 'text-ink-950' }}">
                {{ $value }}
            </p>
            @if ($hint)
                <p class="mt-1 text-xs {{ $accent ? 'text-white/60' : 'text-ink-500' }}">{{ $hint }}</p>
            @endif
        </div>

        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $accent ? 'bg-white/10 text-white' : 'bg-ink-50 text-ink-950 ring-1 ring-ink-100' }}">
            <x-icon :name="$icon" class="h-5 w-5" />
        </span>
    </div>

    <div class="pointer-events-none absolute -right-10 -top-10 h-28 w-28 rounded-full {{ $accent ? 'bg-white/5' : 'bg-ink-50/60' }}"></div>
</div>
