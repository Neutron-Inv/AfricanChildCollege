<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'Platform' }} - {{ config('app.name') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased text-gray-900" style="background: var(--canvas)">
        <div class="flex min-h-screen flex-col">
        <header class="flex h-14 items-center justify-between px-4 text-white sm:px-6" style="background: var(--sidebar)">
            <div class="font-semibold">{{ config('app.name') }} <span class="ml-2 text-xs font-normal text-gray-400">Platform</span></div>
            <div class="flex items-center gap-4 text-sm">
                <span class="hidden text-gray-300 sm:inline">{{ auth()->user()->email }}</span>
                <a href="{{ route('password.change') }}" class="text-gray-300 hover:text-white">Change password</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-md border border-white/20 px-3 py-1 hover:bg-white/10">Log out</button>
                </form>
            </div>
        </header>

        @include('partials.toasts')

        <main class="mx-auto w-full max-w-5xl flex-1 p-4 sm:p-6">
            {{ $slot }}
        </main>

        @include('partials.powered-by')
        </div>
    </body>
</html>
