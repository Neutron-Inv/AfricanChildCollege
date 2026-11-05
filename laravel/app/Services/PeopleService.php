<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * People module: students, staff and guardians for the active tenant.
 * All models used here are tenant-scoped via BelongsToSchool.
 */
class PeopleService
{
    public const STUDENT_STATUSES = ['ACTIVE', 'LEFT'];

    /** Roles an admin may assign when creating a staff member. */
    public const STAFF_ROLES = [Role::TEACHER, Role::ADMIN, Role::REGISTRAR, Role::ACCOUNTANT];

    private bool $sessionResolved = false;

    private ?AcademicSession $currentSession = null;

    public function currentSession(): ?AcademicSession
    {
        if (! $this->sessionResolved) {
            $this->currentSession = AcademicSession::where('is_current', true)->first();
            $this->sessionResolved = true;
        }

        return $this->currentSession;
    }

    /**
     * Classes of the current session, for filters and placement.
     *
     * @return Collection<int, SchoolClass>
     */
    public function classOptions(): Collection
    {
        $session = $this->currentSession();
        if (! $session) {
            return new Collection;
        }

        return SchoolClass::query()
            ->where('session_id', $session->getKey())
            ->with(['level', 'arm'])
            ->get()
            ->sortBy(fn (SchoolClass $c) => [$c->level?->sort_order ?? 0, $c->level?->name, $c->arm?->name])
            ->values();
    }

    // ---------------------------------------------------------------- students

    /**
     * @param  'class'|'all'|null  $teacherScope  class = form class only; all = form class plus students taking their subjects. Null is the office list.
     */
    public function students(string $status, ?string $classId, ?string $search, ?string $teacherStaffId = null, ?string $teacherScope = null): LengthAwarePaginator
    {
        $status = in_array($status, self::STUDENT_STATUSES, true) ? $status : 'ACTIVE';
        $sessionId = $this->currentSession()?->getKey();

        $query = Student::query()
            ->where('status', $teacherStaffId !== null ? 'ACTIVE' : $status)
            ->with(['enrolments' => fn ($q) => $q->where('session_id', $sessionId)->with(['class.level', 'class.arm'])])
            ->orderBy('full_name');

        if ($teacherStaffId !== null) {
            $this->applyTeacherScope($query, $teacherStaffId, $teacherScope === 'all' ? 'all' : 'class');
        } elseif ($classId) {
            $query->whereHas('enrolments', fn (Builder $q) => $q
                ->where('session_id', $sessionId)
                ->where('class_id', $classId));
        }

        if ($search !== null && trim($search) !== '') {
            $like = $this->like($search);
            $query->where(fn (Builder $q) => $q
                ->where('full_name', 'like', $like)
                ->orWhere('index_number', 'like', $like));
        }

        return $query->paginate(25)->withQueryString();
    }

    public function findStudent(string $id, ?string $teacherStaffId = null): ?Student
    {
        $student = Student::query()
            ->with([
                'studentGuardians.guardian',
                'enrolments' => fn ($q) => $q
                    ->where('session_id', $this->currentSession()?->getKey())
                    ->with(['class.level', 'class.arm']),
            ])
            ->find($id);

        if ($student && $teacherStaffId !== null && ! $this->teacherCanSeeStudent($teacherStaffId, $student->getKey())) {
            return null;
        }

        return $student;
    }

    /** Form class, or any class where the student takes a subject this teacher teaches. */
    public function teacherCanSeeStudent(string $staffId, string $studentId): bool
    {
        return $this->applyTeacherScope(Student::query()->whereKey($studentId), $staffId, 'all')->exists();
    }

