@php
    $badge = [
        'DRAFT' => 'bg-gray-100 text-gray-700',
        'SUBMITTED' => 'bg-amber-100 text-amber-800',
        'APPROVED' => 'bg-green-100 text-green-800',
    ];
    $offerLabel = fn ($cs) => trim(($cs->class?->display_name ?? '').' · '.($cs->subject?->name ?? ''), ' ·');
@endphp
<x-school-layout title="Lessons">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold">Lesson notes</h2>
            <p class="text-sm text-gray-500">Plans written by teachers and signed off by the head of department.</p>
        </div>
        @if ($canAuthor)
            <a href="{{ route('lessons.create') }}" class="btn-primary">New lesson note</a>
        @endif
    </div>

    <form method="GET" action="{{ route('lessons') }}" class="card mb-5 flex flex-wrap items-end gap-4">
        <div>
            <label for="f_status" class="block text-xs font-medium text-gray-600">Status</label>
            <select id="f_status" name="status" class="form-input mt-1" onchange="this.form.submit()">
                <option value="">All</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst(strtolower($status)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f_cs" class="block text-xs font-medium text-gray-600">Class &amp; subject</label>
            <select id="f_cs" name="class_subject_id" class="form-input mt-1" onchange="this.form.submit()">
                <option value="">All</option>
                @foreach ($offerings as $cs)
                    <option value="{{ $cs->id }}" @selected(($filters['class_subject_id'] ?? '') === $cs->id)>{{ $offerLabel($cs) }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button class="btn-secondary">Filter</button></noscript>
    </form>

    <div class="card">
        @if ($plans->isEmpty())
            <p class="text-sm text-gray-500">No lesson notes yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="py-2 pr-3 font-medium">Topic</th>
                            <th class="py-2 pr-3 font-medium">Class &amp; subject</th>
                            <th class="py-2 pr-3 font-medium">Week / date</th>
                            <th class="py-2 pr-3 font-medium">Teacher</th>
                            <th class="py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black/5">
                        @foreach ($plans as $plan)
                            <tr>
                                <td class="py-2 pr-3 font-medium"><a href="{{ route('lessons.show', $plan->id) }}" class="link-primary hover:underline">{{ $plan->topic }}</a></td>
                                <td class="py-2 pr-3">{{ $offerLabel($plan->classSubject) }}</td>
                                <td class="py-2 pr-3">{{ trim($plan->week.' '.($plan->lesson_date ? '· '.$plan->lesson_date->format('j M Y') : ''), ' ·') ?: '—' }}</td>
                                <td class="py-2 pr-3">{{ $plan->author?->full_name ?: '—' }}</td>
                                <td class="py-2"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$plan->status] ?? 'bg-gray-100 text-gray-700' }}">{{ ucfirst(strtolower($plan->status)) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $plans->links() }}</div>
        @endif
    </div>
</x-school-layout>
