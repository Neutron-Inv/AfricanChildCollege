{{-- Clickable student name. Needs: $enrolment, $studentProfile. Optional: $nameClass --}}
@php $profile = ($studentProfile ?? null) ? $studentProfile($enrolment) : null; @endphp
@if ($profile)
    <button type="button"
            class="{{ $nameClass ?? 'text-left font-medium hover:underline' }}"
            title="View student details"
            @click="$dispatch('student-profile', @js($profile))">{{ $profile['name'] }}</button>
@endif