    /**
     * Active students in the teacher's form class. Scope "all" also includes students taking their subjects in other classes.
     */
    private function applyTeacherScope(Builder $query, string $staffId, string $scope): Builder
    {
        $sessionId = $this->currentSession()?->getKey();
        if ($staffId === '' || ! $sessionId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas('enrolments', function (Builder $enrolment) use ($sessionId, $staffId, $scope) {
            $enrolment->where('session_id', $sessionId)->where('status', 'ACTIVE');
            $enrolment->where(function (Builder $match) use ($staffId, $scope) {
                $match->whereHas('class', fn (Builder $class) => $class->where('form_teacher_id', $staffId));
                if ($scope === 'all') {
                    $match->orWhereHas('subjects', fn (Builder $row) => $row->whereHas(
                        'classSubject',
                        fn (Builder $subject) => $subject->where('teacher_id', $staffId),
                    ));
                }
            });
        });
    }

    /**
     * Create a student, their STUDENT login and (optionally) a current-session placement.
     */
    public function createStudent(array $data): Student
    {
        $classId = $data['class_id'] ?? null;
        $class = null;

        if ($classId) {
            $session = $this->currentSession();
            $class = $session
                ? SchoolClass::where('session_id', $session->getKey())->find($classId)
                : null;

            if (! $class) {
                throw ValidationException::withMessages([
                    'class_id' => 'Choose a class from the current session.',
                ]);
            }
        }

        return DB::transaction(function () use ($data, $class) {
            $student = Student::create([
                'index_number' => $data['index_number'],
                'full_name' => $data['full_name'],
                'gender' => $data['gender'] ?? '',
                'phone' => $data['phone'] ?? '',
                'email' => $data['email'],
                'address' => $data['address'] ?? '',
                'birth_date' => $data['birth_date'] ?? null,
                'status' => 'ACTIVE',
            ]);

            User::create([
                'school_id' => Tenant::id(),
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => Role::STUDENT,
                'must_change_password' => true,
                'student_id' => $student->getKey(),
            ]);

            if ($class) {
                $this->place($student, $class);
            }

            AuditService::record('student.created', 'Student', $student->getKey(), [
                'index_number' => $student->index_number,
                'class_id' => $class?->getKey(),
            ]);

            return $student;
        });
    }

    /**
     * Active enrolment in the class's session plus its COMPULSORY subjects.
     */
    private function place(Student $student, SchoolClass $class): Enrolment
    {
        $enrolment = Enrolment::create([
            'student_id' => $student->getKey(),
            'class_id' => $class->getKey(),
            'session_id' => $class->session_id,
            'status' => 'ACTIVE',
        ]);

        ClassSubject::where('class_id', $class->getKey())
            ->where('kind', 'COMPULSORY')
            ->pluck('id')
            ->each(fn ($classSubjectId) => EnrolmentSubject::create([
                'enrolment_id' => $enrolment->getKey(),
                'class_subject_id' => $classSubjectId,
            ]));

        return $enrolment;
    }

    /**
     * Add a guardian to a student. Reuses an existing guardian with the same email.
     * A PARENT login is created only when a password is supplied.
     */
    public function addGuardian(Student $student, array $data): Guardian
    {
        return DB::transaction(function () use ($student, $data) {
            $email = $data['email'] ?? null;

            $guardian = $email ? Guardian::where('email', $email)->first() : null;

            if (! $guardian) {
                $guardian = Guardian::create([
                    'full_name' => $data['full_name'],
                    'phone' => $data['phone'] ?? '',
                    'email' => $email ?? '',
                ]);
            }

            $linked = StudentGuardian::where('student_id', $student->getKey())
                ->where('guardian_id', $guardian->getKey())
                ->exists();

            if ($linked) {
                throw ValidationException::withMessages([
                    'email' => 'This guardian is already linked to the student.',
                ])->errorBag('guardian');
            }

            StudentGuardian::create([
                'student_id' => $student->getKey(),
                'guardian_id' => $guardian->getKey(),
                'relationship' => ($data['relationship'] ?? '') ?: 'parent',
            ]);

            if (! empty($data['password']) && ! $guardian->user()->exists()) {
                if (User::where('school_id', Tenant::id())->where('email', $email)->exists()) {
                    throw ValidationException::withMessages([
                        'email' => 'That email already has a login at this school.',
                    ])->errorBag('guardian');
                }

                User::create([
                    'school_id' => Tenant::id(),
                    'email' => $email,
                    'password' => $data['password'],
                    'role' => Role::PARENT,
                    'must_change_password' => true,
                    'guardian_id' => $guardian->getKey(),
                ]);
            }

            AuditService::record('guardian.linked', 'Student', $student->getKey(), [
                'guardian_id' => $guardian->getKey(),
            ]);

            return $guardian;
        });
    }

    // ------------------------------------------------------------------- staff

    public function staff(?string $search = null, ?string $kind = null): LengthAwarePaginator
    {
        $query = Staff::query()
            ->with('user')
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($kind === 'teacher') {
            $query->where('is_teacher', true);
        } elseif ($kind === 'other') {
            $query->where('is_teacher', false);
        }

        if ($search !== null && trim($search) !== '') {
            $like = $this->like($search);
            $query->where(fn (Builder $q) => $q
                ->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('index_number', 'like', $like)
                ->orWhere('email', 'like', $like));
        }

        return $query->paginate(25)->withQueryString();
    }

    public function findStaff(string $id): ?Staff
    {
        $sessionId = $this->currentSession()?->getKey();

        return Staff::query()
            ->with([
                'user',
                'formClasses' => fn ($q) => $q
                    ->where('session_id', $sessionId ?? '')
                    ->with(['level', 'arm']),
                'classSubjects' => fn ($q) => $q
                    ->whereHas('class', fn (Builder $class) => $class->where('session_id', $sessionId ?? ''))
                    ->with(['subject', 'class.level', 'class.arm']),
            ])
            ->find($id);
    }

    public function createStaff(array $data): Staff
    {
        $role = Role::from($data['role']);

        return DB::transaction(function () use ($data, $role) {
            $staff = Staff::create([
                'index_number' => $data['index_number'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                // A TEACHER login is always flagged as a teacher.
                'is_teacher' => $role === Role::TEACHER || (bool) ($data['is_teacher'] ?? false),
                'status' => 'ACTIVE',
            ]);

            User::create([
                'school_id' => Tenant::id(),
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $role,
                'must_change_password' => true,
                'staff_id' => $staff->getKey(),
            ]);

            AuditService::record('staff.created', 'Staff', $staff->getKey(), [
                'index_number' => $staff->index_number,
                'role' => $role->value,
            ]);

            return $staff;
        });
    }

    // --------------------------------------------------------------- guardians

    /**
     * Search-only: returns nothing for an empty query.
     *
     * @return Collection<int, Guardian>
     */
    public function searchGuardians(?string $search, ?string $teacherStaffId = null): Collection
    {
        if ($search === null || trim($search) === '') {
            return new Collection;
        }

        $like = $this->like($search);

        $query = Guardian::query()
            ->where(fn (Builder $q) => $q
                ->where('full_name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('email', 'like', $like))
            ->orderBy('full_name')
            ->limit(50);

        if ($teacherStaffId !== null) {
            $allowed = fn () => $this->applyTeacherScope(Student::query(), $teacherStaffId, 'all')->select('students.id');
            $query->whereHas('students', fn (Builder $students) => $students->whereIn('students.id', $allowed()));
            $query->with(['students' => fn ($students) => $students->whereIn('students.id', $allowed())]);
        } else {
            $query->with('students');
        }

        return $query->get();
    }

    private function like(string $term): string
    {
        $term = addcslashes(trim($term), '\\%_');

        return '%'.$term.'%';
    }
}
