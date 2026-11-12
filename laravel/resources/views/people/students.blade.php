@php
    $teacherView = $teacherView ?? false;
    $scope = $scope ?? 'class';
    $link = fn (array $extra = []) => route('people', array_filter(array_merge([
        'tab' => 'students',
        'status' => $teacherView || $status === 'ACTIVE' ? null : $status,
        'scope' => $teacherView && $scope === 'all' ? 'all' : null,
        'class' => $teacherView ? null : $classId,
        'q' => $q,
        'sort' => ($sort ?? 'name') === 'class' ? 'class' : null,
        'dir' => ($sort ?? 'name') === 'class' && ($dir ?? 'asc') === 'desc' ? 'desc' : null,
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
                        <a href="{{ $link(['status' => $key === 'ACTIVE' ? null : $key, 'page' => null]) }}"
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
            @if (($sort ?? 'name') === 'class')
                <input type="hidden" name="sort" value="class">
                @if (($dir ?? 'asc') === 'desc') <input type="hidden" name="dir" value="desc"> @endif
            @endif
            <div class="min-w-[12rem] flex-1">
                <label for="q" class="text-xs text-gray-500">Search name or index</label>
                <input id="q" type="search" name="q" value="{{ $q }}" class="form-input mt-1" placeholder="Search...">
            </div>
            @unless ($teacherView)
                <div>
                    <label for="class" class="text-xs text-gray-500">Class{{ $currentSession ? ' · '.$currentSession->name : '' }}</label>
                    <select id="class" name="class" class="form-input mt-1" onchange="this.form.submit()">
                        <option value="">All classes</option>
                        <option value="none" @selected($classId === 'none')>No class</option>
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
                        <th class="px-4 py-2">
                            @php $classSortDir = ($sort ?? 'name') === 'class' && ($dir ?? 'asc') === 'asc' ? 'desc' : 'asc'; @endphp
                            <a href="{{ $link(['sort' => 'class', 'dir' => $classSortDir === 'desc' ? 'desc' : null, 'page' => null]) }}" class="hover:text-gray-800">
                                Class
                                @if (($sort ?? 'name') === 'class')
                                    <span aria-hidden="true">{{ ($dir ?? 'asc') === 'desc' ? '↓' : '↑' }}</span>
                                @endif
                            </a>
                        </th>
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
    @php
        $sOld = fn ($f) => $errors->hasBag('guardian') ? null : old($f);
        $createErrors = $errors->any() && ! $errors->hasBag('guardian') && ! $errors->hasBag('student');
        $detailError = $createErrors && $errors->hasAny(['index_number', 'full_name', 'gender', 'email', 'password', 'address', 'phone', 'birth_date']);
        $createStep = $detailError ? 1 : ($createErrors && $errors->has('class_id') ? 2 : ($createErrors ? 3 : 1));
        $classNames = ($classes ?? collect())->mapWithKeys(fn ($class) => [$class->id => $class->display_name])->all();
        $suggestedIndex = $sOld('index_number') ?: ($nextIndex ?? '1');
    @endphp
    <div x-data="{
            open: {{ $createErrors ? 'true' : 'false' }},
            step: {{ $createStep }},
            classId: @js((string) $sOld('class_id')),
            electives: @js($electivesByClass ?? []),
            oldElectives: @js(array_values(array_filter((array) ($createErrors ? old('elective_ids', []) : [])))),
            classNames: @js($classNames),
            hasClasses: {{ ($classes ?? collect())->isNotEmpty() ? 'true' : 'false' }},
            continueDetails() {
                const fields = this.$refs.details.querySelectorAll('input, select, textarea');
                for (const field of fields) {
                    if (!field.checkValidity()) { field.reportValidity(); return; }
                }
                if (this.hasClasses) this.step = 2;
            },
            continueClass() {
                if (!this.classId) { this.$refs.classSelect.reportValidity(); return; }
                this.step = 3;
            },
            finish(event) {
                if (!this.hasClasses) return;
                if (this.step === 1) { event.preventDefault(); this.continueDetails(); }
                else if (this.step === 2) { event.preventDefault(); this.continueClass(); }
            }
         }"
         @open-create-student.window="open = true"
         @keydown.escape.window="open = false"
         x-show="open" x-cloak
         class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
        <form x-ref="form" method="POST" action="{{ route('people.students.store') }}" @click.outside="open = false" @submit="finish($event)"
              class="card my-8 w-full max-w-2xl space-y-4">
            @csrf
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold" x-text="step === 1 ? 'Add student' : (step === 2 ? 'Choose a class' : 'Electives')"></h2>
                    <ol class="mt-1 flex gap-3 text-xs text-gray-400" x-show="hasClasses">
                        <li :class="step === 1 ? 'font-semibold text-gray-800' : ''">1. Details</li>
                        <li :class="step === 2 ? 'font-semibold text-gray-800' : ''">2. Class</li>
                        <li :class="step === 3 ? 'font-semibold text-gray-800' : ''">3. Electives</li>
                    </ol>
                </div>
                <button type="button" @click="open = false" class="text-xl leading-none text-gray-400 hover:text-gray-700" aria-label="Close">&times;</button>
            </div>

            <div x-show="step === 1" x-ref="details" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="s_index" value="Index number" />
                    <x-text-input id="s_index" name="index_number" class="mt-1" :value="$suggestedIndex" required />
                    <p class="mt-1 text-xs text-gray-500">Next after the highest index in this school. Change it if this student already has one.</p>
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
                    <x-text-input id="s_birth" type="date" name="birth_date" class="mt-1" :value="$sOld('birth_date')" :max="now()->toDateString()" />
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
                <div class="sm:col-span-2">
                    <x-input-label for="s_address" value="Address" />
                    <x-text-input id="s_address" name="address" class="mt-1" :value="$sOld('address')" />
                    <x-input-error :messages="$errors->get('address')" class="mt-1" />
                </div>
                <p class="text-xs text-gray-500 sm:col-span-2">The student must change this password at first login.</p>
            </div>

            <div x-show="step === 2" x-cloak class="space-y-3">
                <div>
                    <x-input-label for="s_class" value="Class{{ $currentSession ? ' · '.$currentSession->name : '' }}" />
                    <select id="s_class" name="class_id" x-ref="classSelect" x-model="classId" class="form-input mt-1" required>
                        <option value="">Choose a class</option>
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}">{{ $class->display_name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('class_id')" class="mt-1" />
                </div>
                <p class="text-xs text-gray-500">Compulsory subjects are added with the class. Electives are chosen next.</p>
            </div>

            <div x-show="step === 3" x-cloak class="space-y-3">
                <p class="text-sm text-gray-600">Class: <span class="font-medium" x-text="classNames[classId] || ''"></span></p>
                <div class="space-y-2">
                    <template x-for="offer in (electives[classId] || [])" :key="offer.id">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="elective_ids[]" :value="offer.id" class="rounded border-gray-300" :checked="oldElectives.includes(offer.id)">
                            <span x-text="offer.name"></span>
                        </label>
                    </template>
                </div>
                <p x-show="(electives[classId] || []).length === 0" class="text-sm text-gray-500">This class has no elective subjects. Compulsory subjects are still added.</p>
                <p x-show="(electives[classId] || []).length > 0" class="text-xs text-gray-500">Leave these unticked to add compulsory subjects only.</p>
                <x-input-error :messages="$errors->get('elective_ids')" class="mt-1" />
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" x-show="step === 1" @click="open = false">Cancel</button>
                <button type="button" class="btn-secondary" x-show="step > 1" x-cloak @click="step = step - 1">Back</button>
                <button type="button" class="btn-primary" x-show="step === 1 && hasClasses" @click="continueDetails()">Continue</button>
                <button type="button" class="btn-primary" x-show="step === 2" x-cloak @click="continueClass()">Continue</button>
                <button type="submit" class="btn-primary" x-show="!hasClasses || step === 3" x-cloak>Create student</button>
            </div>
        </form>
    </div>
@endif
