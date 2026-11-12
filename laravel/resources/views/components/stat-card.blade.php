@props([
    'label',
    'value',
    'note' => null,
    'tone' => 'primary',
    'icon' => 'users',
    'as' => 'div',
])

@php
    $tones = [
        'primary' => ['wash' => 'color-mix(in srgb, var(--primary) 14%, white)', 'ink' => 'var(--primary)', 'chip' => 'var(--primary)'],
        'teal' => ['wash' => '#e7f6f4', 'ink' => '#0f766e', 'chip' => '#0f766e'],
        'amber' => ['wash' => '#fff6e5', 'ink' => '#b45309', 'chip' => '#d97706'],
        'green' => ['wash' => '#eaf7ee', 'ink' => '#15803d', 'chip' => '#16a34a'],
    ];
    $palette = $tones[$tone] ?? $tones['primary'];
@endphp

<{{ $as }} {{ $attributes->merge(['class' => 'card relative overflow-hidden transition hover:-translate-y-0.5 hover:shadow-md']) }} style="background: {{ $palette['wash'] }}; border-color: transparent;">
    <span class="absolute inset-x-0 top-0 h-1" style="background: {{ $palette['chip'] }}"></span>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="text-sm font-medium" style="color: {{ $palette['ink'] }}">{{ $label }}</div>
            <div class="mt-1 break-words text-2xl font-semibold text-gray-900">{{ $value }}</div>
            @if (filled($note))
                <div class="mt-1 text-sm text-gray-600">{{ $note }}</div>
            @endif
        </div>
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-white shadow-sm" style="background: {{ $palette['chip'] }}">
            @switch($icon)
                @case('badge')
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0h4"/></svg>
                    @break
                @case('book')
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    @break
                @case('calendar')
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    @break
                @case('grid')
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    @break
                @case('person')
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    @break
                @default
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            @endswitch
        </span>
    </div>
</{{ $as }}>
