<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Concerns\RunsAcademicsActions;
use App\Http\Controllers\Controller;
use App\Services\AcademicsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClassSubjectController extends Controller
{
    use RunsAcademicsActions;

    public function __construct(private readonly AcademicsService $academics) {}

    public function store(Request $request, string $class): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'subject_id' => ['required', 'string'],
            'teacher_id' => ['nullable', 'string'],
            'kind' => ['required', Rule::in([AcademicsService::KIND_COMPULSORY, AcademicsService::KIND_ELECTIVE])],
        ]);

        return $this->attempt(
            fn () => $this->academics->createClassSubject($data + ['class_id' => $class]),
            'Subject added to class.',
            json: fn () => ['panel' => $this->academics->presentClassPanel($class)],
        );
    }

    public function sync(Request $request, string $class): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'form_teacher_id' => ['nullable', 'string'],
            'subjects' => ['present', 'array'],
            'subjects.*.id' => ['required', 'string'],
            'subjects.*.kind' => ['required', Rule::in([AcademicsService::KIND_COMPULSORY, AcademicsService::KIND_ELECTIVE])],
            'subjects.*.teacher_id' => ['nullable', 'string'],
        ]);

        return $this->attempt(
            fn () => $this->academics->syncClassPanel($class, $data['form_teacher_id'] ?? null, $data['subjects']),
            'Class subjects saved.',
            json: fn (array $panel) => ['panel' => $panel],
        );
    }

    public function updateKind(Request $request, string $classSubject): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([AcademicsService::KIND_COMPULSORY, AcademicsService::KIND_ELECTIVE])],
        ]);

        return $this->attempt(
            fn () => $this->academics->setSubjectKind($classSubject, $data['kind']),
            'Subject marked '.strtolower($data['kind']).'.',
        );
    }

    public function updateTeacher(Request $request, string $classSubject): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['teacher_id' => ['nullable', 'string']]);

        return $this->attempt(
            fn () => $this->academics->setSubjectTeacher($classSubject, $data['teacher_id'] ?? null),
            'Subject teacher saved.',
        );
    }

    public function destroy(string $classSubject): RedirectResponse|JsonResponse
    {
        return $this->attempt(
            function () use ($classSubject) {
                $removed = $this->academics->removeClassSubject($classSubject);

                // class_id is still on the model after delete(); return it for the JSON panel refresh.
                return ['class_id' => (string) $removed->class_id];
            },
            'Subject removed from class.',
            json: fn (array $result) => ['panel' => $this->academics->presentClassPanel($result['class_id'])],
        );
    }
}
