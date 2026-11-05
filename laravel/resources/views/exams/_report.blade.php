{{-- Report card. Needs: $term, $enrolment, $card, $isOffice --}}
@php
    $fmt = fn ($v) => $v === null || $v === '' ? '-' : (string) (int) round((float) $v);
    $pct = fn ($v) => $v === null || $v === '' ? '-' : number_format((float) $v, 2, '.', '');
@endphp
@if (! ($enrolment ?? null))
    <div class="card text-sm text-gray-500">
        {{ ($ownOnly ?? false) ? 'No enrolment was found for you in this term\'s session.' : 'No students to show for this class and term.' }}
    </div>
@else
    @php $report = $card['report']; $open = (int) $term->days_school_open; $cumulative = (bool) ($card['cumulative'] ?? false); @endphp
    <div class="card">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
            <div>
                @include('exams._student-name', ['enrolment' => $enrolment, 'nameClass' => 'text-left text-lg font-semibold hover:underline'])
                <p class="text-sm text-gray-500">
                    {{ $enrolment->student?->index_number }} - {{ $className($card['class']) }} - {{ $term->name }} ({{ $term->session?->name }})
                </p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3 text-right text-sm">
                <div>
                    <div>{{ $cumulative ? 'Cumulative percentage' : 'Average' }}: <span class="font-semibold">{{ $cumulative ? $pct($card['average']) : $fmt($card['average']) }}</span></div>
                    <div>Position: <span class="font-semibold">{{ $card['position'] ? $card['position'].' of '.$card['classSize'] : '-' }}</span></div>
                </div>
                @include('exams._pdf-preview', [
                    'title' => 'Report card · '.$enrolment->student?->full_name,
                    'previewUrl' => route('exams.report.pdf', ['term' => $term->id, 'enrolment' => $enrolment->id, 'preview' => 'html']),
                    'downloadUrl' => route('exams.report.pdf', ['term' => $term->id, 'enrolment' => $enrolment->id]),
                ])
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="py-2 pr-3 font-medium">Subject</th>
                        <th class="px-2 py-2 text-right font-medium">CA1</th>
                        <th class="px-2 py-2 text-right font-medium">CA2</th>
                        <th class="px-2 py-2 text-right font-medium">CA3</th>
                        <th class="px-2 py-2 text-right font-medium">Exam</th>
                        <th class="px-2 py-2 text-right font-medium">{{ $cumulative ? '3rd' : 'Total' }}</th>
                        @if ($cumulative)
                            <th class="px-2 py-2 text-right font-medium">1st</th>
                            <th class="px-2 py-2 text-right font-medium">2nd</th>
                            <th class="px-2 py-2 text-right font-medium">Session</th>
                        @endif
                        <th class="px-2 py-2 text-center font-medium">Grade</th>
                        <th class="px-2 py-2 font-medium">Remark</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-black/5">
                    @forelse ($card['lines'] as $line)
                        @php $s = $line['score']; @endphp
                        <tr>
                            <td class="py-2 pr-3 font-medium">{{ $line['subject'] }}</td>
                            @if ($s?->absent)
                                <td colspan="4" class="px-2 py-2 text-center text-xs uppercase text-gray-500">Absent</td>
                            @else
                                <td class="px-2 py-2 text-right tabular-nums">{{ $fmt($s?->ca1) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $fmt($s?->ca2) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $fmt($s?->ca3) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $fmt($s?->exam_score) }}</td>
                            @endif
                            <td class="px-2 py-2 text-right font-semibold tabular-nums">{{ $fmt($line['total']) }}</td>
                            @if ($cumulative)
                                <td class="px-2 py-2 text-right tabular-nums">{{ $fmt($line['terms'][1] ?? null) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $fmt($line['terms'][2] ?? null) }}</td>
                                <td class="px-2 py-2 text-right font-semibold tabular-nums">{{ $fmt($line['aggregate'] ?? null) }}</td>
                            @endif
                            <td class="px-2 py-2 text-center font-semibold">{{ $line['grade']['letter'] ?? '-' }}</td>
                            <td class="px-2 py-2 text-gray-600">{{ $line['grade']['remark'] ?? '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $cumulative ? 11 : 8 }}" class="py-3 text-sm text-gray-500">No subjects for this class.</td></tr>
                    @endforelse
                </tbody>
                @if ($card['count'])
                    <tfoot class="border-t border-black/10 font-semibold">
                        <tr>
                            <td class="py-2 pr-3" colspan="5">{{ $cumulative ? 'Session total' : 'Total' }} ({{ $card['count'] }} {{ \Illuminate\Support\Str::plural('subject', $card['count']) }})</td>
                            @if ($cumulative)
                                <td class="px-2 py-2 text-right tabular-nums">{{ $fmt($card['termSum'] ?? null) }}</td>
                                <td colspan="2"></td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $fmt($card['sum']) }}</td>
                            @else
                                <td class="px-2 py-2 text-right tabular-nums">{{ $fmt($card['sum']) }}</td>
                            @endif
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        @if ($cumulative)
            <p class="mt-2 text-xs text-gray-500">1st and 2nd are this session's earlier totals. Cumulative percentage is the average of the Session column, to two decimal places. A term with no score, or where the student was absent, is left out. Position uses that cumulative percentage.</p>
        @endif

        @php $domains = \App\Enums\ReportTrait::grouped(); $canEdit = (bool) ($canEditReport ?? false); @endphp
        @if ($canEdit)
            <form method="POST" action="{{ route('exams.report.update') }}" class="mt-5 space-y-5 border-t border-black/5 pt-4">
                @csrf @method('PUT')
                <input type="hidden" name="term_id" value="{{ $term->id }}">
                <input type="hidden" name="enrolment_id" value="{{ $enrolment->id }}">
                <input type="hidden" name="view" value="report">
                <input type="hidden" name="class" value="{{ $card['class']?->id }}">
        @endif

        <div class="{{ $canEdit ? '' : 'mt-5 border-t border-black/5 pt-4' }}">
            <h4 class="mb-2 text-sm font-semibold">Attendance</h4>
            @if ($canEdit && $isOffice)
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="days_present" class="text-xs text-gray-500">Days present</label>
                        <input id="days_present" type="number" min="0" max="366" name="days_present" value="{{ old('days_present', $report?->days_present ?? 0) }}" class="form-input mt-1 !w-28">
                    </div>
                    <div>
                        <label for="days_absent" class="text-xs text-gray-500">Days absent</label>
                        <input id="days_absent" type="number" min="0" max="366" name="days_absent" value="{{ old('days_absent', $report?->days_absent ?? 0) }}" class="form-input mt-1 !w-28">
                    </div>
                    <div class="text-sm text-gray-500">School open: <span class="font-medium text-gray-800">{{ $open ?: '-' }}</span> days</div>
                </div>
            @else
                <dl class="flex flex-wrap gap-6 text-sm">
                    <div><dt class="text-xs text-gray-500">Days present</dt><dd class="font-semibold">{{ $report?->days_present ?? 0 }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Days absent</dt><dd class="font-semibold">{{ $report?->days_absent ?? 0 }}</dd></div>
                    <div><dt class="text-xs text-gray-500">School open</dt><dd class="font-semibold">{{ $open ?: '-' }}</dd></div>
                </dl>
            @endif
        </div>

        <div class="{{ $canEdit ? '' : 'mt-5 border-t border-black/5 pt-4' }}">
            <h4 class="text-sm font-semibold">Behaviour</h4>
            <p class="mt-1 text-xs text-gray-500">Each quality is rated from 1 to 5. A filled box is the score reached.</p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                @foreach ($domains as $domain => $traits)
                    <div class="rounded-lg border border-black/5 bg-gray-50 px-3 py-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-[color:var(--primary)]">{{ $domain }}</p>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($traits as $key => $label)
                                @php $rating = (int) ($report?->score($key) ?? 0); @endphp
                                <li class="flex items-center justify-between gap-3 text-sm">
                                    <span>{{ $label }}</span>
                                    @if ($canEdit)
                                        @php $current = (int) old('ratings.'.$key, $rating); @endphp
                                        <span class="flex items-center gap-1" x-data="{ score: {{ $current }} }">
                                            <input type="hidden" name="ratings[{{ $key }}]" value="{{ $current ?: '' }}" :value="score > 0 ? score : ''">
                                            @for ($mark = 1; $mark <= 5; $mark++)
                                                <button type="button" @click="score = score === {{ $mark }} ? 0 : {{ $mark }}"
                                                    :class="score >= {{ $mark }} ? 'bg-[color:var(--primary)] text-white' : 'bg-white text-gray-400 ring-1 ring-black/10'"
                                                    class="inline-flex h-6 w-6 items-center justify-center rounded text-[10px] font-semibold"
                                                    aria-label="{{ $label }} {{ $mark }}">{{ $mark }}</button>
                                            @endfor
                                        </span>
                                    @else
                                        <span class="flex gap-1" aria-label="{{ $label }} {{ $rating ?: 'not rated' }} of 5">
                                            @for ($mark = 1; $mark <= 5; $mark++)
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded text-[10px] font-semibold {{ $rating >= $mark ? 'bg-[color:var(--primary)] text-white' : 'bg-white text-gray-400 ring-1 ring-black/10' }}">{{ $mark }}</span>
                                            @endfor
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            @if ($canEdit)
                <p class="mt-2 text-xs text-gray-500">Click a number to set the score. Click that number again to clear it.</p>
            @endif
        </div>

        <div class="{{ $canEdit ? '' : 'mt-4' }} space-y-3 text-sm">
            @if ($canEdit)
                <div>
                    <label for="teacher_comment" class="text-sm font-semibold">Teacher's comment</label>
                    <textarea id="teacher_comment" name="teacher_comment" rows="3" maxlength="255" class="form-input mt-1 w-full">{{ old('teacher_comment', $report?->teacher_comment) }}</textarea>
                </div>
                @if ($isOffice)
                    <div>
                        <label for="principal_comment" class="text-sm font-semibold">Principal's comment</label>
                        <textarea id="principal_comment" name="principal_comment" rows="3" maxlength="255" class="form-input mt-1 w-full">{{ old('principal_comment', $report?->principal_comment) }}</textarea>
                    </div>
                @elseif ($report?->principal_comment)
                    <p><span class="font-semibold">Principal's comment:</span> {{ $report->principal_comment }}</p>
                @endif
                <button class="btn-secondary">Save</button>
            @elseif ($report && ($report->teacher_comment || $report->principal_comment))
                @if ($report->teacher_comment)<p><span class="font-semibold">Teacher's comment:</span> {{ $report->teacher_comment }}</p>@endif
                @if ($report->principal_comment)<p><span class="font-semibold">Principal's comment:</span> {{ $report->principal_comment }}</p>@endif
            @endif
        </div>

        @if ($canEdit)
            </form>
        @endif
    </div>

    @include('exams._report-charts')
@endif
