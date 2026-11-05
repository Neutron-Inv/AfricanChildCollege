@php
    $className = fn ($c) => trim($c->display_name.' · '.($c->session?->name ?? ''), ' ·');
@endphp

@if ($canManage)
    <form method="POST" action="{{ route('fees.items.store') }}" class="card mb-5">
        @csrf
        <h3 class="mb-3 text-base font-semibold">New fee item</h3>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="fi_name" class="block text-xs font-medium text-gray-600">Name</label>
                <input id="fi_name" name="name" value="{{ old('name') }}" required maxlength="150" class="form-input mt-1" placeholder="Tuition">
            </div>
            <div>
                <label for="fi_amount" class="block text-xs font-medium text-gray-600">Amount</label>
                <input id="fi_amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" required class="form-input mt-1">
            </div>
            <div>
                <label for="fi_session" class="block text-xs font-medium text-gray-600">Session (optional)</label>
                <select id="fi_session" name="session_id" class="form-input mt-1">
                    <option value="">Any session</option>
                    @foreach ($sessions as $s)
                        <option value="{{ $s->id }}" @selected(old('session_id') === $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="fi_class" class="block text-xs font-medium text-gray-600">Class (optional)</label>
                <select id="fi_class" name="class_id" class="form-input mt-1">
                    <option value="">All classes</option>
                    @foreach ($classes as $c)
                        <option value="{{ $c->id }}" @selected(old('class_id') === $c->id)>{{ $className($c) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <p class="mt-2 text-xs text-gray-500">Leave both blank for a whole-school fee. A class fee applies to that class only and overrides the session choice.</p>
        <div class="mt-4"><button class="btn-primary">Add fee item</button></div>
    </form>
@endif

<div class="card">
    @if ($items->isEmpty())
        <p class="text-sm text-gray-500">No fee items yet.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="py-2 pr-3 font-medium">Name</th>
                        <th class="py-2 pr-3 font-medium">Scope</th>
                        <th class="py-2 pr-3 text-right font-medium">Amount</th>
                        @if ($canManage)<th class="py-2 text-right font-medium"><span class="sr-only">Actions</span></th>@endif
                    </tr>
                </thead>
                @foreach ($items as $item)
                    <tbody x-data="{ editing: false }" class="divide-y divide-black/5 border-b border-black/5">
                        <tr>
                            <td class="py-2 pr-3 font-medium">{{ $item->name }}</td>
                            <td class="py-2 pr-3 text-gray-600">{{ app(\App\Services\FeesService::class)->scopeLabel($item) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format((float) $item->amount, 2) }}</td>
                            @if ($canManage)
                                <td class="py-2 text-right"><button type="button" class="link-primary text-sm font-medium" @click="editing = ! editing">Edit</button></td>
                            @endif
                        </tr>
                        @if ($canManage)
                            <tr x-show="editing" x-cloak>
                                <td colspan="4" class="bg-gray-50 p-3">
                                    <form method="POST" action="{{ route('fees.items.update', $item->id) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
                                        @csrf @method('PUT')
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600">Name</label>
                                            <input name="name" value="{{ $item->name }}" required maxlength="150" class="form-input mt-1">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600">Amount</label>
                                            <input name="amount" type="number" step="0.01" min="0.01" value="{{ $item->amount }}" required class="form-input mt-1">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600">Session</label>
                                            <select name="session_id" class="form-input mt-1">
                                                <option value="">Any session</option>
                                                @foreach ($sessions as $s)
                                                    <option value="{{ $s->id }}" @selected($item->session_id === $s->id)>{{ $s->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600">Class</label>
                                            <select name="class_id" class="form-input mt-1">
                                                <option value="">All classes</option>
                                                @foreach ($classes as $c)
                                                    <option value="{{ $c->id }}" @selected($item->class_id === $c->id)>{{ $className($c) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="flex gap-2">
                                            <button class="btn-primary !py-2">Save</button>
                                            <button type="button" class="btn-secondary !py-2" @click="editing = false">Cancel</button>
                                        </div>
                                    </form>
                                    <p class="mt-2 text-xs text-gray-500">Changes affect invoices generated from now on, not existing ones.</p>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @endforeach
            </table>
        </div>
    @endif
</div>
