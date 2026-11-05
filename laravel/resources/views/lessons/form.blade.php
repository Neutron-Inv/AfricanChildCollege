@php
    $editing = $plan->exists;
    $value = fn ($field) => old($field, $plan->{$field});
    $offerLabel = fn ($cs) => trim(($cs->class?->display_name ?? '').' · '.($cs->subject?->name ?? ''), ' ·');
    $textareas = [
        ['specific_objective', 'Objectives', 3],
        ['content', 'Lesson content', 8],
        ['materials', 'Materials', 2],
        ['previous_knowledge', 'Previous knowledge', 2],
        ['evaluation', 'Evaluation', 3],
        ['assignment', 'Assignment', 2],
        ['reference', 'References', 2],
    ];
@endphp
<x-school-layout title="Lessons">
    <div class="mb-5">
        <a href="{{ $editing ? route('lessons.show', $plan->id) : route('lessons') }}" class="text-sm text-gray-500 hover:underline">&larr; Back</a>
        <h2 class="mt-1 text-xl font-semibold">{{ $editing ? 'Edit lesson note' : 'New lesson note' }}</h2>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if ($offerings->isEmpty())
        <div class="card text-sm text-gray-600">You are not assigned to teach any subject in the current session, so there is nothing to write a lesson note for.</div>
    @else
        <form method="POST" action="{{ $editing ? route('lessons.update', $plan->id) : route('lessons.store') }}" class="card space-y-4">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="class_subject_id" class="block text-xs font-medium text-gray-600">Class &amp; subject</label>
                    <select id="class_subject_id" name="class_subject_id" class="form-input mt-1" required>
                        @foreach ($offerings as $cs)
                            <option value="{{ $cs->id }}" @selected($value('class_subject_id') === $cs->id)>{{ $offerLabel($cs) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="term_id" class="block text-xs font-medium text-gray-600">Term</label>
                    <select id="term_id" name="term_id" class="form-input mt-1" required>
                        @foreach ($terms as $term)
                            <option value="{{ $term->id }}" @selected($value('term_id') === $term->id)>{{ $term->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="topic" class="block text-xs font-medium text-gray-600">Topic</label>
                    <input id="topic" name="topic" value="{{ $value('topic') }}" class="form-input mt-1" required maxlength="255">
                </div>
                <div>
                    <label for="sub_topic" class="block text-xs font-medium text-gray-600">Sub-topic</label>
                    <input id="sub_topic" name="sub_topic" value="{{ $value('sub_topic') }}" class="form-input mt-1" maxlength="255">
                </div>
                <div>
                    <label for="week" class="block text-xs font-medium text-gray-600">Week</label>
                    <input id="week" name="week" value="{{ $value('week') }}" class="form-input mt-1" placeholder="e.g. Week 3" maxlength="50">
                </div>
                <div>
                    <label for="lesson_date" class="block text-xs font-medium text-gray-600">Lesson date</label>
                    <input id="lesson_date" type="date" name="lesson_date" value="{{ old('lesson_date', $plan->lesson_date?->toDateString()) }}" class="form-input mt-1">
                </div>
            </div>

            @foreach ($textareas as [$field, $label, $rows])
                <div>
                    <label for="{{ $field }}" class="block text-xs font-medium text-gray-600">{{ $label }}</label>
                    <textarea id="{{ $field }}" name="{{ $field }}" rows="{{ $rows }}" class="form-input mt-1">{{ $value($field) }}</textarea>
                </div>
            @endforeach

            <div class="flex gap-3">
                <button class="btn-primary">{{ $editing ? 'Save changes' : 'Save as draft' }}</button>
                <a href="{{ $editing ? route('lessons.show', $plan->id) : route('lessons') }}" class="btn-secondary">Cancel</a>
            </div>
        </form>
    @endif
</x-school-layout>
