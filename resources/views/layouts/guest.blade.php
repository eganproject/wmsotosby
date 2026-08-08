<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' — '.config('app.name', 'WMS') : config('app.name', 'WMS') }}</title>

    {{-- SVG tetap tajam di layar rapat; favicon.ico dibiarkan sebagai cadangan
         untuk peramban lama yang belum mengenalnya. --}}
    <link rel="icon" href="{{ asset('logo.svg') }}" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased">
<div class="flex min-h-full flex-col lg:flex-row">

    {{-- Brand panel --}}
    <div class="relative hidden overflow-hidden bg-ink-950 lg:flex lg:w-[46%] lg:flex-col lg:justify-between lg:p-12 xl:p-16">
        {{-- Decorative grid + glow --}}
        <div class="pointer-events-none absolute inset-0 opacity-[0.07]"
             style="background-image:linear-gradient(to right,#fff 1px,transparent 1px),linear-gradient(to bottom,#fff 1px,transparent 1px);background-size:56px 56px;"></div>
        <div class="pointer-events-none absolute -left-24 top-1/3 h-96 w-96 rounded-full bg-white/5 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -right-20 h-96 w-96 rounded-full bg-white/5 blur-3xl"></div>

        <div class="relative flex items-center gap-3">
            <span class="inline-flex h-11 w-14 shrink-0 items-center justify-center rounded-2xl bg-white">
                <x-application-logo class="h-5 w-11" />
            </span>
            <div>
                <p class="text-base font-semibold tracking-tight text-white">{{ config('app.name', 'WMS Otosby') }}</p>
                <p class="text-xs text-white/50">Warehouse Management System</p>
            </div>
        </div>

        <div class="relative max-w-lg">
            <h1 class="text-4xl font-semibold leading-tight tracking-tight text-white xl:text-[2.75rem]">
                Kelola gudang Anda<br>dengan lebih rapi.
            </h1>
            <p class="mt-5 text-base leading-relaxed text-white/60">
                Pantau stok, arus barang masuk dan keluar, serta hak akses tim dalam satu panel yang sederhana dan cepat.
            </p>

            <ul class="mt-10 space-y-4">
                @foreach ([
                    ['icon' => 'box', 'title' => 'Data barang terpusat', 'desc' => 'Satu sumber kebenaran untuk seluruh stok gudang.'],
                    ['icon' => 'shield', 'title' => 'Hak akses berbasis role', 'desc' => 'Tentukan siapa boleh melihat dan mengubah apa.'],
                    ['icon' => 'trending-up', 'title' => 'Laporan siap pakai', 'desc' => 'Ringkasan pergerakan stok setiap saat.'],
                ] as $feature)
                    <li class="flex items-start gap-4">
                        <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white ring-1 ring-inset ring-white/10">
                            <x-icon :name="$feature['icon']" class="h-5 w-5" />
                        </span>
                        <div>
                            <p class="text-sm font-medium text-white">{{ $feature['title'] }}</p>
                            <p class="text-sm text-white/50">{{ $feature['desc'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <p class="relative text-xs text-white/40">
            &copy; {{ date('Y') }} {{ config('app.name', 'WMS Otosby') }}. All rights reserved.
        </p>
    </div>

    {{-- Form panel --}}
    <div class="flex flex-1 flex-col justify-center bg-white px-5 py-10 sm:px-8 lg:px-12 xl:px-20">
        <div class="mx-auto w-full max-w-md animate-fade-in">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <span class="inline-flex h-10 w-14 shrink-0 items-center justify-center rounded-xl bg-white ring-1 ring-inset ring-ink-200">
                    <x-application-logo class="h-4 w-10" />
                </span>
                <div>
                    <p class="text-sm font-semibold tracking-tight text-ink-950">{{ config('app.name', 'WMS Otosby') }}</p>
                    <p class="text-[11px] text-ink-400">Warehouse Management System</p>
                </div>
            </div>

            @if ($heading)
                <div class="mb-8">
                    <h2 class="text-2xl font-semibold tracking-tight text-ink-950 sm:text-3xl">{{ $heading }}</h2>
                    @if ($subheading)
                        <p class="mt-2 text-sm text-ink-500">{{ $subheading }}</p>
                    @endif
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>
</div>
</body>
</html>
