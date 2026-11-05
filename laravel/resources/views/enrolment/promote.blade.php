<x-school-layout title="Promote session">
    <div class="mb-5 flex items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold">Promote session</h2>
            <p class="text-sm text-gray-500">Move every active student from one session into classes of another.</p>
        </div>
        <a href="{{ route('enrolment', array_filter(['session' => $from?->id])) }}" class="btn-secondary">Back to enrolment</a>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('enrolment.promote') }}" class="card mb-4 flex flex-wrap items-end gap-3 !p-3">
        <div>
            <label for="from" class="text-xs text-gray-500">From session</label>
            <select id="from" name="from" class="form-input mt-1">
                @foreach ($sessions as $s)
                    <option value="{{ $s->id }}" @selected($s->id === $from?->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="to" class="text-xs text-gray-500">To session</label>
            <select id="to" name="to" class="form-input mt-1">
                <option value="">Choose a session</option>
                @foreach ($sessions as $s)
                    <option value="{{ $s->id }}" @selected($s->id === $to?->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-secondary">Show class mapping</button>
    </form>

    @if ($from && $to)
        <form method="POST" action="{{ route('enrolment.promote.store') }}" class="card space-y-4 !p-0"
              onsubmit="return confirm('Promote students from {{ $from->name }} to {{ $to->name }}? Their {{ $from->name }} enrolments will be marked as left.')">
            @csrf
            <input type="hidden" name="from_session_id" value="{{ $from->id }}">
            <input type="hidden" name="to_session_id" value="{{ $to->id }}">

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2">{{ $from->name }} class</th>
                            <th class="px-4 py-2">Active students</th>
                            <th class="px-4 py-2">Promote into ({{ $to->name }})</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($plan as $row)
                            <tr>
                                <td class="px-4 py-2 font-medium">{{ $row['class']->display_name }}</td>
                                <td class="px-4 py-2">{{ $row['students'] }}</td>
                                <td class="px-4 py-2">
                                    <select name="mapping[{{ $row['class']->id }}]" class="form-input" aria-label="Target class for {{ $row['class']->display_name }}">
                                        <option value="">Do not promote</option>
                                        @foreach ($targets as $target)
                                            <option value="{{ $target->id }}" @selected(old('mapping.'.$row['class']->id, $row['suggested']) === $target->id)>{{ $target->display_name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-8 text-center text-gray-500">{{ $from->name }} has no active students to promote.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($plan)
                <div class="flex items-center justify-between gap-3 px-4 pb-4">
                    <p class="text-xs text-gray-500">
                        Compulsory subjects of each target class are attached, and electives are kept when the target class still offers them.
                        Old enrolments are marked as left; students already placed in {{ $to->name }} are skipped.
                    </p>
                    <x-primary-button>Promote students</x-primary-button>
                </div>
            @endif
        </form>
    @else
        <div class="card text-sm text-gray-500">Choose a source and a different target session to map classes.</div>
    @endif
</x-school-layout>
