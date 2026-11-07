@php
    $daysFull = ['MONDAY' => 'Monday', 'TUESDAY' => 'Tuesday', 'WEDNESDAY' => 'Wednesday', 'THURSDAY' => 'Thursday', 'FRIDAY' => 'Friday'];
    $palette = [
        ['#dbeafe', '#1e3a8a'],
        ['#dcfce7', '#14532d'],
        ['#fef3c7', '#78350f'],
        ['#fce7f3', '#9d174d'],
        ['#e0e7ff', '#312e81'],
        ['#ffedd5', '#9a3412'],
        ['#ccfbf1', '#134e4a'],
        ['#fae8ff', '#6b21a8'],
        ['#fee2e2', '#991b1b'],
        ['#ecfccb', '#3f6212'],
    ];
    $tone = function (?string $subjectId) use ($palette): ?array {
        if (! $subjectId) {
            return null;
        }

        return $palette[hexdec(substr(md5($subjectId), 0, 2)) % count($palette)];
    };
    $tables = [
        ['rows' => $layout['week'], 'days' => $layout['weekDays'], 'caption' => $layout['separate'] ? 'Monday – Thursday' : null],
    ];
    if ($layout['separate']) {
        $tables[] = ['rows' => $layout['friday'], 'days' => ['FRIDAY' => 'Friday'], 'caption' => 'Friday'];
    }
@endphp

<div x-data="{ editing: {{ old('assignments') ? 'true' : 'false' }} }">
    @if ($canManage)
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-gray-500" x-show="!editing">Days down the side, periods across. Each colour is a subject.</p>
            <button type="button" class="btn-secondary" x-show="!editing" @click="editing = true">Edit timetable</button>
        </div>
        <form method="POST" action="{{ route('timetable.assignments') }}">
            @csrf
            <input type="hidden" name="session" value="{{ $session?->id }}">
            <input type="hidden" name="class_id" value="{{ $class->id }}">
    @endif

    @if ($canManage && $classSubjects->isEmpty())
        <p class="mb-3 text-sm text-amber-700" x-show="editing" x-cloak>Assign subjects to this class in Academics first.</p>
    @endif

    <div class="space-y-8">
        @foreach ($tables as $table)
            <div>
                @if ($table['caption'])
                    <h4 class="mb-2 text-sm font-semibold">{{ $table['caption'] }}</h4>
                @endif
                <table class="w-full min-w-[720px] border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="w-28 border-b border-black/10 px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Day</th>
                            @foreach ($table['rows'] as $slot)
                                <th class="border-b border-black/10 px-2 py-2 text-center text-xs font-medium {{ $slot->kind === 'BREAK' ? 'w-16 text-amber-800' : 'text-gray-500' }}">
                                    {{ $slot->label }}
                                    <div class="mt-0.5 font-normal normal-case tracking-normal">{{ $slot->start_time }}–{{ $slot->end_time }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table['days'] as $day => $short)
                            <tr>
                                <th scope="row" class="whitespace-nowrap border-b border-black/5 px-3 py-2 text-left font-semibold text-gray-800" style="box-shadow: inset 3px 0 0 var(--primary)">
                                    {{ $daysFull[$day] ?? $short }}
                                </th>
                                @foreach ($table['rows'] as $slot)
                                    @if ($slot->kind === 'BREAK')
                                        <td class="border-b border-amber-100 bg-amber-50"></td>
                                    @else
                                        @php
                                            $picked = $selection[$slot->id][$day] ?? null;
                                            $colors = $tone($picked?->classSubject?->subject_id);
                                        @endphp
                                        <td class="border-b border-black/5 p-1.5 align-middle">
                                            <div @if ($canManage) x-show="!editing" @endif>
                                                @if ($picked)
                                                    <div class="rounded-md px-2 py-2" @if ($colors) style="background: {{ $colors[0] }}; color: {{ $colors[1] }}" @endif>
                                                        <div class="font-semibold leading-tight">{{ $picked->classSubject?->subject?->name }}</div>
                                                        @if ($picked->classSubject?->teacher)
                                                            <div class="mt-0.5 text-xs opacity-80">{{ $picked->classSubject->teacher->full_name }}</div>
                                                        @endif
                                                        @if ($picked->room)
                                                            <div class="text-xs opacity-70">Room {{ $picked->room->name }}</div>
                                                        @endif
                                                    </div>
                                                @else
                                                    <div class="rounded-md border border-dashed border-black/10 px-2 py-3 text-center text-xs text-gray-400">Free</div>
                                                @endif
                                            </div>
                                            @if ($canManage)
                                                <select name="assignments[{{ $slot->id }}][{{ $day }}]" x-show="editing" x-cloak class="form-input" aria-label="{{ $daysFull[$day] ?? $short }} {{ $slot->label }}">
                                                    <option value="">Free</option>
                                                    @foreach ($classSubjects as $cs)
                                                        <option value="{{ $cs->id }}" @selected($picked?->class_subject_id === $cs->id)>{{ $cs->subject?->name }}{{ $cs->teacher ? ' — '.$cs->teacher->full_name : '' }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                        </td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    @if ($canManage)
            <div class="mt-4 flex flex-wrap items-center justify-end gap-2" x-show="editing" x-cloak>
                <p class="mr-auto text-xs text-gray-500">Choose a subject for each period. Free leaves that period empty.</p>
                <button type="button" class="btn-secondary" @click="editing = false; $el.closest('form').reset()">Cancel</button>
                <button class="btn-primary">Save timetable</button>
            </div>
        </form>
    @endif

    @if ($unmatched->isNotEmpty())
        <div class="mt-4 border-t border-black/10 pt-3">
            <h4 class="text-sm font-semibold">Outside the school day</h4>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($unmatched as $p)
                    <li class="flex flex-wrap items-center gap-3">
                        <span>{{ ucfirst(strtolower($p->day)) }} {{ $p->start_time }} – {{ $p->end_time }} · {{ $p->classSubject?->subject?->name }}</span>
                        @if ($canManage)
                            <form method="POST" action="{{ route('timetable.periods.destroy', $p->id) }}" onsubmit="return confirm('Delete this period?')">
                                @csrf @method('DELETE')
                                <button class="text-xs font-medium text-red-700">Delete</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
