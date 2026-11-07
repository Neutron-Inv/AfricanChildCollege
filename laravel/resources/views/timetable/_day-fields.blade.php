@php
    $breakKey = $prefix === '' ? 'breaks' : 'friday_breaks';
    $breaks = old($breakKey, [
        ['after' => $prefix === '' ? 3 : 3, 'minutes' => $prefix === '' ? 20 : 20, 'label' => 'Short break'],
        ['after' => $prefix === '' ? 5 : 4, 'minutes' => $prefix === '' ? 30 : 0, 'label' => 'Long break'],
    ]);
@endphp

<div class="grid gap-3 sm:grid-cols-3">
    <div>
        <label class="block text-xs font-medium text-gray-600" for="{{ $prefix }}start">Day starts</label>
        <input id="{{ $prefix }}start" type="time" name="{{ $prefix }}start" value="{{ $start }}" class="form-input mt-1" @if ($prefix === '') required @endif>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600" for="{{ $prefix }}period_minutes">Period length (minutes)</label>
        <input id="{{ $prefix }}period_minutes" type="number" min="5" max="180" name="{{ $prefix }}period_minutes" value="{{ $minutes }}" class="form-input mt-1" @if ($prefix === '') required @endif>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600" for="{{ $prefix }}periods">Number of periods</label>
        <input id="{{ $prefix }}periods" type="number" min="1" max="12" name="{{ $prefix }}periods" value="{{ $count }}" class="form-input mt-1" @if ($prefix === '') required @endif>
    </div>
</div>

<div class="mt-3 space-y-3">
    @foreach ($breaks as $i => $break)
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label class="block text-xs font-medium text-gray-600" for="{{ $prefix }}break-after-{{ $i }}">Break {{ $i + 1 }} after period</label>
                <input id="{{ $prefix }}break-after-{{ $i }}" type="number" min="1" max="12" name="{{ $breakKey }}[{{ $i }}][after]" value="{{ $break['after'] ?? '' }}" class="form-input mt-1">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600" for="{{ $prefix }}break-minutes-{{ $i }}">Length (minutes)</label>
                <input id="{{ $prefix }}break-minutes-{{ $i }}" type="number" min="0" max="180" name="{{ $breakKey }}[{{ $i }}][minutes]" value="{{ $break['minutes'] ?? 0 }}" class="form-input mt-1">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600" for="{{ $prefix }}break-label-{{ $i }}">Name</label>
                <input id="{{ $prefix }}break-label-{{ $i }}" name="{{ $breakKey }}[{{ $i }}][label]" value="{{ $break['label'] ?? '' }}" class="form-input mt-1" maxlength="40">
            </div>
        </div>
    @endforeach
    <p class="text-xs text-gray-500">Set a break length to 0 to leave it out.</p>
</div>
