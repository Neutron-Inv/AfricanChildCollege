<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Concerns\RunsAcademicsActions;
use App\Http\Controllers\Controller;
use App\Services\AcademicsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Simple catalogue lists: subjects, departments, rooms. */
class CatalogueController extends Controller
{
    use RunsAcademicsActions;

    public function __construct(private readonly AcademicsService $academics) {}

    public function storeSubject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'department_id' => ['nullable', 'string'],
        ]);

        return $this->attempt(
            fn () => $this->academics->createSubject($data),
            'Subject created.',
            fn ($subject) => ['tab' => 'subjects', 'subject' => $subject->getKey()],
        );
    }

    public function updateSubject(Request $request, string $subject): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'department_id' => ['nullable', 'string'],
            'session_id' => ['nullable', 'string'],
            'offerings' => ['sometimes', 'array'],
            'offerings.*.id' => ['required', 'string'],
            'offerings.*.kind' => ['required', Rule::in([AcademicsService::KIND_COMPULSORY, AcademicsService::KIND_ELECTIVE])],
            'offerings.*.teacher_id' => ['nullable', 'string'],
        ]);

        return $this->attempt(
            fn () => $this->academics->updateSubject($subject, $data, $data['session_id'] ?? null),
            'Subject saved.',
            json: fn (array $row) => ['subject' => $row],
        );
    }

    public function destroySubject(string $subject): RedirectResponse|JsonResponse
    {
        return $this->attempt(
            fn () => $this->academics->deleteSubject($subject),
            'Subject deleted.',
            ['tab' => 'subjects'],
        );
    }

    public function storeClass(Request $request, string $subject): RedirectResponse|JsonResponse
    {
        $kind = ['required', Rule::in([AcademicsService::KIND_COMPULSORY, AcademicsService::KIND_ELECTIVE])];

        if ($request->exists('classes')) {
            $data = $request->validate([
                'classes' => ['required', 'array', 'min:1'],
                'classes.*.class_id' => ['required', 'string'],
                'classes.*.teacher_id' => ['nullable', 'string'],
                'classes.*.kind' => $kind,
                'session_id' => ['nullable', 'string'],
            ]);
            $rows = $data['classes'];
        } else {
            $data = $request->validate([
                'class_id' => ['required', 'string'],
                'teacher_id' => ['nullable', 'string'],
                'kind' => $kind,
                'session_id' => ['nullable', 'string'],
            ]);
            $rows = [$data];
        }

        return $this->attempt(
            fn () => $this->academics->addClassesToSubject($subject, $rows, $data['session_id'] ?? null),
            count($rows) === 1 ? 'Class added to subject.' : 'Classes added to subject.',
            json: fn (array $row) => ['subject' => $row],
        );
    }

    public function destroyClass(Request $request, string $subject, string $classSubject): RedirectResponse|JsonResponse
    {
        return $this->attempt(
            fn () => $this->academics->removeClassFromSubject($subject, $classSubject, $request->input('session_id')),
            'Class removed from subject.',
            json: fn (array $row) => ['subject' => $row],
        );
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'hod_id' => ['nullable', 'string'],
        ]);

        return $this->attempt(fn () => $this->academics->createDepartment($data), 'Department created.', ['tab' => 'departments']);
    }

    public function updateDepartment(Request $request, string $department): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'hod_id' => ['nullable', 'string'],
        ]);

        return $this->attempt(
            fn () => $this->academics->updateDepartment($department, $data),
            'Department saved.',
            ['tab' => 'departments'],
        );
    }

    public function destroyDepartment(string $department): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->academics->deleteDepartment($department),
            'Department deleted.',
            ['tab' => 'departments'],
        );
    }

    public function storeRoom(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'capacity' => ['nullable', 'integer', 'between:0,10000'],
        ]);

        return $this->attempt(fn () => $this->academics->createRoom($data), 'Room created.');
    }
}
