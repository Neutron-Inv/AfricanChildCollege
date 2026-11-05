{{-- Class + term matrix of totals. Needs: $term, $class, $sheet, $sort, $dir --}}
@php
    $fmt = fn ($v) => $v === null || $v === '' ? '-' : (string) (int) round((float) $v);
    $sort = $sort ?? ($sheet['sort'] ?? 'position');
    $dir = $dir ?? ($sheet['dir'] ?? 'asc');
    $toggle = function (string $column) use ($sort, $dir, $term, $class) {
        $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';

        return route('exams', array_filter([
            'view' => 'broadsheet',
            'term' => $term?->id,
            'class' => $class?->id,
            'sort' => $column,
            'dir' => $nextDir,
        ]));
    };
    $arrow = fn (string $column) => $sort === $column ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $pdfQuery = $class ? array_filter([
        'term' => $term?->id,
        'class' => $class->id,
        'sort' => $sort,
        'dir' => $dir,
    ]) : null;
    $pdfUrl = $pdfQuery ? route('exams.broadsheet.pdf', $pdfQuery) : null;
    $previewUrl = $pdfQuery ? route('exams.broadsheet.pdf', $pdfQuery + ['preview' => 'html']) : null;
@endphp
@if (! $class)
    <div class="card text-sm text-gray-500">You have no classes in this term's session.</div>
@else
    <div class="card">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold">
                    {{ $className($class) }}
                    <span class="text-sm font-normal text-gray-500">- {{ $term->name }} ({{ $term->session?->name }}) - totals out of {{ \App\Services\ExamsService::MAX_TOTAL }}</span>
                </h3>
                @if (! empty($sheet['limited']))
                    <p class="mt-1 text-xs text-gray-500">Showing only subjects you teach. Form teachers see the full class broadsheet.</p>
                @endif
            </div>
            @if ($pdfUrl && $sheet && $sheet['subjects']->isNotEmpty() && $sheet['rows']->isNotEmpty())
                @include('exams._pdf-preview', [
                    'title' => 'Broadsheet · '.$className($class),
                    'previewUrl' => $previewUrl,
                    'downloadUrl' => $pdfUrl,
                ])
            @endif
        </div>

        @if (! $sheet || $sheet['subjects']->isEmpty())
            <p class="text-sm text-gray-500">You are not assigned to any subject in this class.</p>
        @elseif ($sheet['rows']->isEmpty())
            <p class="text-sm text-gray-500">No students in this class for this term.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="py-2 pr-3 font-medium">
                                <a href="{{ $toggle('name') }}" class="inline-flex items-center hover:text-gray-900" title="Sort by name">
                                    Student{{ $arrow('name') }}
                                </a>
                            </th>
                            @foreach ($sheet['subjects'] as $cs)
                                <th class="px-2 py-2 text-right font-medium" title="{{ $cs->subject?->name }}">{{ \Illuminate\Support\Str::limit($cs->subject?->name, 10, '.') }}</th>
                            @endforeach
                            <th class="px-2 py-2 text-right font-medium">Total</th>
                            <th class="px-2 py-2 text-right font-medium">Avg</th>
                            <th class="px-2 py-2 text-right font-medium">
                                <a href="{{ $toggle('position') }}" class="inline-flex items-center justify-end hover:text-gray-900" title="Sort by position">
                                    Pos.{{ $arrow('position') }}
                                </a>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black/5">
                        @foreach ($sheet['rows'] as $row)
                            <tr>
                                <td class="whitespace-nowrap py-2 pr-3 font-medium">
                                    @if (($row['position'] ?? null) === 1)
                                        <span class="mr-1 inline-block text-amber-500" title="Highest total" aria-label="Highest total">★</span>
                                    @endif
                                    @if ($views['report'] ?? null)
                                        <a href="{{ route('exams', ['view' => 'report', 'term' => $term->id, 'class' => $class->id, 'enrolment' => $row['enrolment']->id]) }}" class="hover:underline" title="Open report card">{{ $row['student']?->full_name }}</a>
                                    @else
                                        {{ $row['student']?->full_name }}
                                    @endif
                                </td>
                                @foreach ($sheet['subjects'] as $cs)
                                    <td class="px-2 py-2 text-right tabular-nums">{{ $fmt($row['cells'][$cs->id] ?? null) }}</td>
                                @endforeach
                                <td class="px-2 py-2 text-right font-semibold tabular-nums">{{ $row['count'] ? $fmt($row['sum']) : '-' }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $fmt($row['average']) }}</td>
                                <td class="px-2 py-2 text-right font-semibold">{{ $row['position'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                Click <strong>Student</strong> or <strong>Pos.</strong> to sort. Preview opens the sheet so you can check it before downloading. The file keeps the current sort and school branding.
                @if ($views['report'] ?? null)
                    Select a student's name to open their report card.
                @endif
            </p>
        @endif
    </div>
@endif
