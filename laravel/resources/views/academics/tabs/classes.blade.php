@php
    $teacherName = fn ($t) => $t ? trim($t->first_name.' '.$t->last_name) : null;
    $teachersJson = $teachers->map(fn ($t) => [
        'id' => $t->id,
        'name' => trim($t->first_name.' '.$t->last_name),
    ])->values();
    $subjectsJson = $subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values();
    $classesJson = $classes->map(fn ($c) => [
        'id' => $c->id,
        'name' => $c->display_name,
        'form_teacher_id' => $c->form_teacher_id,
        'form_teacher_name' => $teacherName($c->formTeacher),
        'students_count' => (int) $c->students_count,
        'subjects_count' => (int) $c->subjects_count,
    ])->values();
    $panelJson = $class ? app(\App\Services\AcademicsService::class)->presentClassPanel($class->id, $viewerStaffId ?? null) : null;
@endphp

@if ($sessions->isEmpty())
    <div class="card text-sm text-gray-600">
        No academic sessions yet. <a class="link-primary font-medium underline" href="{{ route('academics', ['tab' => 'sessions']) }}">Create a session</a> first.
    </div>
@else
    <div class="grid items-start gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]"
         x-data="academicsClasses({
            canEdit: @js($canEdit),
            sessionId: @js($selectedSession->id),
            academicsUrl: @js(route('academics')),
            panelUrlTemplate: @js(route('academics.classes.panel', ['class' => '__CLASS__'])),
            syncUrlTemplate: @js(route('academics.classes.subjects.sync', ['class' => '__CLASS__'])),
            updateUrlTemplate: @js(route('academics.classes.update', ['class' => '__CLASS__'])),
            levels: @js($levels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->values()),
            arms: @js($arms->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()),
            storeSubjectUrlTemplate: @js(route('academics.class-subjects.store', ['class' => '__CLASS__'])),
            destroySubjectUrlTemplate: @js(route('academics.class-subjects.destroy', ['classSubject' => '__ID__'])),
            teachers: @js($teachersJson),
            subjectsCatalogue: @js($subjectsJson),
            classes: @js($classesJson),
            classId: @js($class?->id),
            panel: @js($panelJson),
         })">

        <aside class="space-y-4 lg:sticky lg:top-20">
            <form method="GET" action="{{ route('academics') }}" class="card !p-3">
                <input type="hidden" name="tab" value="classes">
                <label for="session" class="text-xs font-medium text-gray-500">Session</label>
                <select id="session" name="session" class="form-input mt-1" onchange="this.form.submit()">
                    @foreach ($sessions as $s)
                        <option value="{{ $s->id }}" @selected($s->id === $selectedSession->id)>{{ $s->name }}@if ($s->is_current) · current @endif</option>
                    @endforeach
                </select>
                <noscript><button class="btn-secondary mt-2 w-full">Go</button></noscript>
            </form>

            @if ($classes->isEmpty())
                <div class="card text-sm text-gray-500">
                    @if ($teacherScoped ?? false)
                        You are not assigned to any class in {{ $selectedSession->name }}. Ask the office to put you on a class subject (or as form teacher).
                    @else
                        No classes in {{ $selectedSession->name }} yet.
                    @endif
                </div>
            @else
                <div class="card !p-2">
                    <p class="px-2 pb-2 pt-1 text-xs font-medium uppercase tracking-wide text-gray-400">Classes</p>
                    <div class="max-h-[28rem] space-y-1 overflow-y-auto">
                        <template x-for="card in classes" :key="card.id">
                            <button type="button"
                                    @click="selectClass(card.id)"
                                    class="w-full rounded-md px-3 py-2.5 text-left transition hover:bg-gray-50"
                                    :class="classId === card.id ? 'bg-[color:color-mix(in_srgb,var(--primary)_8%,white)]' : ''"
                                    :style="classId === card.id ? 'box-shadow: inset 3px 0 0 var(--primary)' : ''"
                                    :aria-current="classId === card.id ? 'true' : null">
                                <div class="text-sm font-semibold" x-text="card.name"></div>
                                <div class="mt-0.5 truncate text-xs text-gray-500" x-text="card.form_teacher_name || 'No form teacher'"></div>
                                <div class="mt-1 text-[11px] text-gray-400">
                                    <span x-text="card.students_count"></span> students · <span x-text="card.subjects_count"></span> subjects
                                </div>
                            </button>
                        </template>
                    </div>
                </div>
            @endif

            @if ($canEdit)
                <form method="POST" action="{{ route('academics.classes.store') }}" class="card space-y-3 !p-4">
                    @csrf
                    <input type="hidden" name="session_id" value="{{ $selectedSession->id }}">
                    <h3 class="text-sm font-semibold">Add class</h3>
                    <div>
                        <label for="level_id" class="block text-xs font-medium text-gray-600">Level</label>
                        <select id="level_id" name="level_id" class="form-input mt-1" required>
                            <option value="">Choose…</option>
                            @foreach ($levels as $l) <option value="{{ $l->id }}">{{ $l->name }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="arm_id" class="block text-xs font-medium text-gray-600">Arm</label>
                        <select id="arm_id" name="arm_id" class="form-input mt-1" required>
                            <option value="">Choose…</option>
                            @foreach ($arms as $a) <option value="{{ $a->id }}">{{ $a->name }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="new_form_teacher" class="block text-xs font-medium text-gray-600">Form teacher</label>
                        <select id="new_form_teacher" name="form_teacher_id" class="form-input mt-1">
                            <option value="">None</option>
                            @foreach ($teachers as $t)
                                <option value="{{ $t->id }}">{{ trim($t->first_name.' '.$t->last_name) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn-primary w-full">Add class</button>
                </form>
            @endif
        </aside>

        <div class="min-w-0">
            <div x-show="loadingPanel" class="card text-sm text-gray-500" x-cloak>Loading class…</div>

            <template x-if="panel && !loadingPanel">
                <section class="card space-y-5">
                    <header class="flex flex-wrap items-end justify-between gap-4 border-b border-black/5 pb-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-xl font-semibold" x-text="panel.name"></h3>
                                <button type="button" class="group relative inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-600 hover:bg-black/5" x-show="canEdit && !editingDetails" @click="startDetails()" title="Edit class" aria-label="Edit class">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    <span class="pointer-events-none absolute left-1/2 top-full z-10 mt-1 -translate-x-1/2 whitespace-nowrap rounded bg-gray-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow group-hover:opacity-100">Edit class</span>
                                </button>
                            </div>
                            <p class="text-xs text-gray-500" x-text="panel.session_name"></p>
                            <form x-show="editingDetails" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="saveDetails()">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600">Level</label>
                                    <select class="form-input mt-1" x-model="panel.level_id" required>
                                        <template x-for="level in levels" :key="level.id">
                                            <option :value="level.id" :selected="panel.level_id === level.id" x-text="level.name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600">Arm</label>
                                    <select class="form-input mt-1" x-model="panel.arm_id" required>
                                        <template x-for="arm in arms" :key="arm.id">
                                            <option :value="arm.id" :selected="panel.arm_id === arm.id" x-text="arm.name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600">Admission fee</label>
                                    <input type="number" min="0" step="0.01" class="form-input mt-1" x-model="panel.admission_fee">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600">Hall charge</label>
                                    <input type="number" min="0" step="0.01" class="form-input mt-1" x-model="panel.hall_charge">
                                </div>
                                <div class="flex justify-end gap-2 sm:col-span-2">
                                    <button type="button" class="btn-secondary" @click="cancelDetails()">Cancel</button>
                                    <button class="btn-primary" :disabled="savingDetails" x-text="savingDetails ? 'Saving…' : 'Save class'"></button>
                                </div>
                            </form>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-800">
                                    <span x-text="compulsoryCount"></span> compulsory
                                </span>
                                <span class="rounded-full bg-amber-50 px-2.5 py-1 font-medium text-amber-800">
                                    <span x-text="electiveCount"></span> elective
                                </span>
                            </div>
                        </div>
                        <div class="w-full sm:w-64" x-show="canEdit">
                            <label class="block text-xs font-medium text-gray-600">Form teacher</label>
                            <select class="form-input mt-1" x-model="panel.form_teacher_id">
                                <option value="">None</option>
                                <template x-for="t in teachers" :key="t.id">
                                    <option :value="t.id" x-text="t.name"></option>
                                </template>
                            </select>
                        </div>
                        <p class="text-sm text-gray-600" x-show="!canEdit">
                            Form teacher: <strong x-text="panel.form_teacher_name || 'None'"></strong>
                        </p>
                    </header>

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="inline-flex rounded-lg bg-gray-100 p-0.5 text-sm" role="tablist" aria-label="Filter subjects">
                            <button type="button" class="rounded-md px-3 py-1.5 font-medium" :class="subjectFilter === 'all' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500'" @click="subjectFilter = 'all'">All</button>
                            <button type="button" class="rounded-md px-3 py-1.5 font-medium" :class="subjectFilter === 'COMPULSORY' ? 'bg-white shadow-sm text-emerald-800' : 'text-gray-500'" @click="subjectFilter = 'COMPULSORY'">Compulsory</button>
                            <button type="button" class="rounded-md px-3 py-1.5 font-medium" :class="subjectFilter === 'ELECTIVE' ? 'bg-white shadow-sm text-amber-800' : 'text-gray-500'" @click="subjectFilter = 'ELECTIVE'">Elective</button>
                        </div>
                        <input type="search" x-model="subjectQuery" placeholder="Find a subject" class="form-input !w-48" aria-label="Find a subject">
                    </div>

                    <p class="text-xs text-gray-500" x-show="canEdit">
                        Compulsory subjects are given to every active student. Electives are chosen on Enrolment. Change the toggle and teacher, then save once.
                    </p>
                    <p class="text-xs text-gray-500" x-show="!canEdit && panel.full_subjects">Every subject in your form class, with the teacher who takes it.</p>
                    <p class="text-xs text-gray-500" x-show="!canEdit && !panel.full_subjects" x-cloak>Subjects you teach in this class.</p>

                    <div x-show="!panel.subjects.length" class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center text-sm text-gray-500">
                        No subjects in this class yet.
                    </div>
                    <div x-show="panel.subjects.length && !visibleSubjects.length" class="text-sm text-gray-500" x-cloak>
                        Nothing matches that filter.
                    </div>

                    <div class="space-y-2" x-show="visibleSubjects.length">
                        <template x-for="row in visibleSubjects" :key="row.id">
                            <article class="rounded-lg border border-black/5 bg-gray-50/60 px-3 py-3 sm:px-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold" x-text="row.subject_name"></p>
                                        <p class="text-xs text-gray-500"><span x-text="row.students_count"></span> students</p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <template x-if="canEdit">
                                            <div class="inline-flex rounded-lg bg-white p-0.5 ring-1 ring-black/5" role="group" :aria-label="'Kind for ' + row.subject_name">
                                                <button type="button"
                                                        class="rounded-md px-2.5 py-1 text-xs font-semibold"
                                                        :class="row.kind === 'COMPULSORY' ? 'bg-emerald-600 text-white' : 'text-gray-500 hover:text-gray-800'"
                                                        @click="setKind(row, 'COMPULSORY')">Compulsory</button>
                                                <button type="button"
                                                        class="rounded-md px-2.5 py-1 text-xs font-semibold"
                                                        :class="row.kind === 'ELECTIVE' ? 'bg-amber-500 text-white' : 'text-gray-500 hover:text-gray-800'"
                                                        @click="setKind(row, 'ELECTIVE')">Elective</button>
                                            </div>
                                        </template>
                                        <span x-show="!canEdit"
                                              class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                              :class="row.kind === 'COMPULSORY' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                                              x-text="row.kind === 'COMPULSORY' ? 'Compulsory' : 'Elective'"></span>

                                        <template x-if="canEdit">
                                            <select class="form-input !w-44 !py-1.5"
                                                    x-model="row.teacher_id"
                                                    :aria-label="'Teacher for ' + row.subject_name">
                                                <option value="">Unassigned</option>
                                                <template x-for="t in teachers" :key="t.id">
                                                    <option :value="t.id" x-text="t.name"></option>
                                                </template>
                                            </select>
                                        </template>
                                        <span x-show="!canEdit" class="text-sm text-gray-600" x-text="row.teacher_name || teacherLabel(row.teacher_id)"></span>

                                        <button type="button"
                                                x-show="canEdit"
                                                class="rounded-md px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50"
                                                @click="removeSubject(row)">Remove</button>
                                    </div>
                                </div>
                            </article>
                        </template>
                    </div>

                    @if ($canEdit)
                        <div class="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-black/5 bg-white/95 px-4 py-3 shadow-md backdrop-blur">
                            <p class="text-sm text-gray-600" x-show="dirty" x-cloak>You have unsaved changes</p>
                            <p class="text-sm text-gray-400" x-show="!dirty">All changes saved</p>
                            <button type="button"
                                    class="btn-primary"
                                    :disabled="!dirty || saving"
                                    @click="save()"
                                    x-text="saving ? 'Saving…' : 'Save changes'"></button>
                        </div>

                        <div class="rounded-lg border border-dashed border-gray-200 p-4">
                            <button type="button" class="text-sm font-semibold" style="color: var(--primary)" @click="showAssign = !showAssign" x-text="showAssign ? 'Hide add subject' : 'Add subject'"></button>
                            <form x-show="showAssign" x-cloak class="mt-3 grid gap-3 sm:grid-cols-4 sm:items-end" @submit.prevent="assignSubject()">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600">Subject</label>
                                    <select class="form-input mt-1" x-model="assign.subject_id" required>
                                        <option value="">Choose…</option>
                                        <template x-for="s in availableSubjects" :key="s.id">
                                            <option :value="s.id" x-text="s.name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600">Teacher</label>
                                    <select class="form-input mt-1" x-model="assign.teacher_id">
                                        <option value="">Unassigned</option>
                                        <template x-for="t in teachers" :key="t.id">
                                            <option :value="t.id" x-text="t.name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600">Kind</label>
                                    <div class="mt-1 inline-flex rounded-lg bg-gray-100 p-0.5">
                                        <button type="button" class="rounded-md px-2.5 py-1.5 text-xs font-semibold" :class="assign.kind === 'COMPULSORY' ? 'bg-emerald-600 text-white' : 'text-gray-600'" @click="assign.kind = 'COMPULSORY'">Compulsory</button>
                                        <button type="button" class="rounded-md px-2.5 py-1.5 text-xs font-semibold" :class="assign.kind === 'ELECTIVE' ? 'bg-amber-500 text-white' : 'text-gray-600'" @click="assign.kind = 'ELECTIVE'">Elective</button>
                                    </div>
                                </div>
                                <button class="btn-primary" :disabled="assigning || !assign.subject_id" x-text="assigning ? 'Adding…' : 'Assign subject'"></button>
                            </form>
                        </div>
                    @endif
                </section>
            </template>

            <div class="card text-sm text-gray-500" x-show="!panel && !loadingPanel" x-cloak>
                @if ($teacherScoped ?? false)
                    Select a class. Your form class lists every subject and teacher. Other classes list only the subjects you teach.
                @else
                    Select a class to set its form teacher and which subjects are compulsory or elective.
                @endif
            </div>
        </div>
    </div>
@endif
