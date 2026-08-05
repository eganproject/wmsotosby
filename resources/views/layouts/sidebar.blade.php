@php
    $user = auth()->user();
@endphp

<div class="flex h-full flex-col bg-white">
    {{-- Brand --}}
    <div class="flex h-16 shrink-0 items-center gap-3 border-b border-ink-100 px-5">
        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-ink-950 text-white">
            <x-application-logo class="h-5 w-5" />
        </span>
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold tracking-tight text-ink-950">{{ config('app.name', 'WMS Otosby') }}</p>
            <p class="truncate text-[11px] text-ink-400">Warehouse Management</p>
        </div>
    </div>

    {{-- Navigation --}}
    @include('layouts.nav')

    {{-- Account --}}
    <div class="shrink-0 border-t border-ink-100 p-3">
        <div class="flex items-center gap-3 rounded-xl px-2 py-2">
            <x-ui.avatar :name="$user->name" size="sm" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink-950">{{ $user->name }}</p>
                <p class="truncate text-[11px] text-ink-400">{{ $user->role?->name ?? 'Tanpa role' }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" title="Keluar"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                    <x-icon name="logout" class="h-4 w-4" />
                    <span class="sr-only">Keluar</span>
                </button>
            </form>
        </div>
    </div>
</div>
