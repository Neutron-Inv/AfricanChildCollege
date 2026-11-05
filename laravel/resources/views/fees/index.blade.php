<x-school-layout title="Fees">
    <div class="mb-5">
        <h2 class="text-xl font-semibold">Fees</h2>
        <p class="text-sm text-gray-500">
            {{ $isParent ? 'Invoices and payments for your children.' : 'Fee items, invoices and payments.' }}
        </p>
    </div>

    <nav class="mb-5 flex gap-1 overflow-x-auto border-b border-black/10" aria-label="Fees sections">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('fees', ['tab' => $key]) }}"
               class="-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition {{ $tab === $key ? 'text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-800' }}"
               @if ($tab === $key) style="border-color: var(--primary)" aria-current="page" @endif>
                {{ $label }}
            </a>
        @endforeach
    </nav>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $canManage)
        <p class="mb-4 text-xs text-gray-500">You have view-only access to Fees.</p>
    @endif

    @include('fees.tabs.'.$tab)
</x-school-layout>
