<x-school-layout title="People">
    @php
        $tabs = ($teacherView ?? false)
            ? ['students' => 'Students', 'guardians' => 'Guardians']
            : ['students' => 'Students', 'staff' => 'Staff', 'guardians' => 'Find a guardian'];
    @endphp

    <div class="mb-5">
        <h2 class="text-xl font-semibold">People</h2>
        <p class="text-sm text-gray-500">
            @if ($teacherView ?? false)
                Your class, the students taking your subjects, and their guardians
            @else
                Students, staff and guardians
            @endif
            @if ($currentSession)
                · {{ $currentSession->name }}
            @endif
        </p>
    </div>

    <nav class="mb-5 flex gap-1 overflow-x-auto border-b border-black/10" aria-label="People sections">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('people', ['tab' => $key]) }}"
               class="-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition {{ $tab === $key ? 'text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-800' }}"
               @if ($tab === $key) style="border-color: var(--primary)" aria-current="page" @endif>
                {{ $label }}
            </a>
        @endforeach
    </nav>

    @if ($tab === 'students')
        @include('people.students')
    @elseif ($tab === 'staff')
        @include('people.staff')
    @else
        @include('people.guardians')
    @endif
</x-school-layout>
