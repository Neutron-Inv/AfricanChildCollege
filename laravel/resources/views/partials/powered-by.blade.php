@if (filled(config('app.powered_by')))
    @php
        $credit = config('app.powered_by');
        $email = config('app.powered_by_email');
        $phone = config('app.powered_by_phone');
        $website = trim((string) config('app.powered_by_website'));
        $websiteUrl = $website === '' ? null : (preg_match('#^https?://#i', $website) ? $website : 'https://'.$website);
        $websiteLabel = $websiteUrl ? preg_replace('#^https?://#i', '', rtrim($websiteUrl, '/')) : null;
        $phoneHref = filled($phone) ? 'tel:'.preg_replace('/[^\d+]/', '', $phone) : null;
    @endphp
    <footer class="px-4 py-4 text-center text-xs text-gray-400" x-data="{ open: false }" @keydown.escape.window="open = false">
        <button type="button" class="rounded-sm underline decoration-gray-300 underline-offset-4 transition hover:text-gray-700" @click="open = true">
            Powered by <span class="font-medium text-gray-500">{{ $credit }}</span>
        </button>

        <div x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="{{ $credit }}" @click.self="open = false">
            <div class="absolute inset-0 bg-slate-950/70 backdrop-blur-sm"></div>
            <div class="relative w-full max-w-sm overflow-hidden rounded-2xl text-left shadow-2xl ring-1 ring-white/10" style="background: radial-gradient(120% 80% at 0% 0%, rgba(34,211,238,.18), transparent 42%), linear-gradient(180deg, #101828, #0b1020);" @click.stop>
                <div class="pointer-events-none absolute inset-0 opacity-50" style="background-image: linear-gradient(rgba(255,255,255,.06) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.06) 1px, transparent 1px); background-size: 18px 18px; mask-image: linear-gradient(180deg, #000, transparent 80%);"></div>
                <div class="absolute inset-x-0 top-0 h-px" style="background: linear-gradient(90deg, transparent, #67e8f9, var(--primary), transparent)"></div>

                <div class="relative p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-cyan-200/80">Powered by</p>
                            <h2 class="mt-1 text-lg font-semibold tracking-tight text-white">{{ $credit }}</h2>
                        </div>
                        <button type="button" class="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-sm text-white/70 transition hover:bg-white/10 hover:text-white" @click="open = false" aria-label="Close">&times;</button>
                    </div>

                    <div class="mt-4 space-y-2">
                        @if (filled($email))
                            <a href="mailto:{{ $email }}" class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white/90 transition hover:border-cyan-300/40 hover:bg-white/10">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-cyan-300/15 text-cyan-200">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                </span>
                                <span class="min-w-0 text-left">
                                    <span class="block text-[10px] uppercase tracking-[0.16em] text-white/45">Email</span>
                                    <span class="block truncate">{{ $email }}</span>
                                </span>
                            </a>
                        @endif
                        @if ($phoneHref)
                            <a href="{{ $phoneHref }}" class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white/90 transition hover:border-cyan-300/40 hover:bg-white/10">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-cyan-300/15 text-cyan-200">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                </span>
                                <span class="min-w-0 text-left">
                                    <span class="block text-[10px] uppercase tracking-[0.16em] text-white/45">Phone</span>
                                    <span class="block truncate">{{ $phone }}</span>
                                </span>
                            </a>
                        @endif
                        @if ($websiteUrl)
                            <a href="{{ $websiteUrl }}" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white/90 transition hover:border-cyan-300/40 hover:bg-white/10">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-cyan-300/15 text-cyan-200">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                                </span>
                                <span class="min-w-0 text-left">
                                    <span class="block text-[10px] uppercase tracking-[0.16em] text-white/45">Website</span>
                                    <span class="block truncate">{{ $websiteLabel }}</span>
                                </span>
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </footer>
@endif
