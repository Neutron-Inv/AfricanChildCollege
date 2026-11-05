@php
    $badge = [
        'DRAFT' => 'bg-gray-100 text-gray-700',
        'ISSUED' => 'bg-blue-100 text-blue-800',
        'PARTIAL' => 'bg-amber-100 text-amber-800',
        'PAID' => 'bg-green-100 text-green-800',
        'CANCELLED' => 'bg-red-100 text-red-800',
    ];
    $money = fn ($v) => number_format((float) $v, 2);
@endphp

<div class="mb-5 grid gap-4 sm:grid-cols-3">
    <div class="card"><div class="text-xs uppercase tracking-wide text-gray-500">Billed</div><div class="mt-1 text-xl font-semibold tabular-nums">{{ $money($totals['billed']) }}</div></div>
    <div class="card"><div class="text-xs uppercase tracking-wide text-gray-500">Collected</div><div class="mt-1 text-xl font-semibold tabular-nums">{{ $money($totals['collected']) }}</div></div>
    <div class="card"><div class="text-xs uppercase tracking-wide text-gray-500">Outstanding</div><div class="mt-1 text-xl font-semibold tabular-nums">{{ $money($totals['outstanding']) }}</div></div>
</div>

<form method="GET" action="{{ route('fees') }}" class="card mb-5 flex flex-wrap items-end gap-4">
    <input type="hidden" name="tab" value="invoices">
    <div>
        <label for="f_term" class="block text-xs font-medium text-gray-600">Term</label>
        <select id="f_term" name="term_id" class="form-input mt-1">
            <option value="">All terms</option>
            @foreach ($terms as $t)
                <option value="{{ $t->id }}" @selected(($filters['term_id'] ?? '') === $t->id)>{{ $t->session?->name }} · {{ $t->name ?: 'Term '.$t->sequence }}</option>
            @endforeach
        </select>
    </div>
    @if ($canViewAll)
        <div>
            <label for="f_class" class="block text-xs font-medium text-gray-600">Class</label>
            <select id="f_class" name="class_id" class="form-input mt-1">
                <option value="">All classes</option>
                @foreach ($classes as $c)
                    <option value="{{ $c->id }}" @selected(($filters['class_id'] ?? '') === $c->id)>{{ trim($c->display_name.' · '.($c->session?->name ?? ''), ' ·') }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div>
        <label for="f_status" class="block text-xs font-medium text-gray-600">Status</label>
        <select id="f_status" name="status" class="form-input mt-1">
            <option value="">Any status</option>
            @foreach ($statuses as $s)
                @continue($isParent && $s === 'DRAFT')
                <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst(strtolower($s)) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="f_q" class="block text-xs font-medium text-gray-600">Student</label>
        <input id="f_q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-input mt-1" placeholder="Name or index number">
    </div>
    <button class="btn-secondary">Filter</button>
</form>

<div class="card">
    @if ($invoices->isEmpty())
        <p class="text-sm text-gray-500">No invoices found.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="py-2 pr-3 font-medium">Student</th>
                        <th class="py-2 pr-3 font-medium">Class · Term</th>
                        <th class="py-2 pr-3 text-right font-medium">Amount</th>
                        <th class="py-2 pr-3 text-right font-medium">Balance</th>
                        <th class="py-2 pr-3 font-medium">Status</th>
                        <th class="py-2 text-right font-medium"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                @foreach ($invoices as $invoice)
                    @php
                        $canPay = $canManage && in_array($invoice->status, ['ISSUED', 'PARTIAL'], true) && $invoice->balance > 0;
                    @endphp
                    <tbody x-data="{ open: {{ old('invoice_id') === $invoice->id ? 'true' : 'false' }} }" class="border-b border-black/5">
                        <tr>
                            <td class="py-2 pr-3">
                                <div class="font-medium">{{ $invoice->student?->full_name }}</div>
                                <div class="text-xs text-gray-500">{{ $invoice->student?->index_number }}</div>
                            </td>
                            <td class="py-2 pr-3 text-gray-600">{{ $invoice->class?->display_name }} · {{ $invoice->term?->name ?: 'Term '.$invoice->term?->sequence }} ({{ $invoice->term?->session?->name }})</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $money($invoice->total_amount) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $invoice->status === 'CANCELLED' ? '—' : $money($invoice->balance) }}</td>
                            <td class="py-2 pr-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$invoice->status] ?? 'bg-gray-100 text-gray-700' }}">{{ ucfirst(strtolower($invoice->status)) }}</span></td>
                            <td class="py-2 text-right"><button type="button" class="link-primary text-sm font-medium" @click="open = ! open" x-text="open ? 'Hide' : 'Details'">Details</button></td>
                        </tr>
                        <tr x-show="open" x-cloak>
                            <td colspan="6" class="bg-gray-50 p-4">
                                <div class="grid gap-6 lg:grid-cols-2">
                                    <div>
                                        <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Lines</h4>
                                        <table class="w-full text-sm">
                                            @foreach ($invoice->lines as $line)
                                                <tr><td class="py-1 pr-3">{{ $line->description }}</td><td class="py-1 text-right tabular-nums">{{ $money($line->amount) }}</td></tr>
                                            @endforeach
                                            <tr class="border-t border-black/10 font-semibold"><td class="py-1 pr-3">Total</td><td class="py-1 text-right tabular-nums">{{ $money($invoice->total_amount) }}</td></tr>
                                            <tr><td class="py-1 pr-3 text-gray-600">Paid</td><td class="py-1 text-right tabular-nums">{{ $money($invoice->paid_amount) }}</td></tr>
                                        </table>

                                        <h4 class="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">Payments</h4>
                                        @forelse ($invoice->payments->sortBy('paid_on') as $payment)
                                            <div class="flex justify-between gap-3 py-1 text-sm">
                                                <span>{{ $payment->paid_on->format('j M Y') }} · {{ \App\Services\FeesService::METHODS[$payment->method] ?? $payment->method }}@if ($payment->reference) · {{ $payment->reference }}@endif</span>
                                                <span class="tabular-nums">{{ $money($payment->amount) }}</span>
                                            </div>
                                        @empty
                                            <p class="text-sm text-gray-500">No payments yet.</p>
                                        @endforelse
                                    </div>

                                    @if ($canManage)
                                        <div>
                                            @if ($canPay)
                                                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Record payment</h4>
                                                <form method="POST" action="{{ route('fees.payments.store', $invoice->id) }}" class="grid gap-3 sm:grid-cols-2">
                                                    @csrf
                                                    <input type="hidden" name="invoice_id" value="{{ $invoice->id }}">
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-600">Amount</label>
                                                        <input name="amount" type="number" step="0.01" min="0.01" max="{{ $invoice->balance }}" value="{{ old('invoice_id') === $invoice->id ? old('amount') : $invoice->balance }}" required class="form-input mt-1">
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-600">Method</label>
                                                        <select name="method" class="form-input mt-1">
                                                            @foreach ($methods as $key => $label)
                                                                <option value="{{ $key }}" @selected(old('method') === $key)>{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-600">Reference</label>
                                                        <input name="reference" maxlength="100" value="{{ old('invoice_id') === $invoice->id ? old('reference') : '' }}" class="form-input mt-1">
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-600">Date</label>
                                                        <input name="paid_on" type="date" value="{{ old('invoice_id') === $invoice->id ? old('paid_on') : now()->toDateString() }}" required class="form-input mt-1">
                                                    </div>
                                                    <div class="sm:col-span-2"><button class="btn-primary">Record payment</button></div>
                                                </form>
                                            @endif

                                            <div class="mt-4 flex gap-3">
                                                @if ($invoice->status === 'DRAFT')
                                                    <form method="POST" action="{{ route('fees.invoices.issue', $invoice->id) }}">@csrf<button class="btn-secondary !py-1.5">Issue invoice</button></form>
                                                @endif
                                                @if ($invoice->status !== 'CANCELLED' && $invoice->payments->isEmpty())
                                                    <form method="POST" action="{{ route('fees.invoices.cancel', $invoice->id) }}" onsubmit="return confirm('Cancel this invoice?')">@csrf<button class="btn-secondary !py-1.5 !text-red-700">Cancel invoice</button></form>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    </tbody>
                @endforeach
            </table>
        </div>

        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</div>
