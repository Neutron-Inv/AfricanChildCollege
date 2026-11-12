<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
use App\Models\Guardian;
use App\Models\LegacyLink;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\SubjectScore;
use App\Models\TermReport;
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
    public const STUDENT_STATUSES = ['ACTIVE', 'LEFT', 'GRADUATED'];

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

    /**
     * One past the highest numeric index in this school.
     * A trailing number is incremented and its prefix kept, so 2152 becomes 2153 and JS005 becomes JS006.
     * The highest number wins when prefixes differ. Leading zeros are kept until the number needs another digit.
     */
    public function nextIndexNumber(): string
    {
        $bestValue = null;
        $bestWidth = 0;
        $bestPrefix = '';

        foreach (Student::query()->pluck('index_number') as $index) {
            if (! preg_match('/^(.*?)(\d+)$/u', (string) $index, $match)) {
                continue;
            }

            $digits = $match[2];
            $widerTie = $bestValue !== null && $this->sameNumber($digits, $bestValue) && strlen($digits) > $bestWidth;
            if ($bestValue === null || $this->numberGreater($digits, $bestValue) || $widerTie) {
                $bestValue = $digits;
                $bestWidth = strlen($digits);
                $bestPrefix = $match[1];
            }
        }

        if ($bestValue === null) {
            return '1';
        }

        $value = $bestValue;
        do {
            $value = $this->incrementDigits($value);
            $candidate = $bestPrefix.str_pad($value, $bestWidth, '0', STR_PAD_LEFT);
        } while (Student::query()->where('index_number', $candidate)->exists());

        return $candidate;
    }

    /**
     * Elective subjects offered by each class, keyed by class id.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @return array<string, list<array{id: string, name: string}>>
     */
    public function electivesByClass(Collection $classes): array
    {
        if ($classes->isEmpty()) {
            return [];
        }

        $grouped = ClassSubject::query()
            ->whereIn('class_id', $classes->modelKeys())
            ->where('kind', 'ELECTIVE')
            ->with('subject')
            ->get()
            ->groupBy('class_id');

        $offers = [];
        foreach ($classes as $class) {
            $offers[$class->getKey()] = $grouped->get($class->getKey(), collect())
                ->sortBy(fn (ClassSubject $row) => strtolower($row->subject?->name ?? ''))
                ->map(fn (ClassSubject $row) => [
                    'id' => $row->getKey(),
                    'name' => $row->subject?->name ?? 'Subject',
                ])
                ->values()
                ->all();
        }

        return $offers;
    }

    // ---------------------------------------------------------------- students

    /**
     * @param  'class'|'all'|null  $teacherScope  class = form class only; all = form class plus students taking their subjects. Null is the office list.
     */
    public function students(string $status, ?string $classId, ?string $search, ?string $teacherStaffId = null, ?string $teacherScope = null, string $sort = 'name', string $dir = 'asc'): LengthAwarePaginator
    {
        $status = in_array($status, self::STUDENT_STATUSES, true) ? $status : 'ACTIVE';
        $sessionId = $this->currentSession()?->getKey();
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        $query = Student::query()
            ->with(['enrolments' => fn ($q) => $q->where('session_id', $sessionId)->with(['class.level', 'class.arm'])]);

        if ($sort === 'class' && $sessionId) {
            $query->leftJoin('enrolments as sort_enrolments', function ($join) use ($sessionId) {
                $join->on('sort_enrolments.student_id', '=', 'students.id')
                    ->where('sort_enrolments.session_id', '=', $sessionId);
            })
                ->leftJoin('classes as sort_classes', 'sort_classes.id', '=', 'sort_enrolments.class_id')
                ->leftJoin('levels as sort_levels', 'sort_levels.id', '=', 'sort_classes.level_id')
                ->leftJoin('arms as sort_arms', 'sort_arms.id', '=', 'sort_classes.arm_id')
                ->select('students.*')
                ->orderByRaw('CASE WHEN sort_levels.id IS NULL THEN 1 ELSE 0 END')
                ->orderBy('sort_levels.sort_order', $dir)
                ->orderBy('sort_levels.name', $dir)
                ->orderBy('sort_arms.name', $dir)
                ->orderBy('students.full_name');
        } else {
            $query->orderBy('full_name', $dir);
        }

        if ($teacherStaffId !== null || $status === 'ACTIVE') {
            $query->where('students.status', 'ACTIVE');
        } elseif ($status === 'GRADUATED') {
            $query->where('students.departure', 'GRADUATED');
        } else {
            $query->where('students.status', 'LEFT')->where(fn (Builder $q) => $q->whereNull('students.departure')->orWhere('students.departure', 'LEFT'));
        }

        if ($teacherStaffId !== null) {
            $this->applyTeacherScope($query, $teacherStaffId, $teacherScope === 'all' ? 'all' : 'class');
        } elseif ($classId === 'none') {
            $query->whereDoesntHave('enrolments', fn (Builder $q) => $q->where('session_id', $sessionId));
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
     * Create a student and their STUDENT login.
     * A class is required when the current session has classes. Chosen electives are added with the compulsory subjects.
     */
    public function createStudent(array $data): Student
    {
        $classId = $data['class_id'] ?? null;
        $class = null;
        $electiveIds = array_values(array_unique(array_filter($data['elective_ids'] ?? [])));

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
        } elseif ($this->classOptions()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'class_id' => 'Choose a class for this student.',
            ]);
        }

        $allowedElectives = [];
        if ($electiveIds !== []) {
            if (! $class) {
                throw ValidationException::withMessages([
                    'elective_ids' => 'Choose a class before electives.',
                ]);
            }

            $allowedElectives = ClassSubject::query()
                ->where('class_id', $class->getKey())
                ->where('kind', 'ELECTIVE')
                ->whereIn('id', $electiveIds)
                ->pluck('id')
                ->all();

            if (count($allowedElectives) !== count($electiveIds)) {
                throw ValidationException::withMessages([
                    'elective_ids' => 'Choose elective subjects offered to this class.',
                ]);
            }
        }

        return DB::transaction(function () use ($data, $class, $allowedElectives) {
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
                $enrolment = $this->place($student, $class);
                foreach ($allowedElectives as $classSubjectId) {
                    EnrolmentSubject::create([
                        'enrolment_id' => $enrolment->getKey(),
                        'class_subject_id' => $classSubjectId,
                    ]);
                }
            }

            AuditService::record('student.created', 'Student', $student->getKey(), [
                'index_number' => $student->index_number,
                'class_id' => $class?->getKey(),
            ]);

            return $student;
        });
    }

    /**
     * Remove a student and the records that belong to them: login, guardian links,
     * enrolments, scores, attendance and invoices. Guardian profiles are kept.
     */
    public function deleteStudent(Student $student): void
    {
        $id = $student->getKey();
        $name = $student->full_name;
        $index = $student->index_number;

        DB::transaction(function () use ($student, $id) {
            $enrolmentIds = $student->enrolments()->pluck('id');
            $scoreIds = $enrolmentIds->isEmpty()
                ? collect()
                : SubjectScore::query()->whereIn('enrolment_id', $enrolmentIds)->pluck('id');
            $reportIds = $enrolmentIds->isEmpty()
                ? collect()
                : TermReport::query()->whereIn('enrolment_id', $enrolmentIds)->pluck('id');

            LegacyLink::query()->where('entity', 'student')->where('record_id', $id)->delete();
            $this->deleteLinks('enrolment', $enrolmentIds);
            $this->deleteLinks('score', $scoreIds);
            $this->deleteLinks('term_report', $reportIds);

            $student->delete();
        });

        AuditService::record('delete', 'Student', $id, [
            'full_name' => $name,
            'index_number' => $index,
        ]);
    }

    public function updateStudent(Student $student, array $data): Student
    {
        $status = $data['status'];

        return DB::transaction(function () use ($student, $data, $status) {
            $student->update([
                'index_number' => $data['index_number'],
                'full_name' => $data['full_name'],
                'gender' => $data['gender'] ?? '',
                'phone' => $data['phone'] ?? '',
                'email' => $data['email'] ?? '',
                'address' => $data['address'] ?? '',
                'birth_date' => ($data['birth_date'] ?? null) ?: null,
                'status' => $status === 'ACTIVE' ? 'ACTIVE' : 'LEFT',
                'departure' => match ($status) {
                    'GRADUATED' => 'GRADUATED',
                    'LEFT' => 'LEFT',
                    default => null,
                },
            ]);

            if ($user = $student->user) {
                $user->update(['email' => $data['email'] ?? '']);
            }

            if (filled($data['password'] ?? null)) {
                $login = $student->user()->first();
                if (! $login) {
                    if (blank($student->email)) {
                        throw ValidationException::withMessages([
                            'password' => 'Add an email before setting a password.',
                        ])->errorBag('student');
                    }
                    $login = User::create([
                        'school_id' => Tenant::id(),
                        'email' => $student->email,
                        'password' => $data['password'],
                        'role' => Role::STUDENT,
                        'must_change_password' => false,
                        'student_id' => $student->getKey(),
                    ]);
                    AuditService::record('user.password_set', 'User', $login->getKey());
                } else {
                    $this->assignPassword($login, $data['password'], 'student');
                }
            }

            if ($session = $this->currentSession()) {
                $enrolment = Enrolment::query()
                    ->where('student_id', $student->getKey())
                    ->where('session_id', $session->getKey())
                    ->first();
                $enrolment?->update([
                    'status' => $status === 'ACTIVE' ? 'ACTIVE' : 'LEFT',
                    'outcome' => match ($status) {
                        'GRADUATED' => 'GRADUATED',
                        'LEFT' => 'LEFT',
                        default => null,
                    },
                ]);
            }

            AuditService::record('student.updated', 'Student', $student->getKey());

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

    public function updateStaff(Staff $staff, array $data): Staff
    {
        return DB::transaction(function () use ($staff, $data) {
            $staff->update([
                'index_number' => $data['index_number'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'] ?? '',
                'phone' => $data['phone'] ?? '',
                'gender' => $data['gender'] ?? '',
                'address' => $data['address'] ?? '',
            ]);

            if ($user = $staff->user) {
                $user->update(['email' => $data['email'] ?? '']);
            }

            if (filled($data['password'] ?? null)) {
                $login = $staff->user()->first();
                if (! $login) {
                    throw ValidationException::withMessages([
                        'password' => 'This staff member has no login.',
                    ])->errorBag('staffEdit');
                }
                $this->assignPassword($login, $data['password'], 'staffEdit');
            }

            AuditService::record('staff.updated', 'Staff', $staff->getKey());

            return $staff;
        });
    }

    // --------------------------------------------------------------- guardians

    /**
     * Search-only: returns nothing for an empty query.
     *
     * @return Collection<int, Guardian>
     */
    public function findGuardian(string $id): ?Guardian
    {
        return Guardian::query()->with('user')->find($id);
    }

    public function setGuardianPassword(Guardian $guardian, string $password): Guardian
    {
        return DB::transaction(function () use ($guardian, $password) {
            $login = $guardian->user()->first();

            if (! $login) {
                if (blank($guardian->email)) {
                    throw ValidationException::withMessages([
                        'password' => 'Add an email before setting a password.',
                    ])->errorBag('guardianPassword');
                }
                if (User::where('school_id', Tenant::id())->where('email', $guardian->email)->exists()) {
                    throw ValidationException::withMessages([
                        'password' => 'That email already has a login at this school.',
                    ])->errorBag('guardianPassword');
                }

                $login = User::create([
                    'school_id' => Tenant::id(),
                    'email' => $guardian->email,
                    'password' => $password,
                    'role' => Role::PARENT,
                    'must_change_password' => false,
                    'guardian_id' => $guardian->getKey(),
                ]);
                AuditService::record('user.password_set', 'User', $login->getKey());

                return $guardian;
            }

            $this->assignPassword($login, $password, 'guardianPassword');

            return $guardian;
        });
    }

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
            $query->with([
                'user',
                'students' => fn ($students) => $students->whereIn('students.id', $allowed()),
            ]);
        } else {
            $query->with(['students', 'user']);
        }

        return $query->get();
    }

    private function assignPassword(User $user, string $password, string $bag): void
    {
        if ($user->isPlatformOwner() || (string) $user->school_id !== (string) Tenant::id()) {
            throw ValidationException::withMessages([
                'password' => 'That login cannot be changed here.',
            ])->errorBag($bag);
        }

        $user->forceFill([
            'password' => $password,
            'must_change_password' => false,
        ])->save();

        AuditService::record('user.password_set', 'User', $user->getKey());
    }

    private function sameNumber(string $left, string $right): bool
    {
        return $this->normalDigits($left) === $this->normalDigits($right);
    }

    private function numberGreater(string $left, string $right): bool
    {
        $left = $this->normalDigits($left);
        $right = $this->normalDigits($right);

        return strlen($left) === strlen($right) ? $left > $right : strlen($left) > strlen($right);
    }

    private function normalDigits(string $digits): string
    {
        $digits = ltrim($digits, '0');

        return $digits === '' ? '0' : $digits;
    }

    private function incrementDigits(string $digits): string
    {
        $digits = $this->normalDigits($digits);
        if ($digits === '0') {
            return '1';
        }

        $index = strlen($digits) - 1;
        while ($index >= 0 && $digits[$index] === '9') {
            $digits[$index] = '0';
            $index--;
        }

        if ($index < 0) {
            return '1'.$digits;
        }

        $digits[$index] = (string) ((int) $digits[$index] + 1);

        return $digits;
    }

    private function deleteLinks(string $entity, $ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        LegacyLink::query()->where('entity', $entity)->whereIn('record_id', $ids)->delete();
    }

    private function like(string $term): string
    {
        $term = addcslashes(trim($term), '\\%_');

        return '%'.$term.'%';
    }
}
