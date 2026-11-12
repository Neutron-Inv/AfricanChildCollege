@php $year = (int) now()->format('Y'); @endphp

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        @forelse ($sessions as $session)
            @php $openEditor = old('editing_session') === $session->id; @endphp
            <section class="card" x-data="{ editing: {{ $openEditor ? 'true' : 'false' }}, asking: false }">
                <header class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <h3 class="text-base font-semibold">{{ $session->name }}</h3>
                        @if ($session->is_current)
                            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">Current</span>
                        @endif
                    </div>
                    @if ($canEdit)
                        @php
                            $warning = ($session->is_current ? $session->name.' is the current session. ' : '')
                                .'Delete '.$session->name.'? This removes '
                                .$session->terms->count().' '.str('term')->plural($session->terms->count()).', '
                                .$session->classes_count.' '.str('class')->plural($session->classes_count).', and '
                                .$session->enrolments_count.' '.str('enrolment')->plural($session->enrolments_count)
                                .', including scores and report cards for that session. This cannot be undone.';
                        @endphp
                        <div class="flex items-center gap-1">
                            <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-600 hover:bg-black/5" x-show="!editing" @click="editing = true" title="Edit" aria-label="Edit session">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            @if (! $session->is_current)
                                <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-600 hover:bg-black/5" x-show="!editing" @click="asking = true" title="Mark current" aria-label="Mark current">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </button>
                            @endif
                            <form method="POST" action="{{ route('academics.sessions.destroy', $session->id) }}" x-show="!editing" onsubmit="return confirm(@js($warning))">
                                @csrf @method('DELETE')
                                <button class="inline-flex h-8 w-8 items-center justify-center rounded-md text-red-700 hover:bg-red-50" title="Delete" aria-label="Delete session">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </div>
                    @endif
                </header>
                <p class="mt-1 text-xs text-gray-500">{{ $session->start_year }} – {{ $session->end_year }}</p>

                <div x-show="!editing">
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
                </div>

                @if ($canEdit)
                    <form method="POST" action="{{ route('academics.sessions.update', $session->id) }}" x-show="editing" x-cloak class="mt-4 space-y-4">
                        @csrf @method('PUT')
                        <input type="hidden" name="editing_session" value="{{ $session->id }}">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-3">
                                <label for="name-{{ $session->id }}" class="block text-xs font-medium text-gray-600">Name</label>
                                <input id="name-{{ $session->id }}" name="name" value="{{ $openEditor ? old('name', $session->name) : $session->name }}" class="form-input mt-1" maxlength="100" required>
                            </div>
                            <div>
                                <label for="start-{{ $session->id }}" class="block text-xs font-medium text-gray-600">Start year</label>
                                <input id="start-{{ $session->id }}" type="number" name="start_year" value="{{ $openEditor ? old('start_year', $session->start_year) : $session->start_year }}" class="form-input mt-1" required>
                            </div>
                            <div>
                                <label for="end-{{ $session->id }}" class="block text-xs font-medium text-gray-600">End year</label>
                                <input id="end-{{ $session->id }}" type="number" name="end_year" value="{{ $openEditor ? old('end_year', $session->end_year) : $session->end_year }}" class="form-input mt-1" required>
                            </div>
                        </div>
                        @if ($session->terms->isNotEmpty())
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="py-2 pr-3 font-medium">Term</th>
                                        <th class="py-2 pr-3 font-medium">Days open</th>
                                        <th class="py-2 font-medium">Result date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-black/5">
                                    @foreach ($session->terms as $i => $term)
                                        <tr>
                                            <td class="py-2 pr-3 font-medium">
                                                {{ $term->name }}
                                                <input type="hidden" name="terms[{{ $i }}][id]" value="{{ $term->id }}">
                                            </td>
                                            <td class="py-2 pr-3">
                                                <input type="number" min="0" max="366" name="terms[{{ $i }}][days_school_open]" value="{{ $openEditor ? old('terms.'.$i.'.days_school_open', $term->days_school_open) : $term->days_school_open }}" class="form-input !w-24" aria-label="{{ $term->name }} days open">
                                            </td>
                                            <td class="py-2">
                                                <input type="date" name="terms[{{ $i }}][result_date]" value="{{ $openEditor ? old('terms.'.$i.'.result_date', $term->result_date?->toDateString()) : $term->result_date?->toDateString() }}" class="form-input" aria-label="{{ $term->name }} result date">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                        <div class="flex justify-end gap-2">
                            <button type="button" class="btn-secondary" @click="editing = false">Cancel</button>
                            <button class="btn-primary">Save session</button>
                        </div>
                    </form>
                @endif

                @if ($canEdit && ! $session->is_current)
                    <div x-show="asking" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="asking = false" @click.self="asking = false">
                        <form method="POST" action="{{ route('academics.sessions.current', $session->id) }}" class="card w-full max-w-md space-y-4" @click.stop>
                            @csrf
                            <h3 class="text-base font-semibold">Make {{ $session->name }} the current session?</h3>
                            <p class="text-sm text-gray-600">You can also mark every student enrolled in this session as active. Students marked left or graduated here go back on the active roll, and become left in every other session. You can then promote them, or leave them active here.</p>
                            <div class="flex flex-wrap justify-end gap-2">
                                <button type="button" class="btn-secondary" @click="asking = false">Cancel</button>
                                <button class="btn-secondary" name="activate" value="0">Just make current</button>
                                <button class="btn-primary" name="activate" value="1">Make current and activate</button>
                            </div>
                        </form>
                    </div>
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
                <p class="text-xs text-gray-500">Classes from the previous session are copied in, including their subjects and form teachers.</p>
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
