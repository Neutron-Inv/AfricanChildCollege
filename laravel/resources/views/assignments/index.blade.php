@php
    $offerLabel = fn ($cs) => trim(($cs->class?->display_name ?? '').' · '.($cs->subject?->name ?? ''), ' ·');
    $size = fn ($bytes) => $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, round($bytes / 1024)).' KB';
@endphp
<x-school-layout title="Assignments">
    <div class="mb-5">
        <h2 class="text-xl font-semibold">Assignments</h2>
        <p class="text-sm text-gray-500">Work set for each class subject.</p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if ($canCreate)
        <div class="card mb-5">
            <h3 class="mb-3 text-base font-semibold">New assignment</h3>
            @if ($offerings->isEmpty())
                <p class="text-sm text-gray-500">You are not assigned to teach any subject in the current session.</p>
            @else
                <form method="POST" action="{{ route('assignments.store') }}" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <div>
                        <label for="a_cs" class="block text-xs font-medium text-gray-600">Class &amp; subject</label>
                        <select id="a_cs" name="class_subject_id" class="form-input mt-1" required>
                            @foreach ($offerings as $cs)
                                <option value="{{ $cs->id }}" @selected(old('class_subject_id') === $cs->id)>{{ $offerLabel($cs) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="a_term" class="block text-xs font-medium text-gray-600">Term</label>
                        <select id="a_term" name="term_id" class="form-input mt-1" required>
                            @foreach ($terms as $term)
                                <option value="{{ $term->id }}" @selected(old('term_id') === $term->id)>{{ $term->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="a_title" class="block text-xs font-medium text-gray-600">Title</label>
                        <input id="a_title" name="title" value="{{ old('title') }}" class="form-input mt-1" required maxlength="255">
                    </div>
                    <div>
                        <label for="a_due" class="block text-xs font-medium text-gray-600">Due date</label>
                        <input id="a_due" type="date" name="due_date" value="{{ old('due_date') }}" class="form-input mt-1">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="a_note" class="block text-xs font-medium text-gray-600">Description</label>
                        <textarea id="a_note" name="note" rows="3" class="form-input mt-1">{{ old('note') }}</textarea>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="a_file" class="block text-xs font-medium text-gray-600">Attachment (optional, up to 10 MB)</label>
                        <input id="a_file" type="file" name="file" class="mt-1 block text-sm">
                    </div>
                    <div class="sm:col-span-2"><button class="btn-primary">Create assignment</button></div>
                </form>
            @endif
        </div>
    @endif

    <div class="card">
        @if ($assignments->isEmpty())
            <p class="text-sm text-gray-500">No assignments yet.</p>
        @else
            <div class="divide-y divide-black/5">
                @foreach ($assignments as $assignment)
                    <div class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $assignment->title }}</div>
                            <div class="text-xs text-gray-500">
                                {{ $offerLabel($assignment->classSubject) }}
                                @if ($assignment->teacher) · {{ $assignment->teacher->full_name }} @endif
                                · {{ $assignment->due_date ? 'Due '.$assignment->due_date->format('D, j M Y') : 'No due date' }}
                            </div>
                            @if ($assignment->note)
                                <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ $assignment->note }}</p>
                            @endif
                            @if ($assignment->file)
                                <a href="{{ route('assignments.file', $assignment->id) }}" class="link-primary mt-1 inline-block text-sm hover:underline">
                                    Download {{ $assignment->file->original_name }} ({{ $size($assignment->file->size) }})
                                </a>
                            @endif
                        </div>
                        @if ($service->canManage(auth()->user(), $assignment))
                            <form method="POST" action="{{ route('assignments.destroy', $assignment->id) }}" onsubmit="return confirm('Delete this assignment?')">
                                @csrf @method('DELETE')
                                <button class="btn-secondary !py-1.5">Delete</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="mt-4">{{ $assignments->links() }}</div>
            @if (auth()->user()->hasRole('TEACHER', 'ADMIN') || auth()->user()->isPlatformOwner())
                <p class="mt-4 text-xs text-gray-500">Student submissions are not available yet.</p>
            @endif
        @endif
    </div>
</x-school-layout>
