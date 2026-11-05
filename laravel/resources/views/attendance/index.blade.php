@php
    $className = fn ($c) => trim(($c?->level?->name ?? '').' '.($c?->arm?->name ?? ''));
    $badge = [
        'PRESENT' => 'bg-green-100 text-green-800',
        'ABSENT' => 'bg-red-100 text-red-800',
        'LATE' => 'bg-amber-100 text-amber-800',
        'EXCUSED' => 'bg-blue-100 text-blue-800',
    ];
@endphp
<x-school-layout title="Attendance">
    <div class="mb-5">
        <h2 class="text-xl font-semibold">Attendance</h2>
        <p class="text-sm text-gray-500">
            {{ $mode === 'register' ? 'Daily class register.' : 'Recent attendance records.' }}
        </p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($mode === 'history')
        <div class="card">
            @if ($history->isEmpty())
                <p class="text-sm text-gray-500">No attendance has been recorded yet.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="py-2 pr-3 font-medium">Date</th>
                            <th class="py-2 pr-3 font-medium">Student</th>
                            <th class="py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black/5">
                        @foreach ($history as $row)
                            <tr>
                                <td class="py-2 pr-3">{{ $row->date->format('D, j M Y') }}</td>
                                <td class="py-2 pr-3">{{ $row->student?->full_name }}</td>
                                <td class="py-2"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$row->status] ?? 'bg-gray-100 text-gray-700' }}">{{ ucfirst(strtolower($row->status)) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @else
        @if ($classes->isEmpty())
            <div class="card text-sm text-gray-600">
                No registers available. Registers are listed for classes in the current session
                @unless (auth()->user()->isPlatformOwner() || auth()->user()->hasRole('ADMIN', 'REGISTRAR', 'ACCOUNTANT'))
                    where you are the form teacher
                @endunless.
            </div>
        @else
            <form method="GET" action="{{ route('attendance') }}" class="card mb-5 flex flex-wrap items-end gap-4">
                <div>
                    <label for="att_class" class="block text-xs font-medium text-gray-600">Class</label>
                    <select id="att_class" name="class" class="form-input mt-1" onchange="this.form.submit()">
                        @foreach ($classes as $c)
                            <option value="{{ $c->id }}" @selected($class?->id === $c->id)>{{ $className($c) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="att_date" class="block text-xs font-medium text-gray-600">Date</label>
                    <input id="att_date" type="date" name="date" value="{{ $date->toDateString() }}" class="form-input mt-1" onchange="this.form.submit()">
                </div>
                <noscript><button class="btn-secondary">Open</button></noscript>
            </form>

            @if ($dateError)
                <p class="mb-4 text-sm text-red-700">{{ $dateError }} Showing today instead.</p>
            @endif

            <form method="POST" action="{{ route('attendance.store') }}" class="card"
                  x-data="{ setAll(status) { this.$el.querySelectorAll('[data-mark][value=' + status + ']').forEach(r => r.checked = true) } }">
                @csrf
                <input type="hidden" name="class_id" value="{{ $class->id }}">
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">

                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-base font-semibold">
                        {{ $className($class) }}
                        <span class="text-sm font-normal text-gray-500">· {{ $date->format('l, j F Y') }} · {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('student', $rows->count()) }}</span>
                    </h3>
                    @if ($canSave && $rows->isNotEmpty())
                        <button type="button" class="btn-secondary !py-1.5" @click="setAll('PRESENT')">Mark all present</button>
                    @endif
                </div>

                @if ($rows->isEmpty())
                    <p class="text-sm text-gray-500">No active students are enrolled in this class.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="py-2 pr-3 font-medium">Student</th>
                                    @foreach ($statuses as $status)
                                        <th class="px-3 py-2 text-center font-medium">{{ ucfirst(strtolower($status)) }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-black/5">
                                @foreach ($rows as $row)
                                    @php $current = old('marks.'.$row['enrolment']->id, $row['status']); @endphp
                                    <tr>
                                        <td class="py-2 pr-3">
                                            <div class="font-medium">{{ $row['student']?->full_name }}</div>
                                            <div class="text-xs text-gray-500">{{ $row['student']?->index_number }}</div>
                                        </td>
                                        @foreach ($statuses as $status)
                                            <td class="px-3 py-2 text-center">
                                                <input type="radio" data-mark
                                                       name="marks[{{ $row['enrolment']->id }}]"
                                                       value="{{ $status }}"
                                                       aria-label="{{ $row['student']?->full_name }} {{ strtolower($status) }}"
                                                       @checked($current === $status)
                                                       @disabled(! $canSave)>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($canSave)
                        <div class="mt-4"><button class="btn-primary">Save register</button></div>
                    @else
                        <p class="mt-4 text-xs text-gray-500">Only the form teacher or the office can save this register.</p>
                    @endif
                @endif
            </form>
        @endif
    @endif
</x-school-layout>
