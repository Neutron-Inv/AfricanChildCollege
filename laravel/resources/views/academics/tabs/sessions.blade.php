@php $year = (int) now()->format('Y'); @endphp

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        @forelse ($sessions as $session)
            <section class="card">
                <header class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <h3 class="text-base font-semibold">{{ $session->name }}</h3>
                        @if ($session->is_current)
                            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">Current</span>
                        @endif
                    </div>
                    @if ($canEdit && ! $session->is_current)
                        <form method="POST" action="{{ route('academics.sessions.current', $session->id) }}">
                            @csrf
                            <button class="btn-secondary !px-3 !py-1">Mark current</button>
                        </form>
                    @endif
                </header>

                @if ($session->terms->isEmpty())
                    <p class="mt-3 text-sm text-gray-500">No terms yet.</p>
                @else
                    <table class="mt-3 w-full text-left text-sm">
                        <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="py-2 pr-3 font-medium">Term</th>
                                <th class="py-2 pr-3 font-medium">Days open</th>
                                <th class="py-2 font-medium">Result date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/5">
                            @foreach ($session->terms as $term)
                                <tr>
                                    <td class="py-2 pr-3 font-medium">{{ $term->name }}</td>
                                    <td class="py-2 pr-3 text-gray-600">{{ $term->days_school_open }}</td>
                                    <td class="py-2 text-gray-600">{{ $term->result_date?->format('j M Y') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        @empty
            <div class="card text-sm text-gray-500">No sessions yet.</div>
        @endforelse
    </div>

    @if ($canEdit)
        <div class="space-y-6 self-start">
            <form method="POST" action="{{ route('academics.sessions.store') }}" class="card space-y-3">
                @csrf
                <h3 class="text-base font-semibold">Add session</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="start_year" class="block text-xs font-medium text-gray-600">Start year</label>
                        <input id="start_year" type="number" name="start_year" value="{{ old('start_year', $year) }}" class="form-input mt-1" required>
                    </div>
                    <div>
                        <label for="end_year" class="block text-xs font-medium text-gray-600">End year</label>
                        <input id="end_year" type="number" name="end_year" value="{{ old('end_year', $year + 1) }}" class="form-input mt-1" required>
                    </div>
                </div>
                <div>
                    <label for="session_name" class="block text-xs font-medium text-gray-600">Name (optional)</label>
                    <input id="session_name" name="name" value="{{ old('name') }}" placeholder="Defaults to start/end" class="form-input mt-1" maxlength="100">
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_current" value="1" class="rounded border-gray-300"> Make this the current session
                </label>
                <button class="btn-primary w-full">Add session</button>
            </form>

            @if ($sessions->isNotEmpty())
                <form method="POST" action="{{ route('academics.terms.store') }}" class="card space-y-3">
                    @csrf
                    <h3 class="text-base font-semibold">Add term</h3>
                    <div>
                        <label for="term_session" class="block text-xs font-medium text-gray-600">Session</label>
                        <select id="term_session" name="session_id" class="form-input mt-1" required>
                            @foreach ($sessions as $s)
                                <option value="{{ $s->id }}" @selected($s->is_current)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="term_sequence" class="block text-xs font-medium text-gray-600">Term</label>
                        <select id="term_sequence" name="sequence" class="form-input mt-1" required>
                            @foreach (\App\Services\AcademicsService::TERM_NAMES as $n => $label)
                                <option value="{{ $n }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="days_school_open" class="block text-xs font-medium text-gray-600">Days open</label>
                            <input id="days_school_open" type="number" min="0" max="366" name="days_school_open" value="{{ old('days_school_open', 0) }}" class="form-input mt-1">
                        </div>
                        <div>
                            <label for="result_date" class="block text-xs font-medium text-gray-600">Result date</label>
                            <input id="result_date" type="date" name="result_date" value="{{ old('result_date') }}" class="form-input mt-1">
                        </div>
                    </div>
                    <button class="btn-primary w-full">Add term</button>
                </form>
            @endif
        </div>
    @endif
</div>
