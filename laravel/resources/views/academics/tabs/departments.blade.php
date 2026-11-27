<div class="grid gap-6 lg:grid-cols-3">
    <div class="card lg:col-span-2">
        <h3 class="mb-3 text-base font-semibold">Departments <span class="text-sm font-normal text-gray-500">({{ $departments->count() }})</span></h3>
        @if ($departments->isEmpty())
            <p class="text-sm text-gray-500">No departments yet.</p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="py-2 pr-3 font-medium">Department</th>
                        <th class="py-2 pr-3 font-medium">Head of department</th>
                        <th class="py-2 pr-3 font-medium">Subjects</th>
                        @if ($canEdit)
                            <th class="py-2 text-right font-medium"><span class="sr-only">Actions</span></th>
                        @endif
                    </tr>
                </thead>
                @foreach ($departments as $d)
                    @php $openEditor = old('editing_department') === $d->id; @endphp
                    <tbody class="divide-y divide-black/5" x-data="{ editing: {{ $openEditor ? 'true' : 'false' }}, deleting: false, deletePhrase: '' }">
                        <tr x-show="!editing">
                            <td class="py-2 pr-3 font-medium">{{ $d->name }}</td>
                            <td class="py-2 pr-3 text-gray-600">{{ $d->hod ? trim($d->hod->first_name.' '.$d->hod->last_name) : '—' }}</td>
                            <td class="py-2 text-gray-600">{{ $d->subjects_count }}</td>
                            @if ($canEdit)
                                <td class="py-2 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-600 hover:bg-black/5" @click="editing = true" title="Edit" aria-label="Edit department">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>
                                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-red-700 hover:bg-red-50" @click="deletePhrase = ''; deleting = true; $nextTick(() => $refs.deleteInput?.focus())" title="Delete" aria-label="Delete department">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                        @if ($canEdit)
                            <tr x-show="editing" x-cloak>
                                <td colspan="4" class="py-3">
                                    <form method="POST" action="{{ route('academics.departments.update', $d->id) }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="editing_department" value="{{ $d->id }}">
                                        <div>
                                            <label for="dept-name-{{ $d->id }}" class="block text-xs font-medium text-gray-600">Name</label>
                                            <input id="dept-name-{{ $d->id }}" name="name" value="{{ $openEditor ? old('name', $d->name) : $d->name }}" class="form-input mt-1" required maxlength="120">
                                        </div>
                                        <div>
                                            <label for="dept-hod-{{ $d->id }}" class="block text-xs font-medium text-gray-600">Head of department</label>
                                            <select id="dept-hod-{{ $d->id }}" name="hod_id" class="form-input mt-1">
                                                <option value="">None</option>
                                                @foreach ($teachers as $t)
                                                    <option value="{{ $t->id }}" @selected(($openEditor ? old('hod_id', $d->hod_id) : $d->hod_id) === $t->id)>{{ trim($t->first_name.' '.$t->last_name) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="flex gap-2">
                                            <button type="button" class="btn-secondary" @click="editing = false">Cancel</button>
                                            <button class="btn-primary">Save</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <tr x-show="deleting" x-cloak>
                                <td colspan="4" class="p-0">
                                    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="deleting = false" @click.self="deleting = false">
                                        <form method="POST" action="{{ route('academics.departments.destroy', $d->id) }}" class="card w-full max-w-md space-y-4" @click.stop>
                                            @csrf @method('DELETE')
                                            <h3 class="text-base font-semibold">Delete {{ $d->name }}?</h3>
                                            <p class="text-sm text-gray-600">Subjects in this department stay. They will no longer belong to a department. This cannot be undone.</p>
                                            <div>
                                                <label for="confirm-delete-department-{{ $d->id }}" class="block text-xs font-medium text-gray-600">Type delete to confirm</label>
                                                <input id="confirm-delete-department-{{ $d->id }}" x-ref="deleteInput" x-model="deletePhrase" class="form-input mt-1" autocomplete="off" placeholder="delete" aria-label="Type delete to confirm">
                                            </div>
                                            <div class="flex justify-end gap-2">
                                                <button type="button" class="btn-secondary" @click="deleting = false; deletePhrase = ''">Cancel</button>
                                                <button class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-40" :disabled="deletePhrase.trim().toLowerCase() !== 'delete'">Delete department</button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @endforeach
            </table>
        @endif
    </div>

    @if ($canEdit)
        <form method="POST" action="{{ route('academics.departments.store') }}" class="card space-y-3 self-start">
            @csrf
            <h3 class="text-base font-semibold">Add department</h3>
            <div>
                <label for="dept_name" class="block text-xs font-medium text-gray-600">Name</label>
                <input id="dept_name" name="name" value="{{ old('editing_department') ? '' : old('name') }}" class="form-input mt-1" required maxlength="120">
            </div>
            <div>
                <label for="dept_hod" class="block text-xs font-medium text-gray-600">Head of department (optional)</label>
                <select id="dept_hod" name="hod_id" class="form-input mt-1">
                    <option value="">None</option>
                    @foreach ($teachers as $t)
                        <option value="{{ $t->id }}" @selected(! old('editing_department') && old('hod_id') === $t->id)>{{ trim($t->first_name.' '.$t->last_name) }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn-primary w-full">Add department</button>
        </form>
    @endif
</div>
