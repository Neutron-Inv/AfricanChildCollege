@php
    $className = fn ($c) => trim($c->display_name.' · '.($c->session?->name ?? ''), ' ·');
@endphp

<form method="POST" action="{{ route('fees.invoices.generate') }}" class="card max-w-3xl">
    @csrf
    <h3 class="mb-1 text-base font-semibold">Generate invoices</h3>
    <p class="mb-4 text-sm text-gray-500">
        Creates one invoice per actively enrolled student for the term's session, from the fee items that apply to their class.
        Students who already have an invoice for the term are skipped.
    </p>

    @if ($terms->isEmpty())
        <p class="text-sm text-gray-600">Create a session and term under Academics first.</p>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="gen_term" class="block text-xs font-medium text-gray-600">Term</label>
                <select id="gen_term" name="term_id" required class="form-input mt-1">
                    @foreach ($terms as $t)
                        <option value="{{ $t->id }}" @selected(old('term_id', $defaultTerm?->id) === $t->id)>{{ $t->session?->name }} · {{ $t->name ?: 'Term '.$t->sequence }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="gen_class" class="block text-xs font-medium text-gray-600">Class</label>
                <select id="gen_class" name="class_id" class="form-input mt-1">
                    <option value="">Whole school (runs in the background)</option>
                    @foreach ($classes as $c)
                        <option value="{{ $c->id }}" @selected(old('class_id') === $c->id)>{{ $className($c) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <label class="mt-4 flex items-center gap-2 text-sm">
            <input type="checkbox" name="draft" value="1" @checked(old('draft'))>
            Create as drafts (issue them later)
        </label>

        <div class="mt-4"><button class="btn-primary">Generate invoices</button></div>
    @endif
</form>
