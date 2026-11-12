@php
    $active = $selected->status === 'ACTIVE';
    $compulsory = $subjects->where('kind', 'COMPULSORY');
@endphp

<div class="card space-y-5">
    <div>
        <h2 class="text-base font-semibold">{{ $selected->student?->full_name }}</h2>
        <p class="text-xs text-gray-500">
            {{ $selected->student?->index_number }} &middot; {{ $selected->class?->display_name }} &middot; {{ $selected->session?->name }}
            &middot; {{ $selected->outcome === 'GRADUATED' ? 'Graduated' : ($active ? 'Active' : 'Left') }}
        </p>
    </div>

    @if ($active && ($canManage ?? true))
        {{-- Move --}}
        <form method="POST" action="{{ route('enrolment.move', $selected->id) }}" class="space-y-2 border-t border-gray-100 pt-4">
            @csrf @method('PUT')
            <h3 class="text-sm font-semibold">Move to another class</h3>
            <select name="class_id" class="form-input" required aria-label="New class">
                <option value="">Choose a class</option>
                @foreach ($classes->where('id', '!=', $selected->class_id) as $class)
                    <option value="{{ $class->id }}">{{ $class->display_name }}</option>
                @endforeach
            </select>
            <p class="text-xs text-gray-500">Same session only. Electives are kept if the new class offers them as electives.</p>
            <button class="btn-secondary">Move student</button>
        </form>
    @endif

    @if ($active)
        {{-- Electives --}}
        <form method="POST" action="{{ route('enrolment.electives', $selected->id) }}" class="space-y-2 border-t border-gray-100 pt-4">
            @csrf @method('PUT')
            <h3 class="text-sm font-semibold">Electives</h3>
            @forelse ($electives as $offer)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="class_subject_ids[]" value="{{ $offer->id }}" @checked($offer->taken) class="rounded border-gray-300">
                    {{ $offer->subject?->name }}
                </label>
            @empty
                <p class="text-sm text-gray-500">This class has no elective subjects.</p>
            @endforelse
            @if ($electives->isNotEmpty())
                <button class="btn-secondary">Save electives</button>
            @endif
        </form>
    @endif

    {{-- Subjects --}}
    <div class="border-t border-gray-100 pt-4">
        <h3 class="mb-2 text-sm font-semibold">Subjects ({{ $subjects->count() }})</h3>
        <ul class="space-y-1 text-sm">
            @forelse ($subjects as $cs)
                <li class="flex justify-between rounded-md bg-gray-50 px-3 py-1.5">
                    <span>{{ $cs->subject?->name }}</span>
                    <span class="text-xs text-gray-500">{{ ucfirst(strtolower($cs->kind)) }}</span>
                </li>
            @empty
                <li class="text-gray-500">No subjects yet.</li>
            @endforelse
        </ul>
    </div>

    @if ($active && ($canManage ?? true))
        <form method="POST" action="{{ route('enrolment.leave', $selected->id) }}" class="border-t border-gray-100 pt-4"
              onsubmit="return confirm('Mark this student as left for {{ $selected->session?->name }}?')">
            @csrf
            <button class="text-sm font-medium text-red-700 hover:underline">Mark as left</button>
        </form>
    @elseif ($canManage ?? true)
        <form method="POST" action="{{ route('enrolment.active', $selected->id) }}" class="border-t border-gray-100 pt-4"
              onsubmit="return confirm('Mark this student as active for {{ $selected->session?->name }}? They go back on the active roll, and become left in every other session.')">
            @csrf
            <button class="text-sm font-medium hover:underline" style="color: var(--primary)">Mark as active</button>
        </form>
    @endif
</div>
