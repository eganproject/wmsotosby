@php
    // $document
@endphp

<div class="rounded-xl p-4 ring-1 ring-inset
    @class([
        'bg-emerald-50 ring-emerald-200' => $document->isPosted(),
        'bg-amber-50 ring-amber-200' => $document->isPending(),
        'bg-red-50 ring-red-200' => $document->isRejected(),
        'bg-ink-50 ring-ink-100' => $document->isDraft(),
    ])">
    <div class="flex items-center gap-2">
        <x-icon :name="$document->statusIcon()" @class([
            'h-5 w-5',
            'text-emerald-600' => $document->isPosted(),
            'text-amber-600' => $document->isPending(),
            'text-red-600' => $document->isRejected(),
            'text-ink-400' => $document->isDraft(),
        ]) />
        <p @class([
            'text-sm font-semibold',
            'text-emerald-700' => $document->isPosted(),
            'text-amber-800' => $document->isPending(),
            'text-red-700' => $document->isRejected(),
            'text-ink-950' => $document->isDraft(),
        ])>{{ $document->statusLabel() }}</p>
    </div>

    <p class="mt-1.5 text-xs text-ink-600">
        @if ($document->isPosted())
            Disetujui {{ $document->approved_at?->translatedFormat('d M Y H:i') }}
            @if ($document->approver) oleh {{ $document->approver->name }} @endif
            &middot; stok sudah diperbarui.
        @elseif ($document->isPending())
            Diajukan {{ $document->submitted_at?->diffForHumans() }}
            @if ($document->submitter) oleh {{ $document->submitter->name }} @endif.
            Stok belum berubah sampai disetujui.
        @elseif ($document->isRejected())
            Ditolak {{ $document->rejected_at?->translatedFormat('d M Y H:i') }}
            @if ($document->rejecter) oleh {{ $document->rejecter->name }} @endif.
        @else
            Belum diajukan. Stok belum berubah.
        @endif
    </p>

    {{-- Dokumen yang sudah disetujui tidak bisa ditarik kembali; koreksi
         angkanya lewat dokumen penyesuaian stok agar jejaknya tetap utuh. --}}
    @if ($document->isPosted())
        <p class="mt-2 rounded-lg bg-white/70 px-3 py-2 text-xs text-emerald-800 ring-1 ring-inset ring-emerald-200">
            Dokumen ini final dan tidak bisa diubah lagi.
            @can('adjustments.create')
                Bila angkanya keliru, buat
                <a href="{{ route('admin.adjustments.create') }}" class="font-semibold underline underline-offset-2">penyesuaian stok</a>.
            @else
                Bila angkanya keliru, ajukan penyesuaian stok ke petugas berwenang.
            @endcan
        </p>
    @endif

    @if ($document->isRejected() && $document->rejection_reason)
        <p class="mt-2 rounded-lg bg-white/70 px-3 py-2 text-xs text-red-800 ring-1 ring-inset ring-red-200">
            <span class="font-semibold">Alasan:</span> {{ $document->rejection_reason }}
        </p>
    @endif
</div>
