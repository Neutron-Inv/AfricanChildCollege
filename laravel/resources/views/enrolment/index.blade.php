<x-school-layout title="Enrolment">
    @php
        // Keep filters / page when selecting an enrolment.
        $link = fn (array $extra = []) => route('enrolment', array_filter(array_merge([
            'session' => $session?->id,
            'class' => $classId,
            'status' => $status,
            'q' => $q,
            'per_page' => $perPage !== 25 ? $perPage : null,
            'page' => request('page'),
        ], $extra), fn ($v) => $v !== null && $v !== ''));
    @endphp

    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold">Enrolment</h2>
            <p class="text-sm text-gray-500">
                @if ($canManage ?? true)
                    Place students in classes, move them, manage electives and promote a session.
                @else
                    Choose elective subjects for students in your form class.
                @endif
            </p>
        </div>
        @if ($canManage ?? true)
            <div class="flex gap-2">
                <a href="{{ route('enrolment.promote', array_filter(['from' => $session?->id])) }}" class="btn-secondary">Promote session</a>
                @if ($session)
                    <button type="button" class="btn-primary" x-data @click="$dispatch('open-place')">Place student</button>
                @endif
            </div>
        @endif
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if (session('promotion_skipped'))
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-semibold">Skipped during promotion</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach (session('promotion_skipped') as $skip)
                    <li>{{ $skip['student'] }} - {{ $skip['reason'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $session)
        <div class="card text-sm text-gray-500">Create an academic session in Academics before enrolling students.</div>
    @else
        <div class="grid items-start gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                {{-- Filters --}}
                <form method="GET" action="{{ route('enrolment') }}" class="card flex flex-wrap items-end gap-3 !p-3">
                    <div>
                        <label for="session" class="text-xs text-gray-500">Session</label>
                        <select id="session" name="session" class="form-input mt-1" onchange="this.form.elements.class.value=''; this.form.submit()">
                            @foreach ($sessions as $s)
                                <option value="{{ $s->id }}" @selected($s->id === $session->id)>{{ $s->name }}{{ $s->is_current ? ' (current)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="class" class="text-xs text-gray-500">Class</label>
                        <select id="class" name="class" class="form-input mt-1">
                            <option value="">All classes</option>
                            @foreach ($classes as $class)
                                <option value="{{ $class->id }}" @selected($classId === $class->id)>{{ $class->display_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="status" class="text-xs text-gray-500">Status</label>
                        <select id="status" name="status" class="form-input mt-1">
                            <option value="">All</option>
                            @foreach (['ACTIVE' => 'Active', 'LEFT' => 'Left', 'GRADUATED' => 'Graduated'] as $key => $label)
                                <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-[10rem] flex-1">
                        <label for="q" class="text-xs text-gray-500">Search name or index</label>
                        <input id="q" name="q" value="{{ $q }}" class="form-input mt-1" placeholder="Search...">
                    </div>
                    <div>
                        <label for="per_page" class="text-xs text-gray-500">Per page</label>
                        <select id="per_page" name="per_page" class="form-input mt-1" onchange="this.form.submit()">
                            @foreach ($pageSizes as $size)
                                <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn-secondary">Filter</button>
                    @if ($classId !== '' || $status !== '' || $q !== '')
                        <a href="{{ route('enrolment', array_filter(['session' => $session->id, 'per_page' => $perPage !== 25 ? $perPage : null])) }}" class="btn-secondary">Clear</a>
                    @endif
                </form>

                {{-- List --}}
                <div class="card overflow-x-auto !p-0">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-2">Index</th>
                                <th class="px-4 py-2">Name</th>
                                <th class="px-4 py-2">Class</th>
                                <th class="px-4 py-2">Subjects</th>
                                <th class="px-4 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($enrolments as $row)
                                @php $isSelected = $selected?->id === $row->id; @endphp
                                <tr class="cursor-pointer hover:bg-gray-50 {{ $isSelected ? 'bg-gray-100' : '' }}"
                                    onclick="window.location.href='{{ $link(['enrolment' => $row->id]) }}'">
                                    <td class="px-4 py-2 font-mono text-xs">{{ $row->student?->index_number }}</td>
                                    <td class="px-4 py-2 font-medium">
                                        <a href="{{ $link(['enrolment' => $row->id]) }}" onclick="event.stopPropagation()">{{ $row->student?->full_name }}</a>
                                    </td>
                                    <td class="px-4 py-2 text-gray-600">{{ $row->class?->display_name }}</td>
                                    <td class="px-4 py-2 text-gray-600">{{ $row->subjects_count }}</td>
                                    <td class="px-4 py-2">
                                        @php
                                            $label = $row->outcome === 'GRADUATED' ? 'Graduated' : ($row->status === 'ACTIVE' ? 'Active' : 'Left');
                                            $tone = $row->outcome === 'GRADUATED' ? 'bg-sky-100 text-sky-800' : ($row->status === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700');
                                        @endphp
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $tone }}">{{ $label }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                    @if (! ($canManage ?? true) && $classes->isEmpty())
                                        You are not a form teacher in {{ $session->name }}.
                                    @else
                                        No enrolments found in {{ $session->name }}.
                                    @endif
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between text-xs text-gray-500">
                    <span>{{ $enrolments->total() }} enrolment(s) in {{ $session->name }}</span>
                </div>
                {{ $enrolments->links() }}
            </div>

            {{-- Sticky detail panel --}}
            <aside class="lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto">
                @if ($selected)
                    @include('enrolment.panel')
                @else
                    <div class="card text-sm text-gray-500">
                        {{ ($canManage ?? true) ? 'Select a student to move them or manage electives.' : 'Select a student to choose their elective subjects.' }}
                    </div>
                @endif
            </aside>
        </div>

        {{-- Place student modal --}}
        @if ($canManage ?? true)
        <div x-data="{ open: {{ $errors->any() || old('student_id') ? 'true' : 'false' }} }"
             @open-place.window="open = true"
             @keydown.escape.window="open = false"
             x-show="open" x-cloak
             class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
            <form method="POST" action="{{ route('enrolment.place') }}" @click.outside="open = false" class="card my-8 w-full max-w-lg space-y-4">
                @csrf
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold">Place student - {{ $session->name }}</h2>
                    <button type="button" @click="open = false" class="text-xl leading-none text-gray-400 hover:text-gray-700" aria-label="Close">&times;</button>
                </div>

                <div>
                    <x-input-label for="p_student" value="Unplaced student" />
                    <select id="p_student" name="student_id" class="form-input mt-1" required>
                        <option value="">Choose a student</option>
                        @foreach ($unplaced as $student)
                            <option value="{{ $student->id }}" @selected(old('student_id') === $student->id)>{{ $student->full_name }} ({{ $student->index_number }})</option>
                        @endforeach
                    </select>
                    @if ($unplaced->isEmpty())
                        <p class="mt-1 text-xs text-gray-500">Every active student already has a class in {{ $session->name }}.</p>
                    @endif
                    <x-input-error :messages="$errors->get('student_id')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="p_class" value="Class" />
                    <select id="p_class" name="class_id" class="form-input mt-1" required>
                        <option value="">Choose a class</option>
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}" @selected(old('class_id') === $class->id)>{{ $class->display_name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('class_id')" class="mt-1" />
                </div>
                <p class="text-xs text-gray-500">Compulsory subjects of the class are added automatically.</p>

                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                    <x-primary-button>Place student</x-primary-button>
                </div>
            </form>
        </div>
        @endif
    @endif
</x-school-layout>
