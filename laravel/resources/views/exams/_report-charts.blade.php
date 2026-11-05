{{-- A plain reading of the report, under the card. The PDF does not include this. --}}
@php
    $charts = $charts ?? null;
    $verdict = $charts['verdict'] ?? null;
    $chartTerms = $charts['terms'] ?? [];
    $cumulative = (bool) ($charts['cumulative'] ?? false);
@endphp

@if ($verdict)
    <section class="card mt-5" aria-label="How this student is doing">
        <p class="text-sm text-gray-500">{{ $cumulative ? 'Across this session' : 'This term' }}</p>
        <h3 class="mt-1 text-xl font-semibold">{{ $verdict['sentence'] }}</h3>

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div>
                <div class="text-sm text-gray-500">{{ $cumulative ? 'Cumulative percentage' : 'Average' }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $cumulative ? number_format((float) $verdict['average'], 2, '.', '') : $verdict['average'] }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Place in class</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $verdict['classSize'] > 1 ? $verdict['place'].' of '.$verdict['classSize'] : '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Class average</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $verdict['classAverage'] === null ? '—' : ($cumulative ? number_format((float) $verdict['classAverage'], 2, '.', '') : $verdict['classAverage']) }}</div>
            </div>
        </div>

        @if ($verdict['classSize'] > 1)
            <div class="mt-5">
                <div class="flex items-center justify-between text-xs text-gray-500">
                    <span>Bottom of the class</span>
                    <span>Top of the class</span>
                </div>
                <div class="relative mt-3 h-3 rounded-full bg-gray-200">
                    <div class="absolute inset-y-0 left-0 rounded-full" style="width: {{ $verdict['marker'] }}%; background: var(--primary)"></div>
                    <div class="absolute top-1/2 h-5 w-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow" style="left: {{ $verdict['marker'] }}%; background: var(--primary)" title="{{ $verdict['place'] }} of {{ $verdict['classSize'] }}"></div>
                </div>
            </div>
        @endif

        @if (count($chartTerms) >= 2)
            <p class="mt-4 text-sm text-gray-600">
                Through the session:
                @foreach ($chartTerms as $point)
                    <span class="ml-2"><span class="font-medium text-gray-800">{{ $point['label'] }}</span> {{ $point['student'] ?? '—' }}</span>
                @endforeach
            </p>
        @endif

        <div class="mt-5 grid gap-5 border-t border-black/5 pt-4 sm:grid-cols-2">
            <div>
                <h4 class="text-sm font-semibold">Doing well</h4>
                <p class="mt-0.5 text-xs text-gray-500">The strongest subjects, at 70 or above.</p>
                @if ($verdict['strong'] === [])
                    <p class="mt-3 text-sm text-gray-500">No subject is at 70 or above.</p>
                @else
                    <ul class="mt-3 space-y-2">
                        @foreach ($verdict['strong'] as $row)
                            <li class="flex items-baseline justify-between gap-3 rounded-md bg-gray-50 px-3 py-2">
                                <span>
                                    <span class="text-sm font-medium">{{ $row['subject'] }}</span>
                                    <span class="mt-0.5 block text-xs text-gray-500">{{ $row['note'] }}</span>
                                </span>
                                <span class="text-lg font-semibold tabular-nums">{{ $row['score'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <h4 class="text-sm font-semibold">Needs attention</h4>
                <p class="mt-0.5 text-xs text-gray-500">Up to four subjects that are low, or behind the class.</p>
                @if ($verdict['weak'] === [])
                    <p class="mt-3 text-sm text-gray-500">No subject is sitting low.</p>
                @else
                    <ul class="mt-3 space-y-2">
                        @foreach ($verdict['weak'] as $row)
                            <li class="flex items-baseline justify-between gap-3 rounded-md bg-amber-50 px-3 py-2">
                                <span>
                                    <span class="text-sm font-medium">{{ $row['subject'] }}</span>
                                    <span class="mt-0.5 block text-xs text-gray-500">{{ $row['note'] }}</span>
                                </span>
                                <span class="text-lg font-semibold tabular-nums">{{ $row['score'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </section>
@endif
