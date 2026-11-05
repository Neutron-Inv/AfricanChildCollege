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
            <div class="card space-y-5">
                <div>
                    <h2 class="text-base font-semibold">{{ $guardian->full_name }}</h2>
                    <p class="text-xs text-gray-500">{{ collect([$guardian->phone, $guardian->email])->filter()->implode(' · ') ?: 'No contact details' }}</p>
                </div>
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
                                <a href="{{ route('people', array_filter(['tab' => 'students', 'status' => ($teacherView ?? false) || $student->status !== 'LEFT' ? null : 'LEFT', 'scope' => ($teacherView ?? false) ? 'all' : null, 'student' => $student->id])) }}" class="font-medium link-primary hover:underline">{{ $student->full_name }}</a>
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
