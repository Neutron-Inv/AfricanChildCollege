<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateInvoicesJob;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Term;
use App\Services\FeesService;
use App\Support\Tenant;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(private readonly FeesService $fees) {}

    /**
     * One class is generated straight away; a whole-school run is handed to GenerateInvoicesJob.
     */
    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'term_id' => ['required', 'string'],
            'class_id' => ['nullable', 'string'],
            'draft' => ['nullable', 'boolean'],
        ]);

        $term = Term::query()->find($data['term_id']);
        if (! $term) {
            return back()->withInput()->with('error', 'Choose a term of this school.');
        }

        $class = null;
        if (! empty($data['class_id'])) {
            $class = SchoolClass::query()->find($data['class_id']);
            if (! $class) {
                return back()->withInput()->with('error', 'Choose a class of this school.');
            }
        }

        $status = $request->boolean('draft') ? 'DRAFT' : 'ISSUED';
        $back = redirect()->route('fees', ['tab' => 'invoices', 'term_id' => $term->getKey()]);

        if ($class === null) {
            GenerateInvoicesJob::dispatch(Tenant::id(), $term->getKey(), null, $status);

            return $back->with('success', 'Invoice generation for the whole school has been queued. Refresh in a moment to see the invoices.');
        }

        try {
            $result = $this->fees->generateInvoices($term, $class, $status);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $back->with('success', sprintf(
            '%d invoice(s) created; %d already invoiced; %d without applicable fees.',
            $result['created'], $result['existing'], $result['no_fees'],
        ));
    }

    public function issue(string $invoice): RedirectResponse
    {
        return $this->act(fn () => $this->fees->issue(Invoice::query()->findOrFail($invoice)), 'Invoice issued.');
    }

    public function cancel(string $invoice): RedirectResponse
    {
        return $this->act(fn () => $this->fees->cancel(Invoice::query()->findOrFail($invoice)), 'Invoice cancelled.');
    }

    private function act(\Closure $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
