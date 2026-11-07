@php
    $className = fn ($c) => trim(($c?->level?->name ?? '').' '.($c?->arm?->name ?? ''));
    $sheetMode = $mode ?? null;
    $studentProfile = function ($enrolment) use ($className, $class, $term, $view, $sheetMode) {
        $student = $enrolment?->student;
        if (! $student) {
            return null;
        }
        $shownClass = $enrolment->relationLoaded('class') ? $enrolment->class : $class;

        return [
            'name' => $student->full_name,
            'index' => $student->index_number,
            'gender' => $student->gender,
            'phone' => $student->phone,
            'email' => $student->email,
            'address' => $student->address,
            'birthDate' => $student->birth_date?->format('j M Y'),
            'status' => $student->departure === 'GRADUATED' ? 'Graduated' : ($student->status === 'LEFT' ? 'Left' : 'Active'),
            'className' => $className($shownClass),
            'session' => $term?->session?->name,
            'guardians' => $student->relationLoaded('studentGuardians')
                ? $student->studentGuardians->map(fn ($link) => [
                    'name' => $link->guardian?->full_name,
                    'relationship' => $link->relationship,
                    'phone' => $link->guardian?->phone,
                    'email' => $link->guardian?->email,
                ])->filter(fn ($g) => filled($g['name']))->values()->all()
                : [],
            'scoresUrl' => $view === 'marksheet' && $sheetMode !== 'student'
                ? route('exams', ['view' => 'marksheet', 'term' => $term?->id, 'class' => $class?->id, 'student' => $enrolment->id])
                : null,
        ];
    };
    // Navigation links keep the term and class selection.
    $url = fn (array $extra = []) => route('exams', array_filter(array_merge([
        'view' => $view,
        'term' => $term?->id,
        'class' => $class?->id,
    ], $extra), fn ($v) => $v !== null && $v !== ''));
@endphp
<x-school-layout title="Exams">
    <div class="mb-5">
        <h2 class="text-xl font-semibold">Exams</h2>
        <p class="text-sm text-gray-500">Scores, broadsheets and report cards.</p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if (! $term)
        <div class="card text-sm text-gray-500">No terms exist yet. Add an academic session and term in Academics first.</div>
    @else
        {{-- Tabs --}}
        <div class="mb-4 flex flex-wrap gap-1 border-b border-black/10">
            @foreach ($views as $key => $label)
                <a href="{{ route('exams', array_filter(['view' => $key, 'term' => $term->id, 'class' => $key === 'subject' ? null : $class?->id])) }}"
                   class="-mb-px border-b-2 px-4 py-2 text-sm font-medium {{ $view === $key ? 'border-[color:var(--primary)] text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-800' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route('exams') }}" class="card mb-4 flex flex-wrap items-end gap-3 !p-3">
            <input type="hidden" name="view" value="{{ $view }}">
            @if ($view === 'broadsheet')
                <input type="hidden" name="sort" value="{{ $sort ?? 'position' }}">
                <input type="hidden" name="dir" value="{{ $dir ?? 'asc' }}">
            @endif
            <div>
                <label for="term" class="text-xs font-medium text-gray-500">Term</label>
                <select id="term" name="term" class="form-input mt-1" onchange="this.form.submit()">
                    @foreach ($terms as $t)
                        <option value="{{ $t->id }}" @selected($t->id === $term->id)>{{ $t->session?->name }} · {{ $t->name }}</option>
                    @endforeach
                </select>
            </div>

            @if (! ($ownOnly ?? false) && ($view !== 'subject' || $classes->isNotEmpty()))
                <div>
                    <label for="class" class="text-xs font-medium text-gray-500">Class</label>
                    <select id="class" name="class" class="form-input mt-1" onchange="this.form.submit()">
                        @forelse ($classes as $c)
                            <option value="{{ $c->id }}" @selected($c->id === $class?->id)>{{ $className($c) }}</option>
                        @empty
                            <option value="">No classes you teach</option>
                        @endforelse
                    </select>
                </div>
            @endif

            @if ($view === 'report' && isset($roster))
                <div>
                    <label for="enrolment" class="text-xs text-gray-500">Student</label>
                    <select id="enrolment" name="enrolment" class="form-input mt-1" onchange="this.form.submit()">
                        @foreach ($roster as $e)
                            <option value="{{ $e->id }}" @selected($e->id === $enrolment?->id)>{{ $e->student?->full_name }}@if ($ownOnly ?? false) - {{ $className($e->class) }}@endif</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <noscript><button class="btn-secondary">Apply</button></noscript>
        </form>

        @if ($view === 'marksheet')
            @if (! $class)
                <div class="card text-sm text-gray-500">There are no classes in this term's session yet.</div>
            @elseif ($mode === 'student')
                @include('exams._student-grid')
            @else
                @if (($subjects ?? collect())->isNotEmpty())
                    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-black/10" aria-label="Subjects">
                        @foreach ($subjects as $tab)
                            <a href="{{ route('exams', ['view' => 'marksheet', 'term' => $term->id, 'class' => $class->id, 'subject' => $tab->id]) }}"
                               class="-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium {{ $tab->id === $subject?->id ? 'text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-800' }}"
                               @if ($tab->id === $subject?->id) style="border-color: var(--primary)" aria-current="page" @endif>
                                {{ $tab->subject?->name }}
                            </a>
                        @endforeach
                    </div>
                @endif
                @include('exams._subject-grid')
            @endif
        @elseif ($view === 'subject')
            @if ($classes->isEmpty())
                <div class="card text-sm text-gray-500">You are not assigned to any subject in this session. Ask the office to put you on the class subjects.</div>
            @elseif (($tabs ?? collect())->isEmpty())
                <div class="card text-sm text-gray-500">You have no subjects in {{ $className($class) }} for this session.</div>
            @else
                <div class="mb-4 flex gap-1 overflow-x-auto border-b border-black/10" aria-label="Subjects">
                    @foreach ($tabs as $tab)
                        <a href="{{ $url(['view' => 'subject', 'class' => $class->id, 'subject' => $tab->id]) }}"
                           class="-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium {{ $tab->id === $subject?->id ? 'text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-800' }}"
                           @if ($tab->id === $subject?->id) style="border-color: var(--primary)" aria-current="page" @endif>
                            {{ $tab->subject?->name }}
                        </a>
                    @endforeach
                </div>
                @include('exams._subject-grid')
            @endif
        @elseif ($view === 'broadsheet')
            @include('exams._broadsheet')
        @else
            @include('exams._report')
        @endif

        @include('exams._student-modal')
    @endif
</x-school-layout>
