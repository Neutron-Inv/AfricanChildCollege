{{-- One student x every class subject (office). Needs: $term, $class, $subjects, $enrolment, $scores, $bands, $view --}}
@php
    $fmt = fn ($v) => $v === null ? '' : (string) (int) round((float) $v);
@endphp
<form method="POST" action="{{ route('exams.scores.store') }}" class="card space-y-4"
      x-data="{ bands: @js($bands->values()), grade(t) { if (t === null) return ''; t = Math.round(t); const b = this.bands.find(b => t >= b.min && t <= b.max); return b ? b.letter : ''; } }">
    @csrf
    <input type="hidden" name="term_id" value="{{ $term->id }}">
    <input type="hidden" name="view" value="{{ $view }}">
    <input type="hidden" name="class" value="{{ $class->id }}">
    <input type="hidden" name="student" value="{{ $enrolment->id }}">

    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-black/5 pb-4">
        <div>
            @include('exams._student-name', ['enrolment' => $enrolment, 'nameClass' => 'text-left text-xl font-semibold hover:underline'])
            <p class="mt-1 text-xs text-gray-500">
                {{ $enrolment->student?->index_number }} · {{ $className($class) }} · {{ $term->session?->name }} · {{ $term->name }}
            </p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('exams', ['view' => 'marksheet', 'term' => $term->id, 'class' => $class->id]) }}" class="text-sm font-medium hover:underline" style="color: var(--primary)">All students</a>
            <button class="btn-primary">Save scores</button>
        </div>
    </header>
    <p class="text-xs text-gray-500">Every subject for this student. Whole numbers only. Leave a subject blank to skip it.</p>

    @if ($subjects->isEmpty())
        <p class="text-sm text-gray-500">This class has no subjects yet.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Subject</th>
                        <th class="px-2 py-2 text-right font-medium">CA1 <span class="font-normal normal-case text-gray-400">/{{ \App\Services\ExamsService::MAX_CA }}</span></th>
                        <th class="px-2 py-2 text-right font-medium">CA2 <span class="font-normal normal-case text-gray-400">/{{ \App\Services\ExamsService::MAX_CA }}</span></th>
                        <th class="px-2 py-2 text-right font-medium">CA3 <span class="font-normal normal-case text-gray-400">/{{ \App\Services\ExamsService::MAX_CA }}</span></th>
                        <th class="px-2 py-2 text-right font-medium">Exam <span class="font-normal normal-case text-gray-400">/{{ \App\Services\ExamsService::MAX_EXAM }}</span></th>
                        <th class="px-2 py-2 text-center font-medium">Absent</th>
                        <th class="px-2 py-2 text-right font-medium">Total</th>
                        <th class="px-4 py-2 text-center font-medium">Grade</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($subjects as $cs)
                        @php
                            $s = $scores->get($cs->id);
                            $key = "scores.{$enrolment->id}.{$cs->id}";
                            $v = fn ($f, $col) => old("$key.$f", $fmt($s?->{$col}));
                            $total = $s ? app(\App\Services\ExamsService::class)->totalOf($s) : null;
                            $grade = app(\App\Services\ExamsService::class)->gradeFor($total, $bands);
                            $name = "scores[{$enrolment->id}][{$cs->id}]";
                        @endphp
                        <tr x-data="{
                                ca1: @js((string) $v('ca1', 'ca1')), ca2: @js((string) $v('ca2', 'ca2')), ca3: @js((string) $v('ca3', 'ca3')), exam: @js((string) $v('exam', 'exam_score')),
                                absent: @js((bool) old("$key.absent", $s?->absent)),
                                get total() { if (this.absent) return 0; const f = [this.ca1, this.ca2, this.ca3, this.exam]; if (f.every(x => x === '' || x === null)) return null; return f.reduce((a, x) => a + (parseInt(x, 10) || 0), 0); }
                             }">
                            <td class="px-4 py-2 font-medium">
                                {{ $cs->subject?->name }}
                                @if ($cs->kind === \App\Services\AcademicsService::KIND_ELECTIVE)
                                    <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-800">Elective</span>
                                @endif
                            </td>
                            @foreach ([['ca1', 'ca1', \App\Services\ExamsService::MAX_CA], ['ca2', 'ca2', \App\Services\ExamsService::MAX_CA], ['ca3', 'ca3', \App\Services\ExamsService::MAX_CA], ['exam', 'exam_score', \App\Services\ExamsService::MAX_EXAM]] as [$f, $col, $max])
                                <td class="px-2 py-2">
                                    <input type="number" step="1" min="0" max="{{ $max }}" inputmode="numeric" pattern="[0-9]*"
                                           name="{{ $name }}[{{ $f }}]" x-model="{{ $f }}" :disabled="absent"
                                           value="{{ $v($f, $col) }}"
                                           aria-label="{{ $cs->subject?->name }} {{ strtoupper($f) }}"
                                           class="form-input !w-20 !py-1 text-right tabular-nums">
                                </td>
                            @endforeach
                            <td class="px-2 py-2 text-center">
                                <input type="checkbox" name="{{ $name }}[absent]" value="1" x-model="absent" @checked(old("$key.absent", $s?->absent))
                                       aria-label="{{ $cs->subject?->name }} absent">
                            </td>
                            <td class="px-2 py-2 text-right font-semibold tabular-nums" x-text="total === null ? '-' : total">{{ $total === null ? '-' : $fmt($total) }}</td>
                            <td class="px-2 py-2 text-center font-semibold" x-text="grade(total)">{{ $grade['letter'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="flex justify-end border-t border-black/5 pt-4">
            <button class="btn-primary">Save scores</button>
        </div>
    @endif
</form>
