<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Concerns\RunsAcademicsActions;
use App\Http\Controllers\Controller;
use App\Services\AcademicsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClassController extends Controller
{
    use RunsAcademicsActions;

    public function __construct(private readonly AcademicsService $academics) {}

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'level_id' => ['required', 'string'],
            'arm_id' => ['required', 'string'],
            'form_teacher_id' => ['nullable', 'string'],
        ]);

        return $this->attempt(
            fn () => $this->academics->createClass($data),
            'Class added.',
            fn ($class) => ['tab' => 'classes', 'session' => $class->session_id, 'class' => $class->getKey()],
        );
    }

    /** Soft-load the class panel without a full Academics page reload. */
    public function panel(Request $request, string $class): JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('TEACHER') && $user->staff_id && ! $user->isPlatformOwner()) {
            if (! $this->academics->teacherBelongsToClass($user->staff_id, $class)) {
                return response()->json(['message' => 'You can only open classes you teach.'], 403);
            }
        }

        try {
            $viewer = ($user->hasRole('TEACHER') && $user->staff_id && ! $user->isPlatformOwner())
                ? $user->staff_id
                : null;

            return response()->json($this->academics->presentClassPanel($class, $viewer));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function updateFormTeacher(Request $request, string $class): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['form_teacher_id' => ['nullable', 'string']]);

        return $this->attempt(
            fn () => $this->academics->updateFormTeacher($class, $data['form_teacher_id'] ?? null),
            'Form teacher saved.',
        );
    }
}
