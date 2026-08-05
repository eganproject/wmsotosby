@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex items-center justify-between gap-4">
        {{-- Mobile --}}
        <div class="flex flex-1 justify-between sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="inline-flex h-9 cursor-default items-center rounded-xl bg-white px-3 text-xs font-medium text-ink-300 ring-1 ring-inset ring-ink-100">
                    Sebelumnya
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}"
                   class="inline-flex h-9 items-center rounded-xl bg-white px-3 text-xs font-medium text-ink-700 ring-1 ring-inset ring-ink-200 transition hover:bg-ink-50">
                    Sebelumnya
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}"
                   class="inline-flex h-9 items-center rounded-xl bg-white px-3 text-xs font-medium text-ink-700 ring-1 ring-inset ring-ink-200 transition hover:bg-ink-50">
                    Selanjutnya
                </a>
            @else
                <span class="inline-flex h-9 cursor-default items-center rounded-xl bg-white px-3 text-xs font-medium text-ink-300 ring-1 ring-inset ring-ink-100">
                    Selanjutnya
                </span>
            @endif
        </div>

        {{-- Desktop --}}
        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <p class="text-xs text-ink-500">
                Menampilkan <span class="font-medium text-ink-950">{{ $paginator->firstItem() }}</span>
                – <span class="font-medium text-ink-950">{{ $paginator->lastItem() }}</span>
                dari <span class="font-medium text-ink-950">{{ $paginator->total() }}</span> data
            </p>

            <div class="flex items-center gap-1">
                @if ($paginator->onFirstPage())
                    <span class="inline-flex h-9 w-9 cursor-default items-center justify-center rounded-lg text-ink-200">
                        <x-icon name="chevron-left" class="h-4 w-4" />
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Sebelumnya"
                       class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-500 transition hover:bg-ink-100 hover:text-ink-950">
                        <x-icon name="chevron-left" class="h-4 w-4" />
                    </a>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="inline-flex h-9 w-9 cursor-default items-center justify-center text-xs text-ink-400">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page"
                                      class="inline-flex h-9 min-w-9 items-center justify-center rounded-lg bg-ink-950 px-2 text-xs font-semibold text-white">
                                    {{ $page }}
                                </span>
                            @else
                                <a href="{{ $url }}"
                                   class="inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-xs font-medium text-ink-600 transition hover:bg-ink-100 hover:text-ink-950">
                                    {{ $page }}
                                </a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Selanjutnya"
                       class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-500 transition hover:bg-ink-100 hover:text-ink-950">
                        <x-icon name="chevron-right" class="h-4 w-4" />
                    </a>
                @else
                    <span class="inline-flex h-9 w-9 cursor-default items-center justify-center rounded-lg text-ink-200">
                        <x-icon name="chevron-right" class="h-4 w-4" />
                    </span>
                @endif
            </div>
        </div>
    </nav>
@endif
