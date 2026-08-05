@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'space-y-1 text-xs font-medium text-red-600']) }}>
        @foreach ((array) $messages as $message)
            <li class="flex items-start gap-1.5">
                <x-icon name="x-circle" class="mt-px h-3.5 w-3.5 shrink-0" />
                <span>{{ $message }}</span>
            </li>
        @endforeach
    </ul>
@endif
