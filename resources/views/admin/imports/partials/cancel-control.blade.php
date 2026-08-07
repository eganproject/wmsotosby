{{--
    Tandai resi batal, atau cabut kembali.

    Saringan yang sedang aktif ikut terbawa sebagai kolom tersembunyi: tanpa
    itu kepulangannya bergantung pada header Referer, yang tidak selalu ada dan
    cadangan sesinya tidak pernah diperbarui pada navigasi AJAX — operator yang
    sedang menyaring satu ekspedisi akan terlempar ke seluruh daftar.
--}}
@php
    $filters = array_filter(request()->only(['stage', 'search', 'courier']), fn ($value) => filled($value));
@endphp

@can('imports.cancel')
    @if ($order->isCancelled())
        <form method="POST" action="{{ route('admin.imports.orders.restore', $order) }}" class="inline">
            @csrf
            @method('DELETE')
            @foreach ($filters as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach

            <button type="submit"
                    class="inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-[11px] font-medium text-ink-500 transition hover:bg-ink-100 hover:text-ink-950">
                <x-icon name="refresh" class="h-3.5 w-3.5" />
                Cabut pembatalan
            </button>
        </form>
    @else
        <div x-data="{ marking: false }" class="inline-block text-left">
            <button type="button" x-show="! marking" x-on:click="marking = true"
                    class="inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-[11px] font-medium text-red-600 transition hover:bg-red-50">
                <x-icon name="x-circle" class="h-3.5 w-3.5" />
                Tandai batal
            </button>

            <form method="POST" action="{{ route('admin.imports.orders.cancel', $order) }}"
                  x-show="marking" x-cloak class="mt-1 w-56 space-y-1.5">
                @csrf
                @foreach ($filters as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach

                <input type="text" name="cancellation_reason" required maxlength="255"
                       placeholder="Alasan pembatalan…"
                       class="block w-full rounded-lg border-ink-200 bg-white text-[11px] text-ink-950 placeholder:text-ink-300 shadow-soft transition focus:border-red-500 focus:ring-1 focus:ring-red-500">

                <div class="flex gap-1.5">
                    <x-ui.button type="submit" variant="danger" size="sm" class="flex-1">Simpan</x-ui.button>
                    <x-ui.button type="button" variant="secondary" size="sm" class="flex-1"
                                 x-on:click="marking = false">Batal</x-ui.button>
                </div>
            </form>
        </div>
    @endif
@endcan
