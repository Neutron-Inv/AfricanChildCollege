@php
    $fields = [
        'Index' => $student->index_number,
        'Full name' => $student->full_name,
        'Gender' => $student->gender,
        'Phone' => $student->phone,
        'Email' => $student->email,
        'Address' => $student->address,
        'Birth date' => $student->birth_date?->format('j M Y'),
        'Status' => $student->departure === 'GRADUATED' ? 'Graduated' : ($student->status === 'ACTIVE' ? 'Active' : 'Left'),
    ];
    $className = optional($student->enrolments->first()?->class)->display_name;
    $studentStatus = $student->departure === 'GRADUATED' ? 'GRADUATED' : $student->status;
    $editingStudent = $errors->hasBag('student');
    $sVal = fn (string $field, mixed $default = null) => $editingStudent ? old($field, $default) : $default;
@endphp

<div class="card space-y-5" x-data="{ editing: {{ $editingStudent ? 'true' : 'false' }} }">
    <div class="flex items-start justify-between gap-2">
        <div>
            <h2 class="text-base font-semibold">{{ $student->full_name }}</h2>
            <p class="text-xs text-gray-500">
                {{ $className ?: 'No class' }}
                @if ($currentSession)
                    · {{ $currentSession->name }}
                @endif
            </p>
        </div>
        @if ($canEditStudents)
            <div class="flex shrink-0 items-center gap-1" x-show="!editing">
                <button type="button" class="group relative inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-600 hover:bg-black/5" @click="editing = true" title="Edit student" aria-label="Edit student">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    <span class="pointer-events-none absolute right-0 top-full z-10 mt-1 whitespace-nowrap rounded bg-gray-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow group-hover:opacity-100">Edit student</span>
                </button>
                <form method="POST" action="{{ route('people.students.destroy', $student->id) }}" onsubmit="return confirm(@js('Delete '.$student->full_name.' ('.$student->index_number.')? This removes their login, class enrolments, scores, attendance and invoices. This cannot be undone.'))">
                    @csrf @method('DELETE')
                    <button type="submit" class="group relative inline-flex h-8 w-8 items-center justify-center rounded-md text-red-700 hover:bg-red-50" title="Delete student" aria-label="Delete student">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        <span class="pointer-events-none absolute right-0 top-full z-10 mt-1 whitespace-nowrap rounded bg-gray-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow group-hover:opacity-100">Delete student</span>
                    </button>
                </form>
            </div>
        @endif
    </div>

    @if ($canEditStudents)
        <form method="POST" action="{{ route('people.students.update', $student->id) }}" x-show="editing" x-cloak class="space-y-3">
            @csrf @method('PUT')
            <div>
                <x-input-label for="e_index" value="Index number" />
                <x-text-input id="e_index" name="index_number" class="mt-1" :value="$sVal('index_number', $student->index_number)" required />
                <x-input-error :messages="$errors->getBag('student')->get('index_number')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="e_name" value="Full name" />
                <x-text-input id="e_name" name="full_name" class="mt-1" :value="$sVal('full_name', $student->full_name)" required />
                <x-input-error :messages="$errors->getBag('student')->get('full_name')" class="mt-1" />
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="e_gender" value="Gender" />
                    <select id="e_gender" name="gender" class="form-input mt-1">
                        <option value="">-</option>
                        @foreach (['Male', 'Female'] as $g)
                            <option value="{{ $g }}" @selected($sVal('gender', $student->gender) === $g)>{{ $g }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="e_status" value="Status" />
                    <select id="e_status" name="status" class="form-input mt-1">
                        @foreach (['ACTIVE' => 'Active', 'LEFT' => 'Left', 'GRADUATED' => 'Graduated'] as $key => $label)
                            <option value="{{ $key }}" @selected($sVal('status', $studentStatus) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="e_phone" value="Phone" />
                    <x-text-input id="e_phone" name="phone" class="mt-1" :value="$sVal('phone', $student->phone)" />
                </div>
                <div>
                    <x-input-label for="e_birth" value="Birth date" />
                    <x-text-input id="e_birth" type="date" name="birth_date" class="mt-1" :value="$sVal('birth_date', $student->birth_date?->format('Y-m-d'))" :max="now()->toDateString()" />
                    <x-input-error :messages="$errors->getBag('student')->get('birth_date')" class="mt-1" />
                </div>
            </div>
            <div>
                <x-input-label for="e_email" value="Email" />
                <x-text-input id="e_email" type="email" name="email" class="mt-1" :value="$sVal('email', $student->email)" />
                <x-input-error :messages="$errors->getBag('student')->get('email')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="e_address" value="Address" />
                <x-text-input id="e_address" name="address" class="mt-1" :value="$sVal('address', $student->address)" />
            </div>
            @if ($canSetPasswords ?? false)
                <div>
                    <x-input-label for="e_password" value="New password" />
                    <x-text-input id="e_password" type="password" name="password" class="mt-1" autocomplete="new-password" />
                    <p class="mt-1 text-xs text-gray-500">Leave blank to keep the current password. A new one can be used to sign in straight away.</p>
                    <x-input-error :messages="$errors->getBag('student')->get('password')" class="mt-1" />
                </div>
            @endif
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="editing = false">Cancel</button>
                <x-primary-button>Save student</x-primary-button>
            </div>
        </form>
    @endif

    <dl class="grid grid-cols-3 gap-x-3 gap-y-2 text-sm" @if ($canEditStudents) x-show="!editing" @endif>
        @foreach ($fields as $label => $value)
            <dt class="text-gray-500">{{ $label }}</dt>
            <dd class="col-span-2 break-words">{{ filled($value) ? $value : '—' }}</dd>
        @endforeach
    </dl>

    @if ($canEditStudents && $student->status === 'ACTIVE' && ($currentSession ?? null) && ! $student->enrolments->contains(fn ($enrolment) => $enrolment->status === 'ACTIVE') && ($classes ?? collect())->isNotEmpty())
        <form method="POST" action="{{ route('enrolment.place') }}" class="space-y-3 border-t border-gray-100 pt-4">
            @csrf
            <input type="hidden" name="return_to" value="people">
            <input type="hidden" name="student_id" value="{{ $student->id }}">
            <h3 class="text-sm font-semibold">Place in a class</h3>
            <p class="text-xs text-gray-500">This student has no class in {{ $currentSession->name }}. Compulsory subjects are added with the class.</p>
            <div>
                <x-input-label for="place_class" value="Class" />
                <select id="place_class" name="class_id" class="form-input mt-1" required>
                    <option value="">Choose a class</option>
                    @foreach ($classes as $class)
                        <option value="{{ $class->id }}" @selected(old('class_id') === $class->id)>{{ $class->display_name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('class_id')" class="mt-1" />
            </div>
            <x-primary-button>Place in class</x-primary-button>
        </form>
    @endif

    <div class="border-t border-gray-100 pt-4">
        <h3 class="mb-2 text-sm font-semibold">Guardians</h3>
        <ul class="space-y-2 text-sm">
            @forelse ($student->studentGuardians as $link)
                @php $g = $link->guardian; @endphp
                @continue(! $g)
                <li class="rounded-md bg-gray-50 px-3 py-2">
                    <div class="font-medium">{{ $g->full_name }} <span class="text-xs font-normal text-gray-500">({{ $link->relationship }})</span></div>
                    <div class="text-xs text-gray-600">{{ collect([$g->phone, $g->email])->filter()->implode(' · ') ?: 'No contact details' }}</div>
                </li>
            @empty
                <li class="text-gray-500">No guardians linked.</li>
            @endforelse
        </ul>
    </div>

    @if ($canEditStudents)
        <form method="POST" action="{{ route('people.students.guardians.store', $student->id) }}"
              class="space-y-3 border-t border-gray-100 pt-4">
            @csrf
            <h3 class="text-sm font-semibold">Add guardian</h3>
            @php
                $ge = fn ($f) => $errors->getBag('guardian')->get($f);
                $gOld = fn ($f) => $errors->hasBag('guardian') ? old($f) : null;
            @endphp

            <div>
                <x-input-label for="g_name" value="Full name" />
                <x-text-input id="g_name" name="full_name" class="mt-1" :value="$gOld('full_name')" required />
                <x-input-error :messages="$ge('full_name')" class="mt-1" />
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="g_phone" value="Phone" />
                    <x-text-input id="g_phone" name="phone" class="mt-1" :value="$gOld('phone')" />
                    <x-input-error :messages="$ge('phone')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="g_rel" value="Relationship" />
                    <x-text-input id="g_rel" name="relationship" class="mt-1" placeholder="parent" :value="$gOld('relationship')" />
                    <x-input-error :messages="$ge('relationship')" class="mt-1" />
                </div>
            </div>
            <div>
                <x-input-label for="g_email" value="Email" />
                <x-text-input id="g_email" type="email" name="email" class="mt-1" :value="$gOld('email')" />
                <x-input-error :messages="$ge('email')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="g_password" value="Password (optional, enables parent login)" />
                <x-text-input id="g_password" type="password" name="password" class="mt-1" autocomplete="new-password" />
                <x-input-error :messages="$ge('password')" class="mt-1" />
            </div>
            <x-primary-button>Add guardian</x-primary-button>
        </form>
    @endif
</div>
