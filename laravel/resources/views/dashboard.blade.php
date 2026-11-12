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
            <x-stat-card label="Index number" :value="$studentHome['index'] ?? '—'" tone="primary" icon="badge" />
            <x-stat-card label="Current class" :value="$studentHome['class'] ?? 'Not placed'" :note="$studentHome['formTeacher'] ?? 'No form teacher'" tone="teal" icon="book" />
            <x-stat-card label="Current session" :value="$studentHome['session'] ?? 'Not set'" tone="amber" icon="calendar" />
            <x-stat-card label="Students in class" :value="$studentHome['classSize'] ?? '—'" tone="green" icon="users" />
        @elseif ($teacherHome)
            <x-stat-card label="Form class" :value="$teacherHome['class'] ?? 'None'" tone="primary" icon="book" />
            <x-stat-card label="Students in class" :value="$teacherHome['classSize'] ?? '—'" tone="teal" icon="users" />
            <div class="contents" x-data="{ open: false }" @keydown.escape.window="open = false">
                @if ($teacherHome['subjectCount'] > 0)
                    <x-stat-card as="button" type="button" label="Subjects" :value="$teacherHome['subjectCount']" tone="amber" icon="grid" class="w-full cursor-pointer text-left" x-on:click="open = true" />
                @else
                    <x-stat-card label="Subjects" value="0" tone="amber" icon="grid" />
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
            <x-stat-card label="Current session" :value="$teacherHome['session'] ?? 'Not set'" tone="green" icon="calendar" />
        @else
            <x-stat-card label="Students" :value="$studentCount" tone="primary" icon="users" />
            <x-stat-card label="Staff" :value="$staffCount" tone="teal" icon="person" />
            <x-stat-card label="Classes" :value="$classCount" tone="amber" icon="grid" />
            <x-stat-card label="Current session" :value="$currentSession?->name ?? 'Not set'" tone="green" icon="calendar" />
        @endif
    </div>

    <div class="mt-6 grid gap-5 xl:grid-cols-3">
        {{-- School calendar --}}
        <div class="card overflow-hidden !p-0 xl:col-span-2" id="calendar">
            <div class="h-1.5" style="background: linear-gradient(90deg, var(--primary), #0f766e 55%, #d97706)"></div>
            <div class="p-5">
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
        </div>

        <div class="space-y-5">
            <div class="card border-l-4" style="border-left-color: #0f766e; background: linear-gradient(180deg, #f3fbfa, white 4.5rem)">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="flex items-center gap-2 text-base font-semibold"><span class="h-2.5 w-2.5 rounded-full" style="background: #0f766e"></span>Upcoming events</h3>
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

            <div class="card border-l-4" style="border-left-color: #d97706; background: linear-gradient(180deg, #fff8ee, white 4.5rem)">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="flex items-center gap-2 text-base font-semibold"><span class="h-2.5 w-2.5 rounded-full" style="background: #d97706"></span>Latest notices</h3>
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
