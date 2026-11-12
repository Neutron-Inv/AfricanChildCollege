<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Controllers\Concerns\RunsEnrolmentActions;
use App\Models\Enrolment;
use App\Services\EnrolmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EnrolmentController extends Controller
{
    use RunsEnrolmentActions;

    public function __construct(private readonly EnrolmentService $enrolment) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $canManage = $user->isPlatformOwner() || $user->hasRole(Role::ADMIN, Role::REGISTRAR);
        $session = $this->enrolment->resolveSession($request->query('session'));
        $classes = $this->enrolment->classes($session?->getKey());
        if (! $canManage) {
            $staffId = (string) ($user->staff_id ?? '');
            $classes = $classes
                ->filter(fn ($class) => $staffId !== '' && $class->form_teacher_id === $staffId)
                ->values();
        }

        $classId = (string) $request->query('class', '');
        $classId = $classes->contains('id', $classId) ? $classId : '';
        $status = in_array($request->query('status'), EnrolmentService::STATUSES, true) ? $request->query('status') : '';
        $q = trim((string) $request->query('q', ''));
        $perPage = EnrolmentService::normalisePageSize($request->query('per_page'));

        $selected = ($id = $request->query('enrolment')) ? $this->enrolment->findEnrolment((string) $id) : null;
        if ($selected && $selected->session_id !== $session?->getKey()) {
            $selected = null;
        }
        if ($selected && ! $canManage && ! $classes->contains('id', $selected->class_id)) {
            $selected = null;
        }

        return view('enrolment.index', [
            'sessions' => $this->enrolment->sessions(),
            'session' => $session,
            'classes' => $classes,
            'classId' => $classId,
            'status' => $status,
            'q' => $q,
            'perPage' => $perPage,
            'pageSizes' => EnrolmentService::PAGE_SIZES,
            'canManage' => $canManage,
            'enrolments' => $session ? $this->enrolment->list($session->getKey(), $classId ?: null, $status ?: null, $q, $perPage, $canManage ? null : $classes->modelKeys()) : null,
            'unplaced' => $session ? $this->enrolment->unplacedStudents($session->getKey()) : collect(),
            'selected' => $selected,
            'electives' => $selected ? $this->enrolment->electiveOptions($selected) : collect(),
            'subjects' => $selected ? $this->enrolment->subjectsFor($selected) : collect(),
        ]);
    }

    public function place(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'string'],
            'class_id' => ['required', 'string'],
            'return_to' => ['nullable', 'in:people'],
        ]);

        return $this->attempt(
            fn () => $this->enrolment->place($data['student_id'], $data['class_id']),
            fn ($e) => "{$e->student->full_name} placed in {$e->class->display_name}.",
            fn ($e) => ($data['return_to'] ?? null) === 'people'
                ? redirect()->route('people', ['tab' => 'students', 'student' => $e->student_id])
                : redirect()->route('enrolment', $this->keep($request, ['session' => $e->session_id, 'enrolment' => $e->getKey()])),
        );
    }

    public function move(Request $request, string $enrolment): RedirectResponse
    {
        $data = $request->validate(['class_id' => ['required', 'string']]);

        return $this->attempt(
            fn () => $this->enrolment->move($enrolment, $data['class_id']),
            fn ($e) => "Moved to {$e->class->display_name}.",
        );
    }

    public function electives(Request $request, string $enrolment): RedirectResponse
    {
        $record = $this->enrolment->findEnrolment($enrolment);
        abort_unless($record, 404);
        $this->ensureCanEditElectives($request, $record);

        $data = $request->validate([
            'class_subject_ids' => ['nullable', 'array'],
            'class_subject_ids.*' => ['string'],
        ]);

        return $this->attempt(
            fn () => $this->enrolment->syncElectives($enrolment, $data['class_subject_ids'] ?? []),
            fn ($r) => "Electives saved ({$r['added']} added, {$r['removed']} removed).",
        );
    }

    public function leave(string $enrolment): RedirectResponse
    {
        return $this->attempt(fn () => $this->enrolment->markLeft($enrolment), 'Student marked as left.');
    }

    /** A form teacher may set electives only for a class they form-teach. The office may set any. */
    private function ensureCanEditElectives(Request $request, Enrolment $enrolment): void
    {
        $user = $request->user();
        if ($user->isPlatformOwner() || $user->hasRole(Role::ADMIN, Role::REGISTRAR)) {
            return;
        }

        abort_unless(
            $user->hasRole(Role::TEACHER)
                && $user->staff_id
                && $enrolment->class?->form_teacher_id === $user->staff_id,
            403,
            'You can only choose subjects for students in your form class.',
        );
    }

    /** Keep the current list filters when redirecting after an action. */
    private function keep(Request $request, array $extra): array
    {
        return array_filter(
            $extra + $request->only(['class', 'status', 'q', 'per_page']),
            fn ($v) => $v !== null && $v !== '',
        );
    }
}
