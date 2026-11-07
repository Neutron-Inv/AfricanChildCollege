<?php

namespace App\Http\Controllers;

use App\Http\Requests\People\SetGuardianPasswordRequest;
use App\Services\PeopleService;
use Illuminate\Http\RedirectResponse;

class GuardianController extends Controller
{
    public function __construct(private PeopleService $people)
    {
    }

    public function password(SetGuardianPasswordRequest $request, string $guardian): RedirectResponse
    {
        $record = $this->people->findGuardian($guardian);
        abort_unless($record, 404);

        $saved = $this->people->setGuardianPassword($record, $request->validated('password'));

        return redirect()
            ->route('people', array_filter([
                'tab' => 'guardians',
                'q' => $request->validated('q') ?: $saved->full_name,
                'guardian' => $saved->getKey(),
            ]))
            ->with('success', "Password set for {$saved->full_name}.");
    }
}
