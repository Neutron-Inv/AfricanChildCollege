@php
    $departmentsJson = $departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values();
    $classesJson = $classes->map(fn ($c) => ['id' => $c->id, 'name' => $c->display_name])->values();
    $teachersJson = $teachers->map(fn ($t) => ['id' => $t->id, 'name' => trim($t->first_name.' '.$t->last_name)])->values();
@endphp

@if ($sessions->isEmpty())
    <div class="card text-sm text-gray-600">
        No academic sessions yet. <a class="link-primary font-medium underline" href="{{ route('academics', ['tab' => 'sessions']) }}">Create a session</a> first.
    </div>
@else
<div class="grid items-start gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]"
     x-data="academicsSubjects({
        canEdit: @js($canEdit),
        academicsUrl: @js(route('academics')),
        updateUrlTemplate: @js(route('academics.subjects.update', ['subject' => '__ID__'])),
        destroySubjectUrlTemplate: @js(route('academics.subjects.destroy', ['subject' => '__ID__'])),
        storeClassUrlTemplate: @js(route('academics.subjects.classes.store', ['subject' => '__ID__'])),
        destroyClassUrlTemplate: @js(route('academics.subjects.classes.destroy', ['subject' => '__ID__', 'classSubject' => '__CLASS_SUBJECT__'])),
        subjects: @js($catalogue),
        departments: @js($departmentsJson),
        classes: @js($classesJson),
        teachers: @js($teachersJson),
        selectedId: @js($selectedSubjectId),
        sessionId: @js($selectedSession->id),
     })">

    <aside class="space-y-4 lg:sticky lg:top-20">
        <form method="GET" action="{{ route('academics') }}" class="card !p-3">
            <input type="hidden" name="tab" value="subjects">
            <input type="hidden" name="subject" :value="selectedId || ''">
            <label for="subject_session" class="text-xs font-medium text-gray-500">Session</label>
            <select id="subject_session" name="session" class="form-input mt-1" onchange="this.form.submit()">
                @foreach ($sessions as $s)
                    <option value="{{ $s->id }}" @selected($s->id === $selectedSession->id)>{{ $s->name }}@if ($s->is_current) · current @endif</option>
                @endforeach
            </select>
        </form>

        <div class="card !p-3 space-y-3">
            <input type="search" x-model="query" placeholder="Find a subject" class="form-input" aria-label="Find a subject">
            <div class="flex flex-wrap gap-1">
                <button type="button" class="rounded-full px-2.5 py-1 text-xs font-semibold"
                        :class="departmentFilter === 'all' ? 'text-white' : 'bg-gray-100 text-gray-600'"
                        :style="departmentFilter === 'all' ? 'background: var(--primary)' : ''"
                        @click="departmentFilter = 'all'">All</button>
                <button type="button" class="rounded-full px-2.5 py-1 text-xs font-semibold"
                        :class="departmentFilter === 'none' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600'"
                        @click="departmentFilter = 'none'">No department</button>
                <template x-for="dept in departments" :key="dept.id">
                    <button type="button" class="rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="departmentFilter === dept.id ? 'text-white' : 'bg-gray-100 text-gray-600'"
                            :style="departmentFilter === dept.id ? 'background: var(--primary)' : ''"
                            @click="departmentFilter = dept.id"
                            x-text="dept.name"></button>
                </template>
            </div>
        </div>

        <div class="card !p-2">
            <p class="px-2 pb-2 pt-1 text-xs font-medium uppercase tracking-wide text-gray-400">
                Subjects · <span x-text="filtered.length"></span>
            </p>
            <div class="max-h-[28rem] space-y-1 overflow-y-auto" x-show="filtered.length">
                <template x-for="subject in filtered" :key="subject.id">
                    <button type="button"
                            @click="select(subject.id)"
                            class="w-full rounded-md px-3 py-2.5 text-left transition hover:bg-gray-50"
                            :class="selectedId === subject.id ? 'bg-[color:color-mix(in_srgb,var(--primary)_8%,white)]' : ''"
                            :style="selectedId === subject.id ? 'box-shadow: inset 3px 0 0 var(--primary)' : ''"
                            :aria-current="selectedId === subject.id ? 'true' : null">
                        <div class="text-sm font-semibold" x-text="subject.name"></div>
                        <div class="mt-0.5 truncate text-xs text-gray-500" x-text="subject.department_name || 'No department'"></div>
                        <div class="mt-1 text-[11px] text-gray-400">
                            <span x-text="subject.offerings.length"></span> class offerings
                        </div>
                    </button>
                </template>
            </div>
            <p class="px-2 py-3 text-sm text-gray-500" x-show="!filtered.length" x-cloak>No subjects match.</p>
        </div>

        @if ($canEdit)
            <form method="POST" action="{{ route('academics.subjects.store') }}" class="card space-y-3 !p-4">
                @csrf
                <h3 class="text-sm font-semibold">Add subject</h3>
                <div>
                    <label for="subject_name" class="block text-xs font-medium text-gray-600">Name</label>
                    <input id="subject_name" name="name" value="{{ old('name') }}" class="form-input mt-1" required maxlength="120">
                </div>
                <div>
                    <label for="subject_department" class="block text-xs font-medium text-gray-600">Department</label>
                    <select id="subject_department" name="department_id" class="form-input mt-1">
                        <option value="">None</option>
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}" @selected(old('department_id') === $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn-primary w-full">Add subject</button>
            </form>
        @endif
    </aside>

    <div class="min-w-0">
        <template x-if="selected">
            <section class="card space-y-5">
                <header class="border-b border-black/5 pb-4">
                    <div class="flex items-center gap-2">
                        <h3 class="text-xl font-semibold" x-text="canEdit ? 'Edit subject' : selected.name"></h3>
                        @if ($canEdit)
                            <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-red-700 hover:bg-red-50" @click="askToDelete()" title="Delete subject" aria-label="Delete subject">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                        <span x-text="selected.offerings.length"></span> classes in {{ $selectedSession->name }}.
                    </p>
                </header>

                <template x-if="canEdit">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-600">Name</label>
                            <input class="form-input mt-1" x-model="draft.name" maxlength="120" required>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600">Department</label>
                            <select class="form-input mt-1" x-model="draft.department_id">
                                <option value="">None</option>
                                <template x-for="dept in departments" :key="dept.id">
                                    <option :value="dept.id" x-text="dept.name"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </template>
                <p class="text-sm text-gray-600" x-show="!canEdit">
                    Department: <strong x-text="selected.department_name || 'None'"></strong>
                </p>

                <div>
                    <h4 class="mb-2 text-sm font-semibold text-gray-800">Offered in</h4>
                    <p class="mb-3 text-xs text-gray-500" x-show="canEdit">Add a class here, or add this subject from the class page. Compulsory subjects are given to every active student in that class. Electives are chosen on Enrolment.</p>
                    <div x-show="!selected.offerings.length" class="rounded-lg border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500">
                        Not offered in {{ $selectedSession->name }}.
                    </div>
                    <div class="space-y-2" x-show="selected.offerings.length">
                        <template x-for="offer in selected.offerings" :key="offer.id">
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-black/5 bg-gray-50/60 px-3 py-2.5">
                                <p class="text-sm font-semibold" x-text="offer.class_name"></p>
                                <div class="flex flex-wrap items-center gap-2">
                                    <template x-if="canEdit">
                                        <div class="inline-flex rounded-lg bg-white p-0.5 ring-1 ring-black/5" role="group" :aria-label="'Kind for ' + offer.class_name">
                                            <button type="button"
                                                    class="rounded-md px-2.5 py-1 text-xs font-semibold"
                                                    :class="offer.kind === 'COMPULSORY' ? 'bg-emerald-600 text-white' : 'text-gray-500 hover:text-gray-800'"
                                                    @click="offer.kind = 'COMPULSORY'">Compulsory</button>
                                            <button type="button"
                                                    class="rounded-md px-2.5 py-1 text-xs font-semibold"
                                                    :class="offer.kind === 'ELECTIVE' ? 'bg-amber-500 text-white' : 'text-gray-600'"
                                                    @click="offer.kind = 'ELECTIVE'">Elective</button>
                                        </div>
                                    </template>
                                    <span x-show="!canEdit"
                                          class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                          :class="offer.kind === 'COMPULSORY' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                                          x-text="offer.kind === 'COMPULSORY' ? 'Compulsory' : 'Elective'"></span>

                                    <template x-if="canEdit">
                                        <select class="form-input !w-44 !py-1.5" x-model="offer.teacher_id" :aria-label="'Teacher for ' + offer.class_name">
                                            <option value="">Unassigned</option>
                                            <template x-for="teacher in teachers" :key="teacher.id">
                                                <option :value="teacher.id" x-text="teacher.name"></option>
                                            </template>
                                        </select>
                                    </template>
                                    <span x-show="!canEdit" class="text-sm text-gray-600" x-text="offer.teacher_name || 'Unassigned'"></span>

                                    <button type="button"
                                            x-show="canEdit"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-red-700 hover:bg-red-50"
                                            :aria-label="'Remove ' + offer.class_name"
                                            :title="'Remove ' + offer.class_name"
                                            @click="removeClass(offer)">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>

                    @if ($canEdit)
                        <div class="mt-4 rounded-lg border border-dashed border-gray-200 p-4">
                            <button type="button" class="text-sm font-semibold" style="color: var(--primary)" @click="showAdd = !showAdd" x-text="showAdd ? 'Hide add class' : 'Add class'"></button>
                            <form x-show="showAdd" x-cloak class="mt-3 space-y-3" @submit.prevent="addClasses()">
                                <template x-for="(row, index) in pending" :key="row.key">
                                    <div class="grid gap-3 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto_auto] sm:items-end">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600">Class</label>
                                            <select class="form-input mt-1" x-model="row.class_id">
                                                <option value="">Choose…</option>
                                                <template x-for="schoolClass in classesFor(index)" :key="schoolClass.id">
                                                    <option :value="schoolClass.id" x-text="schoolClass.name"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600">Teacher</label>
                                            <select class="form-input mt-1" x-model="row.teacher_id">
                                                <option value="">Unassigned</option>
                                                <template x-for="teacher in teachers" :key="teacher.id">
                                                    <option :value="teacher.id" x-text="teacher.name"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600">Kind</label>
                                            <div class="mt-1 inline-flex rounded-lg bg-gray-100 p-0.5">
                                                <button type="button" class="rounded-md px-2.5 py-1.5 text-xs font-semibold" :class="row.kind === 'COMPULSORY' ? 'bg-emerald-600 text-white' : 'text-gray-600'" @click="row.kind = 'COMPULSORY'">Compulsory</button>
                                                <button type="button" class="rounded-md px-2.5 py-1.5 text-xs font-semibold" :class="row.kind === 'ELECTIVE' ? 'bg-amber-500 text-white' : 'text-gray-600'" @click="row.kind = 'ELECTIVE'">Elective</button>
                                            </div>
                                        </div>
                                        <button type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-md text-red-700 hover:bg-red-50"
                                                x-show="pending.length > 1"
                                                @click="pending.splice(index, 1)"
                                                aria-label="Remove this row"
                                                title="Remove this row">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </template>
                                <div class="flex items-center justify-between gap-3">
                                    <button type="button"
                                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-black/10 text-gray-700 hover:bg-gray-50 disabled:opacity-40"
                                            @click="addPendingRow()"
                                            :disabled="!anotherClassAvailable"
                                            aria-label="Add another class"
                                            title="Add another class">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                    </button>
                                    <button class="btn-primary" :disabled="adding || !hasPendingClass" x-text="adding ? 'Adding…' : (pendingCount > 1 ? 'Add classes' : 'Add class')"></button>
                                </div>
                            </form>
                            <p class="mt-2 text-xs text-gray-500" x-show="showAdd && !anotherClassAvailable && !hasPendingClass" x-cloak>Every class in this session already offers this subject.</p>
                        </div>
                    @endif
                </div>

                @if ($canEdit)
                    <div class="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-black/5 bg-white/95 px-4 py-3 shadow-md backdrop-blur">
                        <p class="text-sm text-gray-600" x-show="dirty" x-cloak>You have unsaved changes</p>
                        <p class="text-sm text-gray-400" x-show="!dirty">All changes saved</p>
                        <button type="button" class="btn-primary" :disabled="!dirty || saving" @click="save()" x-text="saving ? 'Saving…' : 'Save changes'"></button>
                    </div>
                @endif
            </section>
        </template>

        <div class="card text-sm text-gray-500" x-show="!selected" x-cloak>
            Select a subject to see its department and the classes that offer it in {{ $selectedSession->name }}.
        </div>

        @if ($canEdit)
            <div x-show="askDelete" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="cancelDelete()" @click.self="cancelDelete()">
                <form class="card w-full max-w-md space-y-4" @submit.prevent="confirmDelete()" @click.stop>
                    <h3 class="text-base font-semibold">Delete <span x-text="selected?.name"></span>?</h3>
                    <p class="text-sm text-gray-600">This subject is removed from every class, including its scores, timetable, lesson notes, and assignments. This cannot be undone.</p>
                    <div>
                        <label for="confirm-delete-subject" class="block text-xs font-medium text-gray-600">Type delete to confirm</label>
                        <input id="confirm-delete-subject" x-ref="deleteInput" x-model="deletePhrase" class="form-input mt-1" autocomplete="off" placeholder="delete" aria-label="Type delete to confirm">
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn-secondary" @click="cancelDelete()">Cancel</button>
                        <button class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-40" :disabled="deletePhrase.trim().toLowerCase() !== 'delete' || deleting" x-text="deleting ? 'Deleting…' : 'Delete subject'"></button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</div>
@endif
