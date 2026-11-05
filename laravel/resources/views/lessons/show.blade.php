@php
    $badge = [
        'DRAFT' => 'bg-gray-100 text-gray-700',
        'SUBMITTED' => 'bg-amber-100 text-amber-800',
        'APPROVED' => 'bg-green-100 text-green-800',
    ];
    $sections = [
        'specific_objective' => 'Objectives',
        'content' => 'Lesson content',
        'materials' => 'Materials',
        'previous_knowledge' => 'Previous knowledge',
        'evaluation' => 'Evaluation',
        'assignment' => 'Assignment',
        'reference' => 'References',
    ];
    $cs = $plan->classSubject;
@endphp
<x-school-layout title="Lessons">
    <div class="mb-5">
        <a href="{{ route('lessons') }}" class="text-sm text-gray-500 hover:underline">&larr; All lesson notes</a>
        <div class="mt-1 flex flex-wrap items-center gap-3">
            <h2 class="text-xl font-semibold">{{ $plan->topic }}</h2>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$plan->status] ?? 'bg-gray-100 text-gray-700' }}">{{ ucfirst(strtolower($plan->status)) }}</span>
        </div>
        @if ($plan->sub_topic)<p class="text-sm text-gray-500">{{ $plan->sub_topic }}</p>@endif
    </div>

    <div class="card mb-5 grid gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
        <div><div class="text-xs text-gray-500">Class</div>{{ $cs?->class?->display_name ?: '—' }}</div>
        <div><div class="text-xs text-gray-500">Subject</div>{{ $cs?->subject?->name ?: '—' }}</div>
        <div><div class="text-xs text-gray-500">Term</div>{{ $plan->term?->name ?: '—' }}</div>
        <div><div class="text-xs text-gray-500">Week / date</div>{{ trim($plan->week.' '.($plan->lesson_date ? '· '.$plan->lesson_date->format('j M Y') : ''), ' ·') ?: '—' }}</div>
        <div><div class="text-xs text-gray-500">Teacher</div>{{ $plan->author?->full_name ?: '—' }}</div>
        <div><div class="text-xs text-gray-500">Department</div>{{ $cs?->subject?->department?->name ?: '—' }}</div>
        @if ($plan->status === 'APPROVED')
            <div class="sm:col-span-2"><div class="text-xs text-gray-500">Signed off</div>{{ $plan->hod?->full_name ?: 'Administrator' }} · {{ $plan->hod_signed_at?->format('j M Y, H:i') }}</div>
        @endif
    </div>

    <div class="card mb-5 space-y-4">
        @php $any = false; @endphp
        @foreach ($sections as $field => $label)
            @if (filled($plan->{$field}))
                @php $any = true; @endphp
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</h3>
                    <p class="mt-1 whitespace-pre-line text-sm">{{ $plan->{$field} }}</p>
                </div>
            @endif
        @endforeach
        @unless ($any)
            <p class="text-sm text-gray-500">No details written yet.</p>
        @endunless
    </div>

    <div class="flex flex-wrap gap-3">
        @if ($canEdit)
            <a href="{{ route('lessons.edit', $plan->id) }}" class="btn-secondary">Edit</a>
        @endif
        @if ($canSubmit)
            <form method="POST" action="{{ route('lessons.submit', $plan->id) }}">@csrf <button class="btn-primary">Submit for approval</button></form>
        @endif
        @if ($canReview)
            <form method="POST" action="{{ route('lessons.approve', $plan->id) }}">@csrf <button class="btn-primary">Approve</button></form>
            <form method="POST" action="{{ route('lessons.return', $plan->id) }}">@csrf <button class="btn-secondary">Return to teacher</button></form>
        @endif
    </div>
</x-school-layout>
