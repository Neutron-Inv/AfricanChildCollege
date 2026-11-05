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
                        <th class="py-2 font-medium">Subjects</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-black/5">
                    @foreach ($departments as $d)
                        <tr>
                            <td class="py-2 pr-3 font-medium">{{ $d->name }}</td>
                            <td class="py-2 pr-3 text-gray-600">{{ $d->hod ? trim($d->hod->first_name.' '.$d->hod->last_name) : '—' }}</td>
                            <td class="py-2 text-gray-600">{{ $d->subjects_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($canEdit)
        <form method="POST" action="{{ route('academics.departments.store') }}" class="card space-y-3 self-start">
            @csrf
            <h3 class="text-base font-semibold">Add department</h3>
            <div>
                <label for="dept_name" class="block text-xs font-medium text-gray-600">Name</label>
                <input id="dept_name" name="name" value="{{ old('name') }}" class="form-input mt-1" required maxlength="120">
            </div>
            <div>
                <label for="dept_hod" class="block text-xs font-medium text-gray-600">Head of department (optional)</label>
                <select id="dept_hod" name="hod_id" class="form-input mt-1">
                    <option value="">None</option>
                    @foreach ($teachers as $t) <option value="{{ $t->id }}">{{ trim($t->first_name.' '.$t->last_name) }}</option> @endforeach
                </select>
            </div>
            <button class="btn-primary w-full">Add department</button>
        </form>
    @endif
</div>
