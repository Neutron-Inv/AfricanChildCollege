<x-school-layout title="Settings">
    <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="card max-w-2xl space-y-5">
        @csrf
        @method('PUT')

        <div>
            <h2 class="text-lg font-semibold">School profile</h2>
            <p class="text-sm text-gray-500">Name, contact details, brand colour, logo and principal signature for documents.</p>
        </div>

        <div>
            <x-input-label for="name" value="School name" />
            <x-text-input id="name" name="name" class="mt-1" :value="old('name', $school->name)" required />
            <x-input-error :messages="$errors->get('name')" class="mt-1" />
        </div>

        <div>
            <x-input-label for="address" value="Address" />
            <x-text-input id="address" name="address" class="mt-1" :value="old('address', $school->address)" />
            <x-input-error :messages="$errors->get('address')" class="mt-1" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="email" value="Email" />
                <x-text-input id="email" type="email" name="email" class="mt-1" :value="old('email', $school->email)" />
                <x-input-error :messages="$errors->get('email')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="phone" value="Phone" />
                <x-text-input id="phone" name="phone" class="mt-1" :value="old('phone', $school->phone)" />
                <x-input-error :messages="$errors->get('phone')" class="mt-1" />
            </div>
        </div>

        <div>
            <x-input-label for="motto" value="Motto" />
            <x-text-input id="motto" name="motto" class="mt-1" :value="old('motto', $school->motto)" />
            <x-input-error :messages="$errors->get('motto')" class="mt-1" />
        </div>

        <div>
            <x-input-label for="primary_color" value="Primary colour" />
            <div class="mt-1 flex items-center gap-3" x-data="{ color: '{{ old('primary_color', $school->primary_color) }}' }">
                <input type="color" x-model="color" class="h-9 w-12 cursor-pointer rounded border border-gray-300 bg-white p-1">
                <input id="primary_color" name="primary_color" x-model="color" maxlength="7" class="form-input w-32 font-mono" required>
            </div>
            <x-input-error :messages="$errors->get('primary_color')" class="mt-1" />
        </div>

        <div class="grid gap-6 border-t border-black/5 pt-5 sm:grid-cols-2">
            <div>
                <x-input-label for="logo" value="School logo" />
                <p class="mt-0.5 text-xs text-gray-500">JPEG, PNG, WebP or GIF · max {{ $maxImageKb }} KB. Used on broadsheet PDFs.</p>
                @if ($logoPreview)
                    <div class="mt-3 flex items-end gap-3">
                        <img src="{{ $logoPreview }}" alt="Current logo" class="h-20 w-20 rounded-md border border-black/10 object-contain bg-white p-1">
                        <label class="flex items-center gap-2 text-xs text-gray-600">
                            <input type="checkbox" name="clear_logo" value="1" class="rounded border-gray-300">
                            Remove logo
                        </label>
                    </div>
                @endif
                <input id="logo" type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/gif" class="form-input mt-3 text-sm file:mr-3 file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm">
                <x-input-error :messages="$errors->get('logo')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="principal_signature" value="Principal signature" />
                <p class="mt-0.5 text-xs text-gray-500">Transparent PNG works best · max {{ $maxImageKb }} KB. Printed on exported documents.</p>
                @if ($signaturePreview)
                    <div class="mt-3 flex items-end gap-3">
                        <img src="{{ $signaturePreview }}" alt="Current signature" class="h-16 max-w-[10rem] rounded-md border border-black/10 object-contain bg-white p-1">
                        <label class="flex items-center gap-2 text-xs text-gray-600">
                            <input type="checkbox" name="clear_signature" value="1" class="rounded border-gray-300">
                            Remove signature
                        </label>
                    </div>
                @endif
                <input id="principal_signature" type="file" name="principal_signature" accept="image/jpeg,image/png,image/webp,image/gif" class="form-input mt-3 text-sm file:mr-3 file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm">
                <x-input-error :messages="$errors->get('principal_signature')" class="mt-1" />
            </div>
        </div>

        <div class="pt-2">
            <x-primary-button>Save settings</x-primary-button>
        </div>
    </form>
</x-school-layout>
