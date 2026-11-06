@php
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $school?->primary_color) ? $school->primary_color : '#a01010';
    $name = $school?->name ?? config('app.name');
    $mark = strtoupper(substr($name, 0, 1));
    $headline = $school ? 'The school day, in one place.' : 'One desk for every school.';
    $points = $school
        ? ['Report cards and scores', 'Attendance and the timetable', 'Fees, lessons, and notices']
        : ['Open any school from one list', 'See its people, classes, and results', 'Step back out when you are done'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $school ? 'Sign in' : 'Platform sign in' }} - {{ $name }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <style>:root { --primary: {{ $color }}; }</style>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased" style="background: var(--canvas)">
        <div class="grid min-h-screen lg:grid-cols-2">
            <aside class="relative overflow-hidden px-6 py-8 text-white sm:px-10 lg:flex lg:flex-col lg:justify-between lg:px-14 lg:py-12"
                   style="background-color: var(--primary); background-image: radial-gradient(rgba(255,255,255,.16) 1px, transparent 1px); background-size: 22px 22px;">
                <div class="pointer-events-none absolute inset-0" style="background: linear-gradient(160deg, rgba(255,255,255,.14), transparent 42%, rgba(0,0,0,.28));"></div>

                <div class="relative flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/15 text-lg font-semibold ring-1 ring-white/25">{{ $mark }}</div>
                    <div class="min-w-0 leading-tight">
                        <div class="truncate text-sm font-semibold">{{ $name }}</div>
                        @if ($school?->motto)
                            <div class="truncate text-xs text-white/75">{{ $school->motto }}</div>
                        @else
                            <div class="text-xs uppercase tracking-[0.16em] text-white/70">{{ $school ? 'School' : 'Platform' }}</div>
                        @endif
                    </div>
                </div>

                <div class="relative mt-8 max-w-md lg:mt-0">
                    <h1 class="text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">{{ $headline }}</h1>
                    <ul class="mt-6 hidden space-y-3 text-sm text-white/90 lg:block">
                        @foreach ($points as $point)
                            <li class="flex items-center gap-3">
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-white"></span>
                                {{ $point }}
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="relative mt-8 hidden text-sm text-white/75 lg:block">
                    @if ($school)
                        {{ $school->address ?: 'Use the email and password given to you by the school.' }}
                    @else
                        Platform owner access only.
                    @endif
                </p>
            </aside>

            <main class="flex items-center px-6 py-10 sm:px-10">
                <div class="mx-auto w-full max-w-md">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-400">Welcome back</p>
                    <h2 class="mt-2 text-2xl font-semibold">
                        {{ $school ? 'Sign in to '.$school->name : 'Platform sign in' }}
                    </h2>
                    <p class="mb-8 mt-2 text-sm text-gray-500">
                        {{ $school ? 'Use the email and password given to you by the school.' : 'Sign in to open a school and work inside it.' }}
                    </p>

                    <x-auth-session-status class="mb-4" :status="session('status')" />

                    <form method="POST" action="{{ route('login') }}" class="space-y-4">
                        @csrf

                        <div>
                            <x-input-label for="email" value="Email" />
                            <x-text-input id="email" class="mt-1 py-2.5" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <div>
                            <div class="flex items-center justify-between">
                                <x-input-label for="password" value="Password" />
                                @if (Route::has('password.request'))
                                    <a href="{{ route('password.request') }}" class="text-sm text-gray-500 hover:text-gray-800">Forgot password?</a>
                                @endif
                            </div>
                            <x-text-input id="password" class="mt-1 py-2.5" type="password" name="password" required autocomplete="current-password" />
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>

                        <label for="remember_me" class="inline-flex items-center">
                            <input id="remember_me" type="checkbox" name="remember" class="rounded border-gray-300 shadow-sm" style="color: var(--primary)">
                            <span class="ms-2 text-sm text-gray-600">Remember me</span>
                        </label>

                        <x-primary-button class="w-full py-2.5">Sign in</x-primary-button>
                    </form>
                </div>
            </main>
        </div>

        @include('partials.toasts')
    </body>
</html>
