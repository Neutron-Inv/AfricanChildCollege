@php
    $bands = $scale?->bands ?? collect();
    $rows = old('bands', $bands->map(fn ($b) => [
        'min_score' => $b->min_score, 'max_score' => $b->max_score, 'letter' => $b->letter, 'remark' => $b->remark,
    ])->values()->all());
@endphp

@if (! $scale)
    <div class="card text-sm text-gray-500">No default grading scale has been set up for this school.</div>
@else
    <div class="card max-w-3xl">
        <h3 class="text-base font-semibold">{{ $scale->name }} grading scale</h3>
        <p class="mb-4 text-xs text-gray-500">Scores outside every band receive no grade. Bands cannot overlap.</p>

        @if ($canEditGrading)
            <form method="POST" action="{{ route('academics.grading.update') }}"
                  x-data="{ rows: @js($rows) }" class="space-y-3">
                @csrf @method('PUT')

                <div class="hidden grid-cols-12 gap-2 text-xs font-medium uppercase tracking-wide text-gray-500 sm:grid">
                    <span class="col-span-2">Min</span><span class="col-span-2">Max</span>
                    <span class="col-span-2">Letter</span><span class="col-span-5">Remark</span>
                </div>

                <template x-for="(row, i) in rows" :key="i">
                    <div class="grid grid-cols-12 items-center gap-2">
                        <input type="number" min="0" max="100" :name="`bands[${i}][min_score]`" x-model="row.min_score" class="form-input col-span-3 sm:col-span-2" aria-label="Minimum score" required>
                        <input type="number" min="0" max="100" :name="`bands[${i}][max_score]`" x-model="row.max_score" class="form-input col-span-3 sm:col-span-2" aria-label="Maximum score" required>
                        <input maxlength="4" :name="`bands[${i}][letter]`" x-model="row.letter" class="form-input col-span-3 sm:col-span-2" aria-label="Letter" required>
                        <input maxlength="40" :name="`bands[${i}][remark]`" x-model="row.remark" class="form-input col-span-9 sm:col-span-5" aria-label="Remark" required>
                        <button type="button" @click="rows.splice(i, 1)" class="col-span-3 text-xs font-semibold text-red-700 hover:underline sm:col-span-1" :disabled="rows.length === 1">Remove</button>
                    </div>
                </template>

                <div class="flex items-center gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="rows.push({ min_score: '', max_score: '', letter: '', remark: '' })">Add band</button>
                    <button class="btn-primary" onclick="return confirm('Replace all grading bands? New scores will be graded with this scale.')">Save bands</button>
                </div>
            </form>
        @else
            <table class="w-full text-left text-sm">
                <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="py-2 pr-3 font-medium">Score range</th>
                        <th class="py-2 pr-3 font-medium">Letter</th>
                        <th class="py-2 font-medium">Remark</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-black/5">
                    @foreach ($bands as $b)
                        <tr>
                            <td class="py-2 pr-3">{{ $b->min_score }} – {{ $b->max_score }}</td>
                            <td class="py-2 pr-3 font-semibold">{{ $b->letter }}</td>
                            <td class="py-2 text-gray-600">{{ $b->remark }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-3 text-xs text-gray-500">Only administrators can change the grading scale.</p>
        @endif
    </div>
@endif
