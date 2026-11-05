{{-- One profile dialog for every student name on the exams page. --}}
<div x-data="{ student: null }" @student-profile.window="student = $event.detail" @keydown.escape.window="student = null">
    <div
        x-show="student"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        @click.self="student = null"
        role="dialog"
        aria-modal="true"
        aria-label="Student details"
    >
        <div class="card max-h-[85vh] w-full max-w-lg overflow-y-auto !p-5" @click.stop>
            <div class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold" x-text="student?.name"></h3>
                    <p class="mt-1 text-xs text-gray-500" x-text="[student?.index, student?.className, student?.session].filter(Boolean).join(' · ')"></p>
                </div>
                <button type="button" class="btn-secondary !py-1.5" @click="student = null">Close</button>
            </div>

            <dl class="grid grid-cols-3 gap-x-3 gap-y-2 text-sm">
                <template x-for="row in [
                    ['Gender', student?.gender],
                    ['Phone', student?.phone],
                    ['Email', student?.email],
                    ['Address', student?.address],
                    ['Birth date', student?.birthDate],
                    ['Status', student?.status],
                ]" :key="row[0]">
                    <div class="contents">
                        <dt class="text-gray-500" x-text="row[0]"></dt>
                        <dd class="col-span-2 break-words" x-text="row[1] || '—'"></dd>
                    </div>
                </template>
            </dl>

            <div class="mt-4 border-t border-black/5 pt-4">
                <h4 class="mb-2 text-sm font-semibold">Guardians</h4>
                <p class="text-sm text-gray-500" x-show="!(student?.guardians || []).length">No guardians linked.</p>
                <ul class="space-y-2 text-sm">
                    <template x-for="guardian in student?.guardians || []" :key="guardian.name + (guardian.phone || '')">
                        <li class="rounded-md bg-gray-50 px-3 py-2">
                            <div class="font-medium">
                                <span x-text="guardian.name"></span>
                                <span class="text-xs font-normal text-gray-500" x-show="guardian.relationship" x-text="'(' + guardian.relationship + ')'"></span>
                            </div>
                            <div class="text-xs text-gray-600" x-text="[guardian.phone, guardian.email].filter(Boolean).join(' · ') || 'No contact details'"></div>
                        </li>
                    </template>
                </ul>
            </div>

            <div class="mt-4" x-show="student?.scoresUrl">
                <a :href="student?.scoresUrl" class="text-sm font-medium hover:underline" style="color: var(--primary)">All subjects</a>
            </div>
        </div>
    </div>
</div>
