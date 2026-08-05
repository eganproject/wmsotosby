@props(['icon' => 'box', 'title' => 'Belum ada data', 'description' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-14 text-center']) }}>
    <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-ink-50 text-ink-400 ring-1 ring-ink-100">
        <x-icon :name="$icon" class="h-6 w-6" />
    </span>
    <h3 class="mt-4 text-sm font-semibold tracking-tight text-ink-950">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-ink-500">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-5">{{ $action }}</div>
    @endisset
</div>
