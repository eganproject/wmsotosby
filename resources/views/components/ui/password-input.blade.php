@props(['name' => 'password', 'id' => null, 'placeholder' => '••••••••'])

<div x-data="{ show: false }" class="relative">
    <input :type="show ? 'text' : 'password'"
           type="password"
           name="{{ $name }}"
           id="{{ $id ?? $name }}"
           placeholder="{{ $placeholder }}"
           {{ $attributes->merge([
               'class' => 'block w-full rounded-xl border-ink-200 bg-white pr-11 text-sm text-ink-950 placeholder:text-ink-300 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950',
           ]) }}>

    <button type="button" @click="show = !show" tabindex="-1"
            class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-ink-400 transition hover:text-ink-950">
        <x-icon name="eye" class="h-4 w-4" x-show="!show" />
        <x-icon name="eye-off" class="h-4 w-4" x-show="show" x-cloak />
        <span class="sr-only">Tampilkan kata sandi</span>
    </button>
</div>
