@php
    $periodData = $periods->mapWithKeys(fn ($p) => [$p->id => [
        'day' => $p->day,
        'start_time' => $p->start_time,
        'end_time' => $p->end_time,
        'class_subject_id' => $p->class_subject_id,
        'room_id' => $p->room_id ?? '',
    ]]);
    $className = fn ($c) => trim(($c?->level?->name ?? '').' '.($c?->arm?->name ?? ''));
    $slotCount = count($grid['slots']);
@endphp
<x-school-layout title="Timetable">
    <div class="mb-5">
        <h2 class="text-xl font-semibold">Timetable</h2>
        <p class="text-sm text-gray-500">
            Set the school day once, then choose a subject for each period.
            @unless ($canManage) You have view-only access. @endunless
        </p>
    </div>

    <form method="GET" action="{{ route('timetable') }}" class="card mb-5 flex flex-wrap items-end gap-4">
        <div>
            <label for="tt_session" class="block text-xs font-medium text-gray-600">Session</label>
            <select id="tt_session" name="session" class="form-input mt-1" onchange="this.form.elements['class'].value = ''; this.form.submit()">
                @foreach ($sessions as $s)
                    <option value="{{ $s->id }}" @selected($session?->id === $s->id)>{{ $s->name }}{{ $s->is_current ? ' (current)' : '' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="tt_class" class="block text-xs font-medium text-gray-600">Class</label>
            <select id="tt_class" name="class" class="form-input mt-1" onchange="this.form.submit()">
                @if ($isTeacher)
                    <option value="mine" @selected($showMine)>My periods</option>
                @endif
                @foreach ($classes as $c)
                    <option value="{{ $c->id }}" @selected($class?->id === $c->id)>{{ $className($c) }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button class="btn-secondary">Show</button></noscript>
    </form>

    @if ($canManage)
        @php
            $weekCount = $layout['week']->where('kind', 'PERIOD')->count();
            $breakCount = $layout['week']->where('kind', 'BREAK')->count();
        @endphp
        <details class="card mb-5" @if ($slots->isEmpty() || $errors->any()) open @endif>
            <summary class="cursor-pointer text-sm font-semibold">
                School day
                @if ($slots->isNotEmpty())
                    <span class="font-normal text-gray-500">· {{ $weekCount }} {{ str('period')->plural($weekCount) }}, {{ $breakCount }} {{ str('break')->plural($breakCount) }}{{ $layout['separate'] ? ', Friday is different' : ', including Friday' }}</span>
                @endif
            </summary>
            <form method="POST" action="{{ route('timetable.structure') }}" class="mt-4 space-y-4" x-data="{ friday: {{ old('friday_different') ? 'true' : 'false' }} }">
                @csrf
                <input type="hidden" name="session" value="{{ $session?->id }}">
                <input type="hidden" name="class" value="{{ $class?->id }}">
                <p class="text-sm text-gray-500">Every class shares these times. A period is usually the same length, with a break or two in the day. Friday can use its own times.</p>

                @include('timetable._day-fields', ['prefix' => '', 'start' => old('start', '08:00'), 'minutes' => old('period_minutes', 40), 'count' => old('periods', 6)])

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="friday_different" value="1" class="rounded border-gray-300" x-model="friday" @checked(old('friday_different'))>
                    Friday has different times
                </label>
                <div x-show="friday" x-cloak class="rounded-md border border-black/10 p-3">
                    <h3 class="mb-3 text-sm font-semibold">Friday</h3>
                    @include('timetable._day-fields', ['prefix' => 'friday_', 'start' => old('friday_start', '08:00'), 'minutes' => old('friday_period_minutes', 40), 'count' => old('friday_periods', 5)])
                </div>
                <button class="btn-primary">Save school day</button>
            </form>
            @if ($slots->isNotEmpty())
                <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $layout['separate'] ? 'Monday – Thursday' : 'Monday – Friday' }}</dt>
                        <dd class="mt-1 text-gray-700">
                            @foreach ($layout['week'] as $slot)
                                <span class="mr-3 inline-block">{{ $slot->label }} {{ $slot->start_time }}–{{ $slot->end_time }}</span>
                            @endforeach
                        </dd>
                    </div>
                    @if ($layout['separate'])
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Friday</dt>
                            <dd class="mt-1 text-gray-700">
                                @foreach ($layout['friday'] as $slot)
                                    <span class="mr-3 inline-block">{{ $slot->label }} {{ $slot->start_time }}–{{ $slot->end_time }}</span>
                                @endforeach
                            </dd>
                        </div>
                    @endif
                </dl>
            @endif
        </details>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div x-data="{
            editing: @js(old('editing') ?: null),
            periods: @js($periodData),
            form: @js([
                'day' => old('day', 'MONDAY'),
                'start_time' => old('start_time', '08:00'),
                'end_time' => old('end_time', '08:40'),
                'class_subject_id' => old('class_subject_id', ''),
                'room_id' => old('room_id', ''),
            ]),
            edit(id) { this.editing = id; this.form = { ...this.periods[id] }; window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }); },
            reset() { this.editing = null; this.form = { day: 'MONDAY', start_time: '08:00', end_time: '08:40', class_subject_id: '', room_id: '' }; },
         }"
         class="grid gap-6 {{ $canManage && $class && ! $useStructure ? 'xl:grid-cols-3' : '' }}">

        <div class="card overflow-x-auto {{ $canManage && $class && ! $useStructure ? 'xl:col-span-2' : '' }}" id="timetable-grid">
            <h3 class="mb-3 text-base font-semibold">
                @if ($showMine) My periods @elseif ($class) {{ $className($class) }} @else Timetable @endif
                @if ($session)<span class="text-sm font-normal text-gray-500">· {{ $session->name }}</span>@endif
            </h3>

            @if (! $class && ! $showMine)
                <p class="text-sm text-gray-500">No class to show for this session.</p>
            @elseif ($useStructure)
                @include('timetable._class-grid')
            @elseif ($slotCount === 0)
                <p class="text-sm text-gray-500">No periods scheduled yet. @if ($canManage) Set the school day above, then choose a subject for each period. @endif</p>
            @else
                <table class="w-full min-w-[640px] table-fixed border-collapse text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="w-28 border-b border-black/10 py-2 pr-2 font-medium">Time</th>
                            @foreach ($grid['days'] as $label)
                                <th class="border-b border-black/10 px-2 py-2 font-medium">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grid['slots'] as $slot)
                            @php $key = $slot['start'].'-'.$slot['end']; @endphp
                            <tr class="align-top">
                                <th scope="row" class="whitespace-nowrap border-b border-black/5 py-2 pr-2 font-medium text-gray-700">{{ $slot['start'] }} – {{ $slot['end'] }}</th>
                                @foreach ($grid['days'] as $day => $label)
                                    <td class="border-b border-black/5 px-2 py-2">
                                        @foreach ($grid['cells'][$key][$day] as $p)
                                            <div class="mb-1 rounded-md border border-black/5 bg-gray-50 p-2 last:mb-0">
                                                <div class="font-medium">{{ $p->classSubject?->subject?->name }}</div>
                                                @if ($showMine)
                                                    <div class="text-xs text-gray-600">{{ $className($p->classSubject?->class) }}</div>
                                                @elseif ($p->classSubject?->teacher)
                                                    <div class="text-xs text-gray-600">{{ $p->classSubject->teacher->full_name }}</div>
                                                @endif
                                                @if ($p->room)
                                                    <div class="text-xs text-gray-500">Room {{ $p->room->name }}</div>
                                                @endif
                                                @if ($canManage)
                                                    <div class="mt-1 flex gap-3 text-xs">
                                                        <button type="button" class="link-primary font-medium" @click="edit('{{ $p->id }}')">Edit</button>
                                                        <form method="POST" action="{{ route('timetable.periods.destroy', $p->id) }}" onsubmit="return confirm('Delete this period?')">
                                                            @csrf @method('DELETE')
                                                            <button class="font-medium text-red-700">Delete</button>
                                                        </form>
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($canManage && $class && ! $useStructure)
            <form method="POST"
                  x-bind:action="editing ? '{{ url('/timetable/periods') }}/' + editing : '{{ route('timetable.periods.store') }}'"
                  class="card space-y-3 self-start" id="period-form">
                @csrf
                <input type="hidden" name="_method" value="PUT" x-bind:disabled="!editing">
                <input type="hidden" name="editing" x-bind:value="editing || ''">

                <h3 class="text-base font-semibold" x-text="editing ? 'Edit period' : 'Add period'"></h3>

                <div>
                    <label for="p_day" class="block text-xs font-medium text-gray-600">Weekday</label>
                    <select id="p_day" name="day" x-model="form.day" class="form-input mt-1" required>
                        @foreach ($days as $value => $label)
                            <option value="{{ $value }}">{{ ucfirst(strtolower($value)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="p_start" class="block text-xs font-medium text-gray-600">Start time</label>
                        <input id="p_start" type="time" name="start_time" x-model="form.start_time" class="form-input mt-1" required>
                    </div>
                    <div>
                        <label for="p_end" class="block text-xs font-medium text-gray-600">End time</label>
                        <input id="p_end" type="time" name="end_time" x-model="form.end_time" class="form-input mt-1" required>
                    </div>
                </div>
                <div>
                    <label for="p_subject" class="block text-xs font-medium text-gray-600">Subject</label>
                    <select id="p_subject" name="class_subject_id" x-model="form.class_subject_id" class="form-input mt-1" required>
                        <option value="">Choose subject…</option>
                        @foreach ($classSubjects as $cs)
                            <option value="{{ $cs->id }}">{{ $cs->subject?->name }}{{ $cs->teacher ? ' — '.$cs->teacher->full_name : '' }}</option>
                        @endforeach
                    </select>
                    @if ($classSubjects->isEmpty())
                        <p class="mt-1 text-xs text-amber-700">Assign subjects to this class in Academics first.</p>
                    @endif
                </div>
                <div>
                    <label for="p_room" class="block text-xs font-medium text-gray-600">Room (optional)</label>
                    <select id="p_room" name="room_id" x-model="form.room_id" class="form-input mt-1">
                        <option value="">No room</option>
                        @foreach ($rooms as $r)
                            <option value="{{ $r->id }}">{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-2">
                    <button class="btn-primary flex-1" x-text="editing ? 'Save period' : 'Add period'"></button>
                    <button type="button" class="btn-secondary" x-show="editing" x-cloak @click="reset()">Cancel</button>
                </div>
            </form>
        @endif
    </div>
</x-school-layout>
