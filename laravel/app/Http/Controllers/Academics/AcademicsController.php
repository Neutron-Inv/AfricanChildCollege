<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Services\AcademicsService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AcademicsController extends Controller
{
    public const TABS = [
        'classes' => 'Classes',
        'subjects' => 'Subjects',
        'sessions' => 'Sessions',
        'departments' => 'Departments',
        'rooms' => 'Rooms',
        'grading' => 'Grading',
    ];

    public function index(Request $request, AcademicsService $academics): View
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'classes';
        $user = $request->user();

        $data = [
            'school' => Tenant::get(),
            'tab' => $tab,
            'tabs' => self::TABS,
            'canEdit' => $user->isPlatformOwner() || $user->hasRole('ADMIN', 'REGISTRAR'),
            'canEditGrading' => $user->isPlatformOwner() || $user->hasRole('ADMIN'),
        ];

        return view('academics.index', $data + match ($tab) {
            'classes' => $this->classesData($request, $academics),
            'subjects' => $this->subjectsData($request, $academics),
            'sessions' => ['sessions' => $academics->sessions()],
            'departments' => ['departments' => $academics->departments(), 'teachers' => $academics->teachers()],
            'rooms' => ['rooms' => $academics->rooms()],
            'grading' => ['scale' => $academics->defaultScale()],
        });
    }

    private function classesData(Request $request, AcademicsService $academics): array
    {
        $user = $request->user();
        [$sessions, $selected] = $this->selectedSession($request, $academics);

        // Teachers only see classes they teach (or form-teach); office sees the full list.
        $teacherStaffId = $user->hasRole('TEACHER') && $user->staff_id && ! $user->isPlatformOwner()
            ? $user->staff_id
            : null;

        $classes = $academics->classes($selected?->getKey(), $teacherStaffId);
        $class = null;
        if ($request->query('class') && $selected) {
            $class = $academics->findClass($request->query('class'), $selected->getKey());
            if ($class && $teacherStaffId && ! $academics->teacherBelongsToClass($teacherStaffId, $class->getKey())) {
                $class = null;
            }
            if ($class && ! $classes->contains('id', $class->getKey())) {
                $class = null;
            }
        }

        return [
            'sessions' => $sessions,
            'selectedSession' => $selected,
            'classes' => $classes,
            'class' => $class,
            'classSubjects' => $class ? $academics->classSubjects($class->getKey()) : collect(),
            'teachers' => $teacherStaffId ? collect() : $academics->teachers(),
            'levels' => $academics->levels(),
            'arms' => $academics->arms(),
            'subjects' => $teacherStaffId ? collect() : $academics->subjects(),
            'teacherScoped' => $teacherStaffId !== null,
            'viewerStaffId' => $teacherStaffId,
        ];
    }

    private function subjectsData(Request $request, AcademicsService $academics): array
    {
        [$sessions, $selected] = $this->selectedSession($request, $academics);

        return [
            'sessions' => $sessions,
            'selectedSession' => $selected,
            'catalogue' => $academics->subjectCatalogue($selected?->getKey()),
            'departments' => $academics->departments(),
            'selectedSubjectId' => $request->query('subject'),
        ];
    }

    /** @return array{0: \Illuminate\Database\Eloquent\Collection, 1: ?\App\Models\AcademicSession} */
    private function selectedSession(Request $request, AcademicsService $academics): array
    {
        $sessions = $academics->sessions();
        $selected = $sessions->firstWhere('id', $request->query('session'))
            ?? $sessions->firstWhere('is_current', true)
            ?? $sessions->first();

        return [$sessions, $selected];
    }
}
