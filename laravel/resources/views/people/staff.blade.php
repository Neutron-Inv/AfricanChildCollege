@php
    $staffLink = fn (array $extra = []) => route('people', array_filter(array_merge([
        'tab' => 'staff',
        'q' => $q,
        'kind' => $staffKind === 'all' ? null : $staffKind,
        'page' => request('page'),
    ], $extra), fn ($v) => $v !== null && $v !== ''));
@endphp

<div class="grid items-start gap-6 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2" x-data="{ open: {{ $errors->hasBag('staff') ? 'true' : 'false' }} }">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex rounded-md border border-gray-200 bg-white p-0.5 text-sm">
                @foreach (['all' => 'All', 'teacher' => 'Teachers', 'other' => 'Other staff'] as $key => $label)
                    <a href="{{ route('people', array_filter(['tab' => 'staff', 'q' => $q, 'kind' => $key === 'all' ? null : $key])) }}"
                       class="rounded px-3 py-1.5 font-medium {{ $staffKind === $key ? 'text-white' : 'text-gray-600 hover:bg-gray-50' }}"
                       @if ($staffKind === $key) style="background: var(--primary)" @endif>{{ $label }}</a>
                @endforeach
            </div>
            @if ($canCreateStaff)
                <button type="button" class="btn-primary" @click="open = !open" x-text="open ? 'Close' : 'Add staff'"></button>
            @endif
        </div>

        @if ($canCreateStaff)
            <form x-show="open" x-cloak method="POST" action="{{ route('people.staff.store') }}" class="card space-y-4">
                @csrf
                @php $se = fn ($f) => $errors->getBag('staff')->get($f); @endphp
                <h2 class="text-base font-semibold">Add staff member</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="st_role" value="Role" />
                        <select id="st_role" name="role" class="form-input mt-1" required>
                            @foreach ($staffRoles as $r)
                                <option value="{{ $r->value }}" @selected(old('role', 'TEACHER') === $r->value)>{{ $r->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$se('role')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="st_index" value="Index number" />
                        <x-text-input id="st_index" name="index_number" class="mt-1" :value="old('index_number')" required />
                        <x-input-error :messages="$se('index_number')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="st_first" value="First name" />
                        <x-text-input id="st_first" name="first_name" class="mt-1" :value="old('first_name')" required />
                        <x-input-error :messages="$se('first_name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="st_last" value="Last name" />
                        <x-text-input id="st_last" name="last_name" class="mt-1" :value="old('last_name')" required />
                        <x-input-error :messages="$se('last_name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="st_email" value="Email (login)" />
                        <x-text-input id="st_email" type="email" name="email" class="mt-1" :value="old('email')" required />
                        <x-input-error :messages="$se('email')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="st_password" value="Initial password" />
                        <x-text-input id="st_password" type="password" name="password" class="mt-1" autocomplete="new-password" required />
                        <x-input-error :messages="$se('password')" class="mt-1" />
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_teacher" value="0">
                    <input type="checkbox" name="is_teacher" value="1" class="rounded border-gray-300" @checked(old('is_teacher'))>
                    Teaches classes (always on for the Teacher role)
                </label>
                <p class="text-xs text-gray-500">The new user must change this password at first login.</p>
                <x-primary-button>Create staff</x-primary-button>
            </form>
        @endif

        <form method="GET" action="{{ route('people') }}" class="card flex flex-wrap items-end gap-3 !p-3">
            <input type="hidden" name="tab" value="staff">
            @if ($staffKind !== 'all') <input type="hidden" name="kind" value="{{ $staffKind }}"> @endif
            <div class="min-w-[12rem] flex-1">
                <label for="staff_q" class="text-xs text-gray-500">Search name, index or email</label>
                <input id="staff_q" type="search" name="q" value="{{ $q }}" class="form-input mt-1" placeholder="Search...">
            </div>
            <button class="btn-secondary">Search</button>
        </form>

        <div class="card overflow-x-auto !p-0">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Index</th>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2">Email</th>
                        <th class="px-4 py-2">Role</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($staff as $member)
                        @php $isSelected = $selectedStaff?->id === $member->id; @endphp
                        <tr class="cursor-pointer hover:bg-gray-50 {{ $isSelected ? 'bg-[color:color-mix(in_srgb,var(--primary)_8%,white)]' : '' }}"
                            @if ($isSelected) style="box-shadow: inset 3px 0 0 var(--primary)" @endif
                            onclick="window.location.href='{{ $staffLink(['staff' => $member->id]) }}'">
                            <td class="px-4 py-2 font-mono text-xs">{{ $member->index_number }}</td>
                            <td class="px-4 py-2 font-medium">
                                <a href="{{ $staffLink(['staff' => $member->id]) }}" onclick="event.stopPropagation()" @if ($isSelected) aria-current="true" @endif>{{ $member->full_name }}</a>
                            </td>
                            <td class="px-4 py-2 text-gray-600">{{ $member->email ?: '—' }}</td>
                            <td class="px-4 py-2">{{ $member->user?->role?->label() ?? ($member->is_teacher ? 'Teacher' : 'Staff') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No staff yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-400">{{ $staff->total() }} staff</p>
        {{ $staff->links() }}
    </div>

    <aside class="lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto">
        @if ($selectedStaff)
            @php
                $member = $selectedStaff;
                $fields = [
                    'Index' => $member->index_number,
                    'Email' => $member->email,
                    'Phone' => $member->phone,
                    'Gender' => $member->gender,
                    'Address' => $member->address,
                    'Role' => $member->user?->role?->label(),
                ];
                $taught = $member->classSubjects
                    ->sortBy(fn ($cs) => ($cs->class?->display_name ?? '').' '.($cs->subject?->name ?? ''))
                    ->values();
            @endphp
            <div class="card space-y-5">
                <div>
                    <h2 class="text-base font-semibold">{{ $member->full_name }}</h2>
                    <p class="text-xs text-gray-500">
                        {{ $member->is_teacher ? 'Teacher' : 'Staff' }}
                        @if ($currentSession)
                            · {{ $currentSession->name }}
                        @endif
                    </p>
                </div>
                <dl class="grid grid-cols-3 gap-x-3 gap-y-2 text-sm">
                    @foreach ($fields as $label => $value)
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="col-span-2 break-words">{{ filled($value) ? $value : '—' }}</dd>
                    @endforeach
                </dl>
                <div class="border-t border-gray-100 pt-4">
                    <h3 class="mb-2 text-sm font-semibold">Teaching{{ $currentSession ? ' in '.$currentSession->name : '' }}</h3>
                    <ul class="space-y-2 text-sm">
                        @forelse ($taught as $cs)
                            <li class="rounded-md bg-gray-50 px-3 py-2">
                                <div class="font-medium">{{ $cs->class?->display_name }}</div>
                                <div class="text-xs text-gray-600">{{ $cs->subject?->name }} · {{ $cs->kind === 'COMPULSORY' ? 'Compulsory' : 'Elective' }}</div>
                            </li>
                        @empty
                            <li class="text-gray-500">No class subjects this session.</li>
                        @endforelse
                    </ul>
                </div>
                <div class="border-t border-gray-100 pt-4">
                    <h3 class="mb-2 text-sm font-semibold">Form teacher</h3>
                    <ul class="space-y-1 text-sm">
                        @forelse ($member->formClasses as $class)
                            <li>{{ $class->display_name }}</li>
                        @empty
                            <li class="text-gray-500">Not a form teacher this session.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @else
            <div class="card text-sm text-gray-500">Select a staff member to see their profile.</div>
        @endif
    </aside>
</div>
