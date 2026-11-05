<x-platform-layout title="Schools">
    <div class="mb-4">
        <h1 class="text-xl font-semibold">Schools</h1>
        <p class="text-sm text-gray-500">Enter a school to view and manage it as platform owner.</p>
    </div>

    @php
        $port = parse_url(config('app.url'), PHP_URL_PORT);
        $scheme = parse_url(config('app.url'), PHP_URL_SCHEME) ?: 'http';
    @endphp

    <div class="overflow-x-auto rounded-lg border border-black/5 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-100 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">School</th>
                    <th class="px-4 py-3">Address</th>
                    <th class="px-4 py-3 text-right">Students</th>
                    <th class="px-4 py-3 text-right">Staff</th>
                    <th class="px-4 py-3 text-right">Users</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($schools as $school)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2 font-medium">
                                <span class="inline-block h-3 w-3 rounded-full" style="background: {{ $school->primary_color }}"></span>
                                {{ $school->name }}
                            </div>
                            <div class="text-xs text-gray-500">{{ $scheme }}://{{ $school->subdomain }}.{{ config('app.domain') }}{{ $port ? ':'.$port : '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $school->address ?: '-' }}</td>
                        <td class="px-4 py-3 text-right">{{ $school->students_count }}</td>
                        <td class="px-4 py-3 text-right">{{ $school->staff_count }}</td>
                        <td class="px-4 py-3 text-right">{{ $school->users_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('platform.schools.enter', $school) }}">
                                @csrf
                                <button type="submit" class="btn-primary !py-1.5">Enter school</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No schools yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-platform-layout>
