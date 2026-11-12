<?php

namespace App\Http\Controllers;

use App\Http\Requests\People\StoreGuardianRequest;
use App\Http\Requests\People\StoreStudentRequest;
use App\Http\Requests\People\UpdateStudentRequest;
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

    public function update(UpdateStudentRequest $request, string $student): RedirectResponse
    {
        $record = $this->people->findStudent($student);
        abort_unless($record, 404);

        $saved = $this->people->updateStudent($record, $request->validated());

        return redirect()
            ->route('people', ['tab' => 'students', 'student' => $saved->getKey()])
            ->with('success', "Student {$saved->full_name} updated.");
    }

    public function destroy(string $student): RedirectResponse
    {
        $record = $this->people->findStudent($student);
        abort_unless($record, 404);

        $name = $record->full_name;
        $this->people->deleteStudent($record);

        return redirect()
            ->route('people', ['tab' => 'students'])
            ->with('success', "Student {$name} deleted.");
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
