<x-guest-layout>
    <h1 class="text-lg font-semibold">Change password</h1>
    <p class="mb-5 mt-1 text-sm text-gray-500">
        {{ $forced ? 'You must choose a new password before continuing.' : 'Choose a new password for your account.' }}
    </p>

    <form method="POST" action="{{ route('password.change.update') }}">
        @csrf
        @method('PUT')

        <div>
            <x-input-label for="current_password" value="Current password" />
            <x-text-input id="current_password" class="mt-1" type="password" name="current_password" required autofocus autocomplete="current-password" />
            <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" value="New password" />
            <x-text-input id="password" class="mt-1" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" value="Confirm new password" />
            <x-text-input id="password_confirmation" class="mt-1" type="password" name="password_confirmation" required autocomplete="new-password" />
        </div>

        <div class="mt-5 flex items-center justify-between">
            @if ($forced)
                <button type="submit" form="logout-form" class="text-sm text-gray-500 underline hover:text-gray-700">Log out</button>
            @else
                <a href="{{ url()->previous(route('dashboard')) }}" class="text-sm text-gray-500 underline hover:text-gray-700">Cancel</a>
            @endif
            <x-primary-button>Change password</x-primary-button>
        </div>
    </form>

    <form id="logout-form" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
</x-guest-layout>
