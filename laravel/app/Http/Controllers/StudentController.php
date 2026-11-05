<?php

namespace App\Http\Controllers;

use App\Http\Requests\People\StoreGuardianRequest;
use App\Http\Requests\People\StoreStudentRequest;
use App\Services\PeopleService;
use Illuminate\Http\RedirectResponse;

class StudentController extends Controller
{
    public function __construct(private PeopleService $people)
    {
    }

    public function store(StoreStudentRequest $request): RedirectResponse
    {
        $student = $this->people->createStudent($request->validated());

        return redirect()
            ->route('people', ['tab' => 'students', 'student' => $student->getKey()])
            ->with('success', "Student {$student->full_name} created.");
    }

    public function storeGuardian(StoreGuardianRequest $request, string $student): RedirectResponse
    {
        $record = $this->people->findStudent($student);
        abort_unless($record, 404);

        $guardian = $this->people->addGuardian($record, $request->validated());

        return redirect()
            ->route('people', ['tab' => 'students', 'student' => $record->getKey()])
            ->with('success', "Guardian {$guardian->full_name} added.");
    }
}
