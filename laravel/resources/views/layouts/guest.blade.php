@php
    use App\Support\Tenant;

    $school = Tenant::get();
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $school?->primary_color) ? $school->primary_color : '#a01010';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $school?->name ?? config('app.name') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <style>:root { --primary: {{ $color }}; }</style>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased" style="background: var(--canvas)">
        <div class="flex min-h-screen flex-col">
        <div class="flex flex-1 flex-col items-center justify-center px-4 py-8">
            <div class="mb-6 text-center">
                <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full text-xl font-bold text-white" style="background: var(--primary)">
                    {{ strtoupper(substr($school?->name ?? config('app.name'), 0, 1)) }}
                </div>
                <div class="text-xl font-semibold">{{ $school?->name ?? config('app.name') }}</div>
                @if ($school?->motto)
                    <div class="text-sm text-gray-500">{{ $school->motto }}</div>
                @endif
            </div>

            <div class="w-full max-w-md rounded-lg border border-black/5 bg-white px-6 py-6 shadow-sm">
                {{ $slot }}
            </div>
        </div>

        @include('partials.powered-by')
        </div>

        @include('partials.toasts')
    </body>
</html>
