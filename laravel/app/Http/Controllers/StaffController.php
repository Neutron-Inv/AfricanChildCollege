<?php

namespace App\Http\Controllers;

use App\Http\Requests\People\StoreStaffRequest;
use App\Http\Requests\People\UpdateStaffRequest;
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

    public function update(UpdateStaffRequest $request, string $staff): RedirectResponse
    {
        $record = $this->people->findStaff($staff);
        abort_unless($record, 404);

        $saved = $this->people->updateStaff($record, $request->validated());

        return redirect()
            ->route('people', ['tab' => 'staff', 'staff' => $saved->getKey()])
            ->with('success', "Staff member {$saved->full_name} updated.");
    }
}
