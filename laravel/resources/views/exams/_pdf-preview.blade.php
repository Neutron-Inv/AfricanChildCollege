{{-- Opens the PDF in a modal, then offers the download. Needs: $previewUrl, $downloadUrl, $title --}}
<div x-data="{ open: false, src: '' }" @keydown.escape.window="open = false">
    <button type="button" class="btn-secondary inline-flex items-center gap-2 !py-1.5" @click="src = @js($previewUrl); open = true">
        Preview
    </button>

    <div
        x-show="open"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3 sm:p-6"
        @click.self="open = false"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $title }}"
    >
        <div class="flex max-h-[96vh] flex-col items-center">
            <div class="mb-2 flex w-full items-center justify-between gap-3" style="width: min(90vw, calc(86vh * 210 / 297));">
                <h3 class="truncate text-sm font-semibold text-white">{{ $title }}</h3>
                <div class="flex shrink-0 items-center gap-2">
                    <a href="{{ $downloadUrl }}" class="btn-primary inline-flex items-center gap-2 !py-1.5">
                        <span aria-hidden="true">⬇</span> Download PDF
                    </a>
                    <button type="button" class="btn-secondary !py-1.5" @click="open = false">Close</button>
                </div>
            </div>
            {{-- A4 portrait: 210 × 297. The sheet scrolls inside the page. --}}
            <div class="overflow-hidden bg-white shadow-2xl" style="height: min(86vh, calc(90vw * 297 / 210)); width: min(90vw, calc(86vh * 210 / 297));">
                <iframe class="h-full w-full border-0 bg-white" :src="src" title="{{ $title }}"></iframe>
            </div>
        </div>
    </div>
</div>
