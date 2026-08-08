@php
    $pageTitle = $title ?? 'Dashboard';
    $documentTitle = $pageTitle.' — '.config('app.name', 'WMS');
@endphp

{{-- Permintaan AJAX hanya menerima potongan halaman, bukan dokumen penuh. --}}
@if (request()->header('X-Page-Fragment') === '1')
    <div id="page-fragment" data-title="{{ $documentTitle }}" data-heading="{{ $pageTitle }}">
        <template data-fragment="nav">@include('layouts.nav')</template>
        <template data-fragment="bottom-nav">@include('layouts.bottom-nav')</template>
        <template data-fragment="flash"><x-ui.flash /></template>
        <div data-fragment="content">{{ $slot }}</div>
    </div>
@else
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $documentTitle }}</title>

    {{-- SVG tetap tajam di layar rapat; favicon.ico dibiarkan sebagai cadangan
         untuk peramban lama yang belum mengenalnya. --}}
    <link rel="icon" href="{{ asset('logo.svg') }}" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased">

{{-- Indikator loading: garis tipis di paling atas layar --}}
<div id="page-progress" aria-hidden="true"></div>

<div x-data="{ sidebarOpen: false }" @page-navigated.window="sidebarOpen = false" class="min-h-full bg-ink-50/40">

    {{-- Mobile sidebar --}}
    <div x-show="sidebarOpen" x-cloak class="relative z-50 lg:hidden" role="dialog" aria-modal="true">
        <div x-show="sidebarOpen"
             x-transition:enter="transition-opacity ease-linear duration-200" x-transition:enter-start="opacity-0"
             x-transition:leave="transition-opacity ease-linear duration-200" x-transition:leave-end="opacity-0"
             @click="sidebarOpen = false" class="fixed inset-0 bg-ink-950/40 backdrop-blur-sm"></div>

        <div x-show="sidebarOpen"
             x-transition:enter="transition ease-in-out duration-300 transform" x-transition:enter-start="-translate-x-full"
             x-transition:leave="transition ease-in-out duration-300 transform" x-transition:leave-end="-translate-x-full"
             @keydown.escape.window="sidebarOpen = false"
             class="fixed inset-y-0 left-0 flex w-72 max-w-[85%] flex-col border-r border-ink-100 bg-white shadow-lift">
            <button type="button" @click="sidebarOpen = false"
                    class="absolute -right-3 top-4 z-10 inline-flex h-8 w-8 items-center justify-center rounded-full bg-ink-950 text-white shadow-lift">
                <x-icon name="close" class="h-4 w-4" />
                <span class="sr-only">Tutup menu</span>
            </button>
            @include('layouts.sidebar')
        </div>
    </div>

    {{-- Desktop sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 hidden w-72 border-r border-ink-100 bg-white lg:block">
        @include('layouts.sidebar')
    </aside>

    <div class="lg:pl-72">
        {{-- Topbar --}}
        <header class="sticky top-0 z-30 border-b border-ink-100 bg-white/80 backdrop-blur-md">
            <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
                <button type="button" @click="sidebarOpen = true"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-500 transition hover:bg-ink-100 hover:text-ink-950 lg:hidden">
                    <x-icon name="menu" class="h-5 w-5" />
                    <span class="sr-only">Buka menu</span>
                </button>

                <div class="min-w-0 flex-1">
                    <p id="page-heading" class="truncate text-sm font-semibold tracking-tight text-ink-950">{{ $pageTitle }}</p>
                </div>

                <div class="flex items-center gap-1.5 sm:gap-2">
                    <span class="hidden items-center gap-2 rounded-full bg-ink-50 px-3 py-1.5 text-xs font-medium text-ink-500 ring-1 ring-inset ring-ink-100 sm:inline-flex">
                        <x-icon name="clock" class="h-3.5 w-3.5" />
                        {{ now()->translatedFormat('d M Y') }}
                    </span>

                    <button type="button"
                            class="relative inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-500 transition hover:bg-ink-100 hover:text-ink-950">
                        <x-icon name="bell" class="h-5 w-5" />
                        <span class="absolute right-2 top-2 h-1.5 w-1.5 rounded-full bg-ink-950 ring-2 ring-white"></span>
                        <span class="sr-only">Notifikasi</span>
                    </button>

                    <x-dropdown align="right" width="56">
                        <x-slot name="trigger">
                            <button class="flex items-center gap-2 rounded-xl p-1 pr-2 transition hover:bg-ink-100">
                                <x-ui.avatar :name="auth()->user()->name" size="sm" />
                                <span class="hidden text-left sm:block">
                                    <span class="block max-w-[10rem] truncate text-xs font-semibold text-ink-950">{{ auth()->user()->name }}</span>
                                    <span class="block max-w-[10rem] truncate text-[11px] text-ink-400">{{ auth()->user()->role?->name ?? 'Tanpa role' }}</span>
                                </span>
                                <x-icon name="chevron-down" class="h-4 w-4 text-ink-400" />
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <div class="border-b border-ink-100 px-3 py-2.5">
                                <p class="truncate text-sm font-medium text-ink-950">{{ auth()->user()->name }}</p>
                                <p class="truncate text-xs text-ink-400">{{ auth()->user()->email }}</p>
                            </div>

                            <x-dropdown-link :href="route('profile.edit')" icon="user">Profil Saya</x-dropdown-link>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-dropdown-link :href="route('logout')" icon="logout"
                                                 onclick="event.preventDefault(); this.closest('form').requestSubmit();">
                                    Keluar
                                </x-dropdown-link>
                            </form>
                        </x-slot>
                    </x-dropdown>
                </div>
            </div>
        </header>

        {{-- Page. Ruang bawah disisakan untuk bilah navigasi ponsel. --}}
        <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div id="page-content" class="mx-auto max-w-7xl">
                {{ $slot }}
            </div>
        </main>

        <footer class="px-4 pb-28 sm:px-6 lg:px-8 lg:pb-8">
            <div class="mx-auto max-w-7xl border-t border-ink-100 pt-5 text-xs text-ink-400">
                &copy; {{ date('Y') }} {{ config('app.name', 'WMS Otosby') }}. Warehouse Management System.
            </div>
        </footer>
    </div>

    @include('layouts.bottom-nav')

    <div id="flash-slot"><x-ui.flash /></div>
</div>
</body>
</html>
@endif
