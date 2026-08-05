@php
    // $document, $prefix (inbounds|outbounds|returns)
    $canPost = auth()->user()->can($prefix.'.post');
    $canApprove = auth()->user()->can($prefix.'.approve');
@endphp

{{--
    Dokumen yang sudah disetujui bersifat final: tidak ada tombol pembatalan.
    Selama masih diajukan, pengajuannya masih bisa ditarik kembali.
--}}
@if ($document->isPending())
    @if ($canApprove)
        <form method="POST" action="{{ route('admin.'.$prefix.'.approve', $document) }}">
            @csrf
            <x-ui.button type="submit" icon="check">Setujui</x-ui.button>
        </form>
    @endif

    @if ($canPost)
        <form method="POST" action="{{ route('admin.'.$prefix.'.withdraw', $document) }}">
            @csrf
            <x-ui.button type="submit" variant="secondary" icon="refresh">Tarik Kembali</x-ui.button>
        </form>
    @endif
@endif
