@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'flex items-start gap-2 rounded-xl bg-emerald-50 px-3.5 py-3 text-sm font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200']) }}>
        <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
        <span>{{ $status }}</span>
    </div>
@endif
