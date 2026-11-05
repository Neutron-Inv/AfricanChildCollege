<x-guest-layout>
    <h1 class="text-lg font-semibold">
        {{ $school ? 'Sign in to '.$school->name : 'Platform sign in' }}
    </h1>
    <p class="mb-5 mt-1 text-sm text-gray-500">
        {{ $school ? 'Use the email and password given to you by the school.' : 'Platform owner access only.' }}
    </p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input id="email" class="mt-1" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" value="Password" />
            <x-text-input id="password" class="mt-1" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="mt-4 inline-flex items-center">
            <input id="remember_me" type="checkbox" name="remember" class="rounded border-gray-300 shadow-sm" style="color: var(--primary)">
            <span class="ms-2 text-sm text-gray-600">Remember me</span>
        </label>

        <x-primary-button class="mt-5 w-full">Log in</x-primary-button>
    </form>
</x-guest-layout>
