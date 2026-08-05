@php
    $supplier = $supplier ?? null;
    $isEdit = (bool) $supplier;
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.suppliers.update', $supplier) : route('admin.suppliers.store') }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Identitas Pemasok">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.field label="Kode" hint="Dibuat otomatis oleh sistem.">
                        <x-text-input type="text" :value="$code" class="font-mono" disabled />
                    </x-ui.field>

                    <x-ui.field label="Nama Pemasok" for="name" :error="$errors->get('name')" required>
                        <x-text-input id="name" name="name" type="text" :value="old('name', $supplier?->name)"
                                      required autofocus placeholder="PT Sumber Otoparts" />
                    </x-ui.field>

                    <x-ui.field label="Nama Kontak" for="contact_name" :error="$errors->get('contact_name')">
                        <x-text-input id="contact_name" name="contact_name" type="text"
                                      :value="old('contact_name', $supplier?->contact_name)" placeholder="Nama sales / PIC" />
                    </x-ui.field>

                    <x-ui.field label="Nomor Telepon" for="phone" :error="$errors->get('phone')">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                                <x-icon name="phone" class="h-4 w-4" />
                            </span>
                            <x-text-input id="phone" name="phone" type="text" :value="old('phone', $supplier?->phone)"
                                          class="pl-10" placeholder="08xxxxxxxxxx" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Email" for="email" :error="$errors->get('email')">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                                <x-icon name="mail" class="h-4 w-4" />
                            </span>
                            <x-text-input id="email" name="email" type="email" :value="old('email', $supplier?->email)"
                                          class="pl-10" placeholder="sales@pemasok.com" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Alamat" for="address" :error="$errors->get('address')" class="sm:col-span-2">
                        <textarea id="address" name="address" rows="2" placeholder="Alamat lengkap pemasok"
                                  class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 placeholder:text-ink-300 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">{{ old('address', $supplier?->address) }}</textarea>
                    </x-ui.field>

                    <x-ui.field label="Catatan" for="note" :error="$errors->get('note')" class="sm:col-span-2">
                        <x-text-input id="note" name="note" type="text" :value="old('note', $supplier?->note)"
                                      placeholder="Termin pembayaran, jadwal kirim, dsb." />
                    </x-ui.field>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Status">
                <div class="rounded-xl border border-ink-100 bg-ink-50/50 p-4">
                    <x-ui.toggle name="is_active" :checked="(bool) old('is_active', $supplier?->is_active ?? true)"
                                 label="Pemasok aktif"
                                 description="Pemasok nonaktif tidak muncul di dropdown dokumen baru." />
                </div>
            </x-ui.card>

            <div class="flex flex-col gap-2 sm:flex-row">
                <x-ui.button type="submit" icon="check" class="flex-1">
                    {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Pemasok' }}
                </x-ui.button>
                <x-ui.button :href="route('admin.suppliers.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
            </div>
        </div>
    </div>
</form>
