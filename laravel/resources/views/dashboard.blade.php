<x-school-layout title="Dashboard">
    <div class="mb-6">
        <h2 class="text-xl font-semibold">Welcome, {{ auth()->user()->name }}</h2>
        <p class="text-sm text-gray-500">
            {{ $school->name }}
            @if ($currentSession) &middot; Session {{ $currentSession->name }} @endif
        </p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @if ($studentHome)
            @foreach ([
                ['Index number', $studentHome['index'] ?? '—', null],
                ['Current class', $studentHome['class'] ?? 'Not placed', $studentHome['formTeacher'] ?? 'No form teacher'],
                ['Current session', $studentHome['session'] ?? 'Not set', null],
                ['Students in class', $studentHome['classSize'] ?? '—', null],
            ] as [$label, $value, $note])
                <div class="card">
                    <div class="text-sm text-gray-500">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
                    @if ($note)
                        <div class="mt-1 text-sm text-gray-500">{{ $note }}</div>
                    @endif
                </div>
            @endforeach
        @elseif ($teacherHome)
            @foreach ([
                ['Form class', $teacherHome['class'] ?? 'None'],
                ['Students in class', $teacherHome['classSize'] ?? '—'],
            ] as [$label, $value])
                <div class="card">
                    <div class="text-sm text-gray-500">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
                </div>
            @endforeach
            <div class="contents" x-data="{ open: false }" @keydown.escape.window="open = false">
                @if ($teacherHome['subjectCount'] > 0)
                    <button type="button" class="card w-full text-left transition hover:ring-1 hover:ring-[color:var(--primary)]" @click="open = true">
                        <div class="text-sm text-gray-500">Subjects</div>
                        <div class="mt-1 text-2xl font-semibold">{{ $teacherHome['subjectCount'] }}</div>
                    </button>
                @else
                    <div class="card">
                        <div class="text-sm text-gray-500">Subjects</div>
                        <div class="mt-1 text-2xl font-semibold">0</div>
                    </div>
                @endif
                <div
                    x-show="open"
                    x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                    @click.self="open = false"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Subjects you teach"
                >
                    <div class="card max-h-[85vh] w-full max-w-md overflow-y-auto !p-5" @click.stop>
                        <div class="mb-4 flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold">Subjects</h3>
                                <p class="mt-1 text-xs text-gray-500">{{ $teacherHome['subjectCount'] }} distinct {{ \Illuminate\Support\Str::plural('subject', $teacherHome['subjectCount']) }} this session</p>
                            </div>
                            <button type="button" class="btn-secondary !py-1.5" @click="open = false">Close</button>
                        </div>
                        <ul class="space-y-3 text-sm">
                            @foreach ($teacherHome['offerings'] as $offering)
                                <li class="rounded-md bg-gray-50 px-3 py-2">
                                    <div class="font-medium">{{ $offering['subject'] }}</div>
                                    <div class="mt-0.5 text-xs text-gray-600">{{ implode(', ', $offering['classes']) }}</div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="text-sm text-gray-500">Current session</div>
                <div class="mt-1 text-2xl font-semibold">{{ $teacherHome['session'] ?? 'Not set' }}</div>
            </div>
        @else
            @foreach ([
                ['Students', $studentCount],
                ['Staff', $staffCount],
                ['Classes', $classCount],
                ['Current session', $currentSession?->name ?? 'Not set'],
            ] as [$label, $value])
                <div class="card">
                    <div class="text-sm text-gray-500">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
                </div>
            @endforeach
        @endif
    </div>

    <div class="mt-6 grid gap-5 xl:grid-cols-3">
        {{-- School calendar --}}
        <div class="card xl:col-span-2" id="calendar">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-base font-semibold">{{ $calendar['month']->format('F Y') }}</h3>
                <div class="flex items-center gap-2 text-sm">
                    <a href="{{ route('dashboard', ['month' => $calendar['prev']]) }}#calendar" class="btn-secondary !px-3 !py-1" aria-label="Previous month">&larr;</a>
                    <a href="{{ route('dashboard') }}#calendar" class="btn-secondary !px-3 !py-1">Today</a>
                    <a href="{{ route('dashboard', ['month' => $calendar['next']]) }}#calendar" class="btn-secondary !px-3 !py-1" aria-label="Next month">&rarr;</a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full table-fixed border-collapse text-xs">
                    <thead>
                        <tr class="text-gray-500">
                            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)
                                <th class="border border-black/5 py-1 font-medium">{{ $d }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($calendar['weeks'] as $week)
                            <tr>
                                @foreach ($week as $day)
                                    <td class="h-20 border border-black/5 p-1 align-top {{ $day['in_month'] ? '' : 'bg-gray-50 text-gray-400' }}">
                                        <div class="mb-0.5 {{ $day['today'] ? 'inline-flex h-5 w-5 items-center justify-center rounded-full font-semibold text-white' : '' }}"
                                             @if ($day['today']) style="background: var(--primary)" @endif>{{ $day['date']->day }}</div>
                                        @foreach ($day['events']->take(2) as $event)
                                            <div class="truncate rounded px-1 py-0.5 text-[10px] font-medium text-white" style="background: var(--primary)" title="{{ $event->title }}">{{ $event->title }}</div>
                                        @endforeach
                                        @if ($day['events']->count() > 2)
                                            <div class="text-[10px] text-gray-500">+{{ $day['events']->count() - 2 }} more</div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-5">
            <div class="card">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-base font-semibold">Upcoming events</h3>
                    <a href="{{ route('notices') }}" class="link-primary text-sm hover:underline">All</a>
                </div>
                @forelse ($upcomingEvents as $event)
                    <div class="border-t border-black/5 py-2 text-sm first:border-0 first:pt-0">
                        <div class="font-medium">{{ $event->title }}</div>
                        <div class="text-xs text-gray-500">
                            {{ $event->starts_at->format('D, j M') }}@unless ($event->starts_at->isSameDay($event->ends_at)) – {{ $event->ends_at->format('D, j M') }}@endunless
                            · {{ $event->all_day ? 'All day' : $event->starts_at->format('H:i') }}
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No upcoming events.</p>
                @endforelse
            </div>

            <div class="card">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-base font-semibold">Latest notices</h3>
                    <a href="{{ route('notices') }}" class="link-primary text-sm hover:underline">All</a>
                </div>
                @forelse ($latestNotices as $notice)
                    <div class="border-t border-black/5 py-2 text-sm first:border-0 first:pt-0">
                        <div class="font-medium">{{ $notice->title }}</div>
                        <div class="text-xs text-gray-500">{{ ($notice->publish_at ?? $notice->created_at)->format('j M Y') }}</div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No notices yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-school-layout>
