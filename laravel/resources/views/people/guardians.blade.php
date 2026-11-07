@php
    $guardianLink = fn ($guardian) => route('people', array_filter([
        'tab' => 'guardians',
        'q' => $q,
        'guardian' => $guardian->id,
    ]));
@endphp

<div class="grid items-start gap-6 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <form method="GET" action="{{ route('people') }}" class="card flex flex-wrap items-end gap-3 !p-3">
            <input type="hidden" name="tab" value="guardians">
            <div class="min-w-[12rem] flex-1">
                <label for="gq" class="text-xs text-gray-500">Search by name, phone or email</label>
                <input id="gq" type="search" name="q" value="{{ $q }}" class="form-input mt-1" placeholder="Search guardians..." autofocus>
            </div>
            <x-primary-button>Search</x-primary-button>
        </form>

        @if ($q === '')
            <div class="card text-sm text-gray-500">Enter a name, phone number or email to find a guardian.</div>
        @else
            <div class="card overflow-x-auto !p-0">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2">Name</th>
                            <th class="px-4 py-2">Phone</th>
                            <th class="px-4 py-2">Email</th>
                            <th class="px-4 py-2">Students</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($guardians as $guardian)
                            @php $isSelected = $selectedGuardian?->id === $guardian->id; @endphp
                            <tr class="cursor-pointer hover:bg-gray-50 {{ $isSelected ? 'bg-[color:color-mix(in_srgb,var(--primary)_8%,white)]' : '' }}"
                                @if ($isSelected) style="box-shadow: inset 3px 0 0 var(--primary)" @endif
                                onclick="window.location.href='{{ $guardianLink($guardian) }}'">
                                <td class="px-4 py-2 font-medium">
                                    <a href="{{ $guardianLink($guardian) }}" onclick="event.stopPropagation()" @if ($isSelected) aria-current="true" @endif>{{ $guardian->full_name }}</a>
                                </td>
                                <td class="px-4 py-2 text-gray-600">{{ $guardian->phone ?: '—' }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $guardian->email ?: '—' }}</td>
                                <td class="px-4 py-2 text-gray-600">
                                    {{ $guardian->students->pluck('full_name')->implode(', ') ?: 'None' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No guardians match "{{ $q }}".</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <aside class="lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto">
        @if ($selectedGuardian)
            @php $guardian = $selectedGuardian; @endphp
            @php $settingPassword = $errors->hasBag('guardianPassword'); @endphp
            <div class="card space-y-5" x-data="{ settingPassword: {{ $settingPassword ? 'true' : 'false' }} }">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold">{{ $guardian->full_name }}</h2>
                        <p class="text-xs text-gray-500">{{ collect([$guardian->phone, $guardian->email])->filter()->implode(' · ') ?: 'No contact details' }}</p>
                    </div>
                    @if ($canSetPasswords ?? false)
                        <button type="button" class="group relative inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-gray-600 hover:bg-black/5" x-show="!settingPassword" @click="settingPassword = true" title="Set password" aria-label="Set password">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                            <span class="pointer-events-none absolute right-0 top-full z-10 mt-1 whitespace-nowrap rounded bg-gray-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow group-hover:opacity-100">Set password</span>
                        </button>
                    @endif
                </div>
                @if ($canSetPasswords ?? false)
                    <form method="POST" action="{{ route('people.guardians.password', $guardian->id) }}" x-show="settingPassword" x-cloak class="space-y-3">
                        @csrf @method('PUT')
                        <input type="hidden" name="q" value="{{ $q }}">
                        <div>
                            <x-input-label for="gp_password" value="New password" />
                            <x-text-input id="gp_password" type="password" name="password" class="mt-1" autocomplete="new-password" required />
                            <p class="mt-1 text-xs text-gray-500">
                                @if ($guardian->user)
                                    They can sign in with this straight away.
                                @else
                                    This creates their parent login. They can sign in with it straight away.
                                @endif
                            </p>
                            <x-input-error :messages="$errors->getBag('guardianPassword')->get('password')" class="mt-1" />
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="btn-secondary" @click="settingPassword = false">Cancel</button>
                            <x-primary-button>Save password</x-primary-button>
                        </div>
                    </form>
                @endif
                <dl class="grid grid-cols-3 gap-x-3 gap-y-2 text-sm">
                    @foreach (['Phone' => $guardian->phone, 'Email' => $guardian->email, 'Address' => $guardian->address] as $label => $value)
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="col-span-2 break-words">{{ filled($value) ? $value : '—' }}</dd>
                    @endforeach
                </dl>
                <div class="border-t border-gray-100 pt-4">
                    <h3 class="mb-2 text-sm font-semibold">Linked students</h3>
                    <ul class="space-y-2 text-sm">
                        @forelse ($guardian->students as $student)
                            <li class="rounded-md bg-gray-50 px-3 py-2">
                                <a href="{{ route('people', array_filter(['tab' => 'students', 'status' => ($teacherView ?? false) ? null : ($student->departure === 'GRADUATED' ? 'GRADUATED' : ($student->status === 'LEFT' ? 'LEFT' : null)), 'scope' => ($teacherView ?? false) ? 'all' : null, 'student' => $student->id])) }}" class="font-medium link-primary hover:underline">{{ $student->full_name }}</a>
                                <div class="text-xs text-gray-500">{{ $student->index_number }} · {{ $student->pivot->relationship }}</div>
                            </li>
                        @empty
                            <li class="text-gray-500">None</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @elseif ($q !== '')
            <div class="card text-sm text-gray-500">Select a guardian to see their profile.</div>
        @endif
    </aside>
</div>
