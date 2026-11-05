{{-- Flash + live toasts (Alpine store). Top-right under the header. --}}
@php
    $flashToasts = collect([
        ['type' => 'success', 'message' => session('success')],
        ['type' => 'error', 'message' => session('error')],
    ])->filter(fn ($t) => filled($t['message']))->values();
@endphp

<div class="pointer-events-none fixed right-4 top-16 z-50 flex w-full max-w-sm flex-col gap-2"
     aria-live="polite"
     x-data
     x-init="@foreach ($flashToasts as $toast) $store.toasts.push(@js($toast['type']), @js($toast['message'])); @endforeach">
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div x-show="true"
             x-transition.opacity
             role="status"
             class="pointer-events-auto flex items-start gap-3 rounded-md border px-4 py-3 text-sm shadow-lg"
             :class="toast.type === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800'">
            <p class="flex-1" x-text="toast.message"></p>
            <button type="button" @click="$store.toasts.dismiss(toast.id)" class="text-lg leading-none opacity-60 hover:opacity-100" aria-label="Dismiss">&times;</button>
        </div>
    </template>
</div>
