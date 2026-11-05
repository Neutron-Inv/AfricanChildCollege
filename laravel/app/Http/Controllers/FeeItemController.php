<?php

namespace App\Http\Controllers;

use App\Models\FeeItem;
use App\Services\FeesService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FeeItemController extends Controller
{
    public function __construct(private readonly FeesService $fees) {}

    public function store(Request $request): RedirectResponse
    {
        try {
            $this->fees->saveFeeItem($this->validated($request));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fees', ['tab' => 'items'])->with('success', 'Fee item created.');
    }

    public function update(Request $request, string $feeItem): RedirectResponse
    {
        $item = FeeItem::query()->findOrFail($feeItem);

        try {
            $this->fees->saveFeeItem($this->validated($request), $item);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fees', ['tab' => 'items'])->with('success', 'Fee item updated.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'class_id' => ['nullable', 'string'],
            'session_id' => ['nullable', 'string'],
        ]);
    }
}
