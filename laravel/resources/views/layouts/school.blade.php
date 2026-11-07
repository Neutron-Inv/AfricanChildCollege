@php
    use App\Enums\Role;
    use App\Support\Tenant;

    $school = Tenant::get();
    $user = auth()->user();
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $school?->primary_color) ? $school->primary_color : '#a01010';

    $school?->loadMissing('logo');
    $logoFile = $school?->logo;
    $logoUrl = $logoFile && \Illuminate\Support\Facades\Storage::disk(\App\Services\SchoolSettingsService::DISK)->exists($logoFile->storage_key)
        ? route('branding.logo', ['v' => $logoFile->getKey()])
        : null;

    // A platform owner who has entered a school sees the ADMIN navigation.
    $role = $user->isPlatformOwner() ? Role::ADMIN : $user->role;

    $A = Role::ADMIN; $AC = Role::ACCOUNTANT; $R = Role::REGISTRAR; $T = Role::TEACHER; $S = Role::STUDENT; $P = Role::PARENT;

    // label => [route name, roles allowed]. Settings stays last and ADMIN-only.
    // Items whose route is not built yet render as disabled.
    $nav = [
        ['Dashboard',    'dashboard',     [$A, $AC, $R, $T, $S, $P]],
        ['Academics',    'academics',     [$A, $R, $T]],
        ['People',       'people',        [$A, $R, $T]],
        ['Enrolment',    'enrolment',     [$A, $R, $T]],
        ['Timetable',    'timetable',     [$A, $R, $T, $S, $P]],
        ['Attendance',   'attendance',    [$A, $AC, $R, $T, $S, $P]],
        ['Exams',        'exams',         [$A, $R, $T, $S, $P]],
        ['Fees',         'fees',          [$A, $AC, $R, $P]],
        ['Lessons',      'lessons',       [$A, $T]],
        ['Assignments',  'assignments',   [$A, $T, $S, $P]],
        ['Notices',      'notices',       [$A, $AC, $R, $T, $S, $P]],
        ['Settings',     'settings',      [$A]],
    ];
    $nav = array_filter($nav, fn ($item) => in_array($role, $item[2], true));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'Dashboard' }} - {{ $school->name }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <style>:root { --primary: {{ $color }}; }</style>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased text-gray-900" style="background: var(--canvas)">
        <div x-data="{ sidebar: false }" class="min-h-screen">
            {{-- Sidebar --}}
            <div x-show="sidebar" x-cloak @click="sidebar = false" class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>
            <aside class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col text-gray-300 transition-transform lg:translate-x-0"
                   :class="sidebar ? 'translate-x-0' : '-translate-x-full'"
                   style="background: var(--sidebar)">
                <div class="border-b border-white/10 px-4 py-3">
                    <div class="flex items-center gap-3">
                        @if ($logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ $school->name }} logo" class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-0.5">
                        @endif
                        <div class="min-w-0 leading-tight">
                            <div class="text-base font-semibold text-white">{{ $school->name }}</div>
                            @if ($school->motto)
                                <div class="mt-0.5 text-xs text-gray-400">{{ $school->motto }}</div>
                            @endif
                        </div>
                    </div>
                </div>

                <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4 text-sm">
                    @foreach ($nav as [$label, $routeName])
                        @if (Route::has($routeName))
                            @php $active = request()->routeIs($routeName.'*'); @endphp
                            <a href="{{ route($routeName) }}"
                               class="flex items-center rounded-md px-3 py-2 font-medium transition hover:bg-white/10 hover:text-white {{ $active ? 'bg-white/10 text-white' : '' }}"
                               @if ($active) style="box-shadow: inset 3px 0 0 var(--primary)" @endif>
                                {{ $label }}
                            </a>
                        @else
                            <span class="flex cursor-not-allowed items-center justify-between rounded-md px-3 py-2 text-gray-500">
                                {{ $label }}
                                <span class="text-[10px] uppercase tracking-wide">Soon</span>
                            </span>
                        @endif
                    @endforeach
                </nav>

                <div class="border-t border-white/10 px-5 py-3 text-xs text-gray-400">
                    <a href="{{ route('password.change') }}" class="hover:text-white">Change password</a>
                </div>
            </aside>

            {{-- Main --}}
            <div class="flex min-h-screen flex-col lg:pl-64">
                <header class="sticky top-0 z-20 flex h-14 items-center justify-between border-b border-black/5 bg-white px-4">
                    <div class="flex items-center gap-3">
                        <button type="button" class="lg:hidden" @click="sidebar = true" aria-label="Open menu">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round"/></svg>
                        </button>
                        <h1 class="text-base font-semibold">{{ $title ?? 'Dashboard' }}</h1>
                    </div>

                    <div class="flex items-center gap-4 text-sm">
                        <div class="hidden text-right leading-tight sm:block">
                            <div class="font-medium">{{ $user->name }}</div>
                            <div class="text-xs text-gray-500">{{ $user->role->label() }}</div>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn-secondary !py-1.5">Log out</button>
                        </form>
                    </div>
                </header>

                @include('partials.toasts')

                @if ($user->isPlatformOwner())
                    <div class="flex items-center justify-between bg-amber-100 px-4 py-2 text-sm text-amber-900">
                        <span>You are viewing <strong>{{ $school->name }}</strong> as platform owner.</span>
                        <form method="POST" action="{{ route('platform.leave') }}">
                            @csrf
                            <button type="submit" class="font-semibold underline">Exit school</button>
                        </form>
                    </div>
                @endif

                <main class="flex-1 p-4 sm:p-6">
                    {{ $slot }}
                </main>

                @include('partials.powered-by')
            </div>
        </div>
    </body>
</html>
