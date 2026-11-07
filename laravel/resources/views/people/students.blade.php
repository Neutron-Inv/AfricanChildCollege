@php
    $teacherView = $teacherView ?? false;
    $scope = $scope ?? 'class';
    $link = fn (array $extra = []) => route('people', array_filter(array_merge([
        'tab' => 'students',
        'status' => $teacherView || $status === 'ACTIVE' ? null : $status,
        'scope' => $teacherView && $scope === 'all' ? 'all' : null,
        'class' => $teacherView ? null : $classId,
        'q' => $q,
        'page' => request('page'),
    ], $extra), fn ($v) => $v !== null && $v !== ''));

    $className = fn ($student) => optional($student->enrolments->first()?->class)->display_name ?: '—';
@endphp

<div class="grid items-start gap-6 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-3">
            @if ($teacherView)
                <div class="inline-flex rounded-md border border-gray-200 bg-white p-0.5 text-sm">
                    @foreach (['class' => 'My class students', 'all' => 'All my students'] as $key => $label)
                        <a href="{{ route('people', array_filter(['tab' => 'students', 'scope' => $key === 'all' ? 'all' : null, 'q' => $q])) }}"
                           class="rounded px-3 py-1.5 font-medium {{ $scope === $key ? 'text-white' : 'text-gray-600 hover:bg-gray-50' }}"
                           @if ($scope === $key) style="background: var(--primary)" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            @else
                <div class="inline-flex rounded-md border border-gray-200 bg-white p-0.5 text-sm">
                    @foreach (['ACTIVE' => 'Active', 'LEFT' => 'Left', 'GRADUATED' => 'Graduated'] as $key => $label)
                        <a href="{{ route('people', array_filter(['tab' => 'students', 'status' => $key === 'ACTIVE' ? null : $key, 'class' => $classId, 'q' => $q])) }}"
                           class="rounded px-3 py-1.5 font-medium {{ $status === $key ? 'text-white' : 'text-gray-600 hover:bg-gray-50' }}"
                           @if ($status === $key) style="background: var(--primary)" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            @endif
            @if ($canEditStudents)
                <button type="button" class="btn-primary" @click="$dispatch('open-create-student')">Add student</button>
            @endif
        </div>

        @if ($teacherView)
            <p class="text-xs text-gray-500">
                @if ($scope === 'all')
                    Active students in your class, and students in other classes who take your subjects.
                @else
                    Active students in the class you form-teach.
                @endif
            </p>
        @endif

        <form method="GET" action="{{ route('people') }}" class="card flex flex-wrap items-end gap-3 !p-3">
            <input type="hidden" name="tab" value="students">
            @if (! $teacherView && $status !== 'ACTIVE') <input type="hidden" name="status" value="{{ $status }}"> @endif
            @if ($teacherView && $scope === 'all') <input type="hidden" name="scope" value="all"> @endif
            <div class="min-w-[12rem] flex-1">
                <label for="q" class="text-xs text-gray-500">Search name or index</label>
                <input id="q" type="search" name="q" value="{{ $q }}" class="form-input mt-1" placeholder="Search...">
            </div>
            @unless ($teacherView)
                <div>
                    <label for="class" class="text-xs text-gray-500">Class{{ $currentSession ? ' · '.$currentSession->name : '' }}</label>
                    <select id="class" name="class" class="form-input mt-1" onchange="this.form.submit()">
                        <option value="">All classes</option>
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}" @selected($classId === $class->id)>{{ $class->display_name }}</option>
                        @endforeach
                    </select>
                </div>
            @endunless
            <button class="btn-secondary">Search</button>
            @if ($q !== '' || (! $teacherView && $classId !== ''))
                <a href="{{ route('people', array_filter(['tab' => 'students', 'status' => ! $teacherView && $status !== 'ACTIVE' ? $status : null, 'scope' => $teacherView && $scope === 'all' ? 'all' : null])) }}" class="btn-secondary">Clear</a>
            @endif
        </form>

        <div class="card overflow-x-auto !p-0">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Index</th>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2">Class</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($students as $student)
                        @php $isSelected = $selected?->id === $student->id; @endphp
                        <tr class="cursor-pointer hover:bg-gray-50 {{ $isSelected ? 'bg-[color:color-mix(in_srgb,var(--primary)_8%,white)]' : '' }}"
                            @if ($isSelected) style="box-shadow: inset 3px 0 0 var(--primary)" @endif
                            onclick="window.location.href='{{ $link(['student' => $student->id]) }}'">
                            <td class="px-4 py-2 font-mono text-xs">{{ $student->index_number }}</td>
                            <td class="px-4 py-2 font-medium">
                                <a href="{{ $link(['student' => $student->id]) }}" onclick="event.stopPropagation()" @if ($isSelected) aria-current="true" @endif>{{ $student->full_name }}</a>
                            </td>
                            <td class="px-4 py-2 text-gray-600">{{ $className($student) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-8 text-center text-gray-500">
                            @if ($teacherView && $scope === 'class')
                                No active students in your class.
                            @elseif ($teacherView)
                                No students in your class or taking your subjects.
                            @else
                                No {{ $status === 'GRADUATED' ? 'graduated' : ($status === 'LEFT' ? 'former' : 'active') }} students found.
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-400">{{ $students->total() }} {{ $teacherView ? ($scope === 'class' ? 'in your class' : 'students') : ($status === 'GRADUATED' ? 'graduated' : ($status === 'LEFT' ? 'former' : 'active')).' students' }}</p>
        {{ $students->links() }}
    </div>

    <aside class="lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto">
        @if ($selected)
            @include('people.student-profile', ['student' => $selected])
        @else
            <div class="card text-sm text-gray-500">Select a student to see their profile.</div>
        @endif
    </aside>
</div>

@if ($canEditStudents)
    <div x-data="{ open: {{ $errors->any() && ! $errors->hasBag('guardian') && ! $errors->hasBag('student') ? 'true' : 'false' }} }"
         @open-create-student.window="open = true"
         @keydown.escape.window="open = false"
         x-show="open" x-cloak
         class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
        @php $sOld = fn ($f) => $errors->hasBag('guardian') ? null : old($f); @endphp
        <form method="POST" action="{{ route('people.students.store') }}" @click.outside="open = false"
              class="card my-8 w-full max-w-2xl space-y-4">
            @csrf
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">Add student</h2>
                <button type="button" @click="open = false" class="text-xl leading-none text-gray-400 hover:text-gray-700" aria-label="Close">&times;</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="s_index" value="Index number" />
                    <x-text-input id="s_index" name="index_number" class="mt-1" :value="$sOld('index_number')" required />
                    <x-input-error :messages="$errors->get('index_number')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="s_name" value="Full name" />
                    <x-text-input id="s_name" name="full_name" class="mt-1" :value="$sOld('full_name')" required />
                    <x-input-error :messages="$errors->get('full_name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="s_gender" value="Gender" />
                    <select id="s_gender" name="gender" class="form-input mt-1">
                        <option value="">-</option>
                        @foreach (['Male', 'Female'] as $g)
                            <option value="{{ $g }}" @selected($sOld('gender') === $g)>{{ $g }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('gender')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="s_birth" value="Birth date" />
                    <x-text-input id="s_birth" type="date" name="birth_date" class="mt-1" :value="$sOld('birth_date')" />
                    <x-input-error :messages="$errors->get('birth_date')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="s_email" value="Email (login)" />
                    <x-text-input id="s_email" type="email" name="email" class="mt-1" :value="$sOld('email')" required />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="s_password" value="Initial password" />
                    <x-text-input id="s_password" type="password" name="password" class="mt-1" autocomplete="new-password" required />
                    <x-input-error :messages="$errors->get('password')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="s_phone" value="Phone" />
                    <x-text-input id="s_phone" name="phone" class="mt-1" :value="$sOld('phone')" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="s_class" value="Class (optional){{ $currentSession ? ' · '.$currentSession->name : '' }}" />
                    <select id="s_class" name="class_id" class="form-input mt-1">
                        <option value="">No placement</option>
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}" @selected($sOld('class_id') === $class->id)>{{ $class->display_name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('class_id')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="s_address" value="Address" />
                    <x-text-input id="s_address" name="address" class="mt-1" :value="$sOld('address')" />
                    <x-input-error :messages="$errors->get('address')" class="mt-1" />
                </div>
            </div>

            <p class="text-xs text-gray-500">The student must change this password at first login.</p>

            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                <x-primary-button>Create student</x-primary-button>
            </div>
        </form>
    </div>
@endif
