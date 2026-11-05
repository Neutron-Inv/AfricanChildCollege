<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Services\NoticesService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly NoticesService $notices) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $session = AcademicSession::where('is_current', true)->first();
        $student = $user->role === Role::STUDENT;
        $teacher = $user->role === Role::TEACHER;

        // Models below are tenant-scoped (BelongsToSchool).
        return view('dashboard', [
            'school' => Tenant::get(),
            'currentSession' => $session,
            'studentHome' => $student ? $this->studentHome($user, $session) : null,
            'teacherHome' => $teacher ? $this->teacherHome($user, $session) : null,
            'studentCount' => $student || $teacher ? null : Student::count(),
            'staffCount' => $student || $teacher ? null : Staff::count(),
            'classCount' => $student || $teacher ? null : SchoolClass::count(),
            'calendar' => $this->notices->calendar($this->notices->parseMonth($request->query('month'))),
            'upcomingEvents' => $this->notices->upcomingEvents(5),
            'latestNotices' => $this->notices->latest($user, 5),
        ]);
    }

    /** @return array{class: ?string, classSize: ?int, session: ?string, formTeacher: ?string, index: ?string} */
    private function studentHome(User $user, ?AcademicSession $session): array
    {
        $student = $user->student_id ? Student::query()->find($user->student_id) : null;
        $enrolment = $student && $session
            ? Enrolment::query()
                ->where('student_id', $student->getKey())
                ->where('session_id', $session->getKey())
                ->where('status', 'ACTIVE')
                ->with(['class.level', 'class.arm', 'class.formTeacher'])
                ->first()
            : null;
        $class = $enrolment?->class;

        return [
            'class' => $class?->display_name ?: null,
            'classSize' => $class
                ? Enrolment::query()->where('class_id', $class->getKey())->where('status', 'ACTIVE')->count()
                : null,
            'session' => $session?->name,
            'formTeacher' => $class?->formTeacher?->full_name,
            'index' => $student?->index_number,
        ];
    }

    /**
     * @return array{
     *     class: ?string,
     *     classSize: ?int,
     *     subjectCount: int,
     *     offerings: list<array{subject: string, classes: list<string>}>,
     *     session: ?string
     * }
     */
    private function teacherHome(User $user, ?AcademicSession $session): array
    {
        $classes = ($user->staff_id && $session)
            ? SchoolClass::query()
                ->where('session_id', $session->getKey())
                ->where('form_teacher_id', $user->staff_id)
                ->with(['level', 'arm'])
                ->get()
                ->sortBy(fn (SchoolClass $class) => $class->display_name)
                ->values()
            : collect();

        $offerings = ($user->staff_id && $session)
            ? ClassSubject::query()
                ->where('teacher_id', $user->staff_id)
                ->whereHas('class', fn ($query) => $query->where('session_id', $session->getKey()))
                ->with(['subject', 'class.level', 'class.arm'])
                ->get()
                ->groupBy(fn (ClassSubject $offering) => $offering->subject?->name)
                ->reject(fn ($rows, $name) => ! filled($name))
                ->sortKeys()
                ->map(fn ($rows, $name) => [
                    'subject' => $name,
                    'classes' => $rows
                        ->map(fn (ClassSubject $offering) => $offering->class?->display_name)
                        ->filter()
                        ->unique()
                        ->sort()
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all()
            : [];

        return [
            'class' => $classes->isEmpty() ? null : $classes->map(fn (SchoolClass $class) => $class->display_name)->implode(', '),
            'classSize' => $classes->isEmpty()
                ? null
                : Enrolment::query()->whereIn('class_id', $classes->modelKeys())->where('status', 'ACTIVE')->count(),
            'subjectCount' => count($offerings),
            'offerings' => $offerings,
            'session' => $session?->name,
        ];
    }
}
