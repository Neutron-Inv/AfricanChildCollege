<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\FeesService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly FeesService $fees) {}

    public function store(Request $request, string $invoice): RedirectResponse
    {
        $invoice = Invoice::query()->findOrFail($invoice);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'method' => ['required', 'string', 'max:20'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->fees->recordPayment($request->user(), $invoice, $data);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment recorded.');
    }
}
