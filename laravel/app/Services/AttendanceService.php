<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Attendance;
use App\Models\Enrolment;
use App\Models\SchoolClass;
use App\Models\StudentGuardian;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for the daily class register.
 *
 * Attendance rows are keyed by student + date (a student has one ACTIVE enrolment per class register),
 * so saving a register twice updates the existing rows instead of duplicating them.
 */
class AttendanceService
{
    public const STATUSES = ['PRESENT', 'ABSENT', 'LATE', 'EXCUSED'];

    // ----------------------------------------------------------------- Access

    /** Office roles that can open any register. */
    public function isOffice(User $user): bool
    {
        return $user->isPlatformOwner() || $user->hasRole(Role::ADMIN, Role::REGISTRAR, Role::ACCOUNTANT);
    }

    public function canMark(User $user, SchoolClass $class): bool
    {
        if ($this->isOffice($user)) {
            return true;
        }

        return $user->role === Role::TEACHER
            && $user->staff_id !== null
            && $user->staff_id === $class->form_teacher_id;
    }

    // ---------------------------------------------------------------- Lookups

    public function currentSession(): ?AcademicSession
    {
        return AcademicSession::query()->where('is_current', true)->first();
    }

    /** Classes (current session) whose register the user can open, sorted by level then arm. */
    public function classesFor(User $user): Collection
    {
        $session = $this->currentSession();
        if (! $session) {
            return new Collection;
        }

        $query = SchoolClass::query()->where('session_id', $session->getKey())->with(['level', 'arm', 'formTeacher']);

        if (! $this->isOffice($user)) {
            $query->where('form_teacher_id', $user->staff_id ?? '-');
        }

        return $query->get()
            ->sortBy([
                fn ($a, $b) => ($a->level?->sort_order ?? 0) <=> ($b->level?->sort_order ?? 0),
                fn ($a, $b) => strcmp((string) $a->level?->name, (string) $b->level?->name),
                fn ($a, $b) => strcmp((string) $a->arm?->name, (string) $b->arm?->name),
            ])
            ->values();
    }

    public function findClass(string $classId): ?SchoolClass
    {
        return SchoolClass::query()->with(['level', 'arm', 'formTeacher'])->find($classId);
    }

    /** Parse a Y-m-d date; throws DomainException when invalid. */
    public function parseDate(?string $date): Carbon
    {
        $date = trim((string) $date);
        $parsed = $date === '' ? null : Carbon::createFromFormat('!Y-m-d', $date);

        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException('Enter a valid date (YYYY-MM-DD).');
        }

        return $parsed;
    }

    // --------------------------------------------------------------- Register

    /**
     * ACTIVE enrolments of the class with their mark for the date (null when not yet marked).
     *
     * @return \Illuminate\Support\Collection<int, array{enrolment: Enrolment, student: \App\Models\Student, status: ?string}>
     */
    public function register(SchoolClass $class, Carbon $date)
    {
        $enrolments = Enrolment::query()
            ->where('class_id', $class->getKey())
            ->where('session_id', $class->session_id)
            ->where('status', 'ACTIVE')
            ->with('student')
            ->get()
            ->sortBy(fn (Enrolment $e) => strtolower((string) $e->student?->full_name))
            ->values();

        $marks = Attendance::query()
            ->whereDate('date', $date->toDateString())
            ->whereIn('student_id', $enrolments->pluck('student_id'))
            ->get()
            ->keyBy('student_id');

        return $enrolments->map(fn (Enrolment $e) => [
            'enrolment' => $e,
            'student' => $e->student,
            'status' => $marks->get($e->student_id)?->status,
        ]);
    }

    /**
     * Upsert the marks of a register.
     *
     * @param  array<string, ?string>  $marks  enrolment id => status (blank = leave unmarked)
     * @return int number of rows written
     */
    public function save(User $user, SchoolClass $class, Carbon $date, array $marks): int
    {
        if (! $this->canMark($user, $class)) {
            throw new DomainException('Only the form teacher or the office can save this register.');
        }

        $marks = array_filter($marks, fn ($status) => $status !== null && $status !== '');

        $enrolments = Enrolment::query()
            ->where('class_id', $class->getKey())
            ->where('status', 'ACTIVE')
            ->whereIn('id', array_keys($marks))
            ->get()
            ->keyBy('id');

        foreach ($marks as $enrolmentId => $status) {
            if (! $enrolments->has($enrolmentId)) {
                throw new DomainException('A student in this register is not actively enrolled in the class.');
            }
            if (! in_array($status, self::STATUSES, true)) {
                throw new DomainException('Attendance must be present, absent, late or excused.');
            }
        }

        DB::transaction(function () use ($marks, $enrolments, $date) {
            foreach ($marks as $enrolmentId => $status) {
                $studentId = $enrolments[$enrolmentId]->student_id;

                $row = Attendance::query()
                    ->where('student_id', $studentId)
                    ->whereDate('date', $date->toDateString())
                    ->first();

                if ($row) {
                    $row->update(['status' => $status]);
                } else {
                    Attendance::create([
                        'student_id' => $studentId,
                        'date' => $date->toDateString(),
                        'status' => $status,
                    ]);
                }
            }
        });

        AuditService::record('update', 'Attendance', $class->getKey(), [
            'date' => $date->toDateString(),
            'count' => count($marks),
        ]);

        return count($marks);
    }

    // ---------------------------------------------------------------- History

    /**
     * Latest attendance marks of the signed-in student, or of a parent's children.
     */
    public function mine(User $user, int $limit = 60): Collection
    {
        $studentIds = [];
        if ($user->role === Role::STUDENT && $user->student_id) {
            $studentIds = [$user->student_id];
        } elseif ($user->role === Role::PARENT && $user->guardian_id) {
            $studentIds = StudentGuardian::query()->where('guardian_id', $user->guardian_id)->pluck('student_id')->all();
        }

        if ($studentIds === []) {
            return new Collection;
        }

        return Attendance::query()
            ->whereIn('student_id', $studentIds)
            ->with('student')
            ->orderByDesc('date')
            ->limit($limit)
            ->get();
    }
}
