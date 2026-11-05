@php
    $fields = [
        'Index' => $student->index_number,
        'Full name' => $student->full_name,
        'Gender' => $student->gender,
        'Phone' => $student->phone,
        'Email' => $student->email,
        'Address' => $student->address,
        'Birth date' => $student->birth_date?->format('j M Y'),
        'Status' => $student->status === 'ACTIVE' ? 'Active' : 'Left',
    ];
    $className = optional($student->enrolments->first()?->class)->display_name;
@endphp

<div class="card space-y-5">
    <div>
        <h2 class="text-base font-semibold">{{ $student->full_name }}</h2>
        <p class="text-xs text-gray-500">
            {{ $className ?: 'No class' }}
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
