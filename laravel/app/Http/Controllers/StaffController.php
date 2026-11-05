<?php

namespace App\Http\Controllers;

use App\Http\Requests\People\StoreStaffRequest;
use App\Services\PeopleService;
use Illuminate\Http\RedirectResponse;

class StaffController extends Controller
{
    public function __construct(private PeopleService $people)
    {
    }

    public function store(StoreStaffRequest $request): RedirectResponse
    {
        $staff = $this->people->createStaff($request->validated());

        return redirect()
            ->route('people', ['tab' => 'staff', 'staff' => $staff->getKey()])
            ->with('success', "Staff member {$staff->full_name} created.");
    }
}
