@php
    $departmentsJson = $departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values();
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
        subjects: @js($catalogue),
        departments: @js($departmentsJson),
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
                    <h3 class="text-xl font-semibold" x-text="canEdit ? 'Edit subject' : selected.name"></h3>
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
                    <p class="mb-3 text-xs text-gray-500">Compulsory and elective are set on the class, not here.</p>
                    <div x-show="!selected.offerings.length" class="rounded-lg border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500">
                        Not offered in {{ $selectedSession->name }}.
                    </div>
                    <div class="space-y-2" x-show="selected.offerings.length">
                        <template x-for="(offer, index) in selected.offerings" :key="index">
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-black/5 bg-gray-50/60 px-3 py-2.5">
                                <div>
                                    <p class="text-sm font-semibold" x-text="offer.class_name"></p>
                                </div>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                      :class="offer.kind === 'COMPULSORY' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                                      x-text="offer.kind === 'COMPULSORY' ? 'Compulsory' : 'Elective'"></span>
                            </div>
                        </template>
                    </div>
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
    </div>
</div>
@endif
