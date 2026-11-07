<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\Student;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business rules for Enrolment: placing, moving, electives and promoting between sessions.
 *
 * All models are tenant-scoped, so lookups can never reach another school's data.
 * Rule violations throw DomainException with a user-facing message.
 */
class EnrolmentService
{
    public const PAGE_SIZES = [25, 50, 100];

    public const STATUSES = ['ACTIVE', 'LEFT', 'GRADUATED'];

    /** Mapping value: the class is not placed in the next session. */
    public const PROMOTE_GRADUATED = 'graduated';

    private const COMPULSORY = AcademicsService::KIND_COMPULSORY;

    private const ELECTIVE = AcademicsService::KIND_ELECTIVE;

    // ---------------------------------------------------------------- Lookups

    /** @return Collection<int, AcademicSession> newest first */
    public function sessions(): Collection
    {
        return AcademicSession::query()->orderByDesc('start_year')->orderByDesc('name')->get();
    }

    /** The requested session, else the current one, else the newest. */
    public function resolveSession(?string $sessionId): ?AcademicSession
    {
        $sessions = $this->sessions();

        return ($sessionId ? $sessions->firstWhere('id', $sessionId) : null)
            ?? $sessions->firstWhere('is_current', true)
            ?? $sessions->first();
    }

    /** @return Collection<int, SchoolClass> */
    public function classes(?string $sessionId): Collection
    {
        if (! $sessionId) {
            return new Collection;
        }

        return SchoolClass::query()
            ->where('session_id', $sessionId)
            ->with(['level', 'arm'])
            ->get()
            ->sortBy(fn (SchoolClass $c) => [$c->level?->sort_order ?? 0, $c->level?->name, $c->arm?->name])
            ->values();
    }

    public static function normalisePageSize(mixed $size): int
    {
        return in_array((int) $size, self::PAGE_SIZES, true) ? (int) $size : self::PAGE_SIZES[0];
    }

    // ------------------------------------------------------------------- List

    public function list(string $sessionId, ?string $classId, ?string $status, ?string $search, int $perPage = 25, ?array $onlyClassIds = null): LengthAwarePaginator
    {
        $query = Enrolment::query()
            ->where('session_id', $sessionId)
            ->with(['student', 'class.level', 'class.arm'])
            ->withCount('subjects as subjects_count')
            ->orderBy(Student::query()->select('full_name')->whereColumn('students.id', 'enrolments.student_id')->limit(1))
            ->orderBy('id');

        if ($onlyClassIds !== null) {
            $query->whereIn('class_id', $onlyClassIds);
        }
        if ($classId) {
            $query->where('class_id', $classId);
        }
        if ($status === 'GRADUATED') {
            $query->where('outcome', 'GRADUATED');
        } elseif ($status === 'LEFT') {
            $query->where('status', 'LEFT')->where(fn (Builder $q) => $q->whereNull('outcome')->orWhere('outcome', 'LEFT'));
        } elseif ($status === 'ACTIVE') {
            $query->where('status', 'ACTIVE');
        }
        if ($search !== null && trim($search) !== '') {
            $like = '%'.addcslashes(trim($search), '\\%_').'%';
            $query->whereHas('student', fn (Builder $q) => $q
                ->where('full_name', 'like', $like)
                ->orWhere('index_number', 'like', $like));
        }

        return $query->paginate(self::normalisePageSize($perPage))->withQueryString();
    }

    public function findEnrolment(string $id): ?Enrolment
    {
        return Enrolment::query()->with(['student', 'session', 'class.level', 'class.arm'])->find($id);
    }

    /**
     * ACTIVE students with no ACTIVE placement in the session (a LEFT placement counts as unplaced;
     * placing them again reactivates it).
     *
     * @return Collection<int, Student>
     */
    public function unplacedStudents(string $sessionId, int $limit = 500): Collection
    {
        return Student::query()
            ->where('status', 'ACTIVE')
            ->whereDoesntHave('enrolments', fn (Builder $q) => $q->where('session_id', $sessionId)->where('status', 'ACTIVE'))
            ->orderBy('full_name')
            ->limit($limit)
            ->get();
    }

    /**
     * Electives offered to the enrolment's class, flagged with whether the student takes each.
     *
     * @return Collection<int, ClassSubject> with a `taken` attribute
     */
    public function electiveOptions(Enrolment $enrolment): Collection
    {
        $taken = EnrolmentSubject::query()->where('enrolment_id', $enrolment->getKey())->pluck('class_subject_id')->all();

        return ClassSubject::query()
            ->where('class_id', $enrolment->class_id)
            ->where('kind', self::ELECTIVE)
            ->with('subject')
            ->get()
            ->sortBy(fn (ClassSubject $cs) => strtolower($cs->subject?->name ?? ''))
            ->each(fn (ClassSubject $cs) => $cs->setAttribute('taken', in_array($cs->getKey(), $taken, true)))
            ->values();
    }

    /** Subjects the enrolment currently takes, split for display. */
    public function subjectsFor(Enrolment $enrolment): Collection
    {
        return EnrolmentSubject::query()
            ->where('enrolment_id', $enrolment->getKey())
            ->with('classSubject.subject')
            ->get()
            ->map(fn (EnrolmentSubject $es) => $es->classSubject)
            ->filter()
            ->sortBy(fn (ClassSubject $cs) => [$cs->kind, strtolower($cs->subject?->name ?? '')])
            ->values();
    }

    // ------------------------------------------------------------------ Place

    public function place(string $studentId, string $classId): Enrolment
    {
        $student = Student::query()->where('status', 'ACTIVE')->find($studentId)
            ?? throw new DomainException('Student not found or no longer active.');
        $class = SchoolClass::query()->with(['level', 'arm'])->find($classId)
            ?? throw new DomainException('Class not found.');

        $existing = Enrolment::query()
            ->where('student_id', $student->getKey())
            ->where('session_id', $class->session_id)
            ->first();

        if ($existing?->status === 'ACTIVE') {
            throw new DomainException("{$student->full_name} is already in a class this session. Use Move on their row.");
        }

        $enrolment = DB::transaction(function () use ($student, $class, $existing) {
            if ($existing) { // LEFT -> back again
                $existing->update(['class_id' => $class->getKey(), 'status' => 'ACTIVE', 'outcome' => null]);
                EnrolmentSubject::query()->where('enrolment_id', $existing->getKey())->delete();
                $enrolment = $existing;
            } else {
                $enrolment = Enrolment::create([
                    'student_id' => $student->getKey(),
                    'class_id' => $class->getKey(),
                    'session_id' => $class->session_id,
                    'status' => 'ACTIVE',
                ]);
            }

            $this->attach($enrolment, $this->compulsoryIds($class->getKey()));

            return $enrolment;
        });

        AuditService::record('create', 'Enrolment', $enrolment->getKey(), [
            'student_id' => $student->getKey(),
            'class_id' => $class->getKey(),
            'reactivated' => (bool) $existing,
        ]);

        return $enrolment;
    }

    // ------------------------------------------------------------------- Move

    /**
     * Change class within the same session. Old class subjects are dropped, the new class's
     * compulsory subjects attached, and electives kept only when the same subject is an
     * elective in the new class.
     */
    public function move(string $enrolmentId, string $classId): Enrolment
    {
        $enrolment = Enrolment::query()->with(['class.level', 'class.arm'])->find($enrolmentId)
            ?? throw new DomainException('Enrolment not found.');
        if ($enrolment->status !== 'ACTIVE') {
            throw new DomainException('This student has left. Place them again if they come back.');
        }

        $target = SchoolClass::query()->find($classId) ?? throw new DomainException('Class not found.');
        if ($target->session_id !== $enrolment->session_id) {
            throw new DomainException('Choose a class in the same session. Promote the session to move into another one.');
        }
        if ($target->getKey() === $enrolment->class_id) {
            throw new DomainException('This student is already in that class.');
        }

        $fromClassId = $enrolment->class_id;

        DB::transaction(function () use ($enrolment, $target) {
            $keptSubjectIds = EnrolmentSubject::query()
                ->where('enrolment_id', $enrolment->getKey())
                ->with('classSubject')
                ->get()
                ->filter(fn (EnrolmentSubject $es) => $es->classSubject?->kind === self::ELECTIVE)
                ->map(fn (EnrolmentSubject $es) => $es->classSubject->subject_id)
                ->all();

            $keptElectives = ClassSubject::query()
                ->where('class_id', $target->getKey())
                ->where('kind', self::ELECTIVE)
                ->whereIn('subject_id', $keptSubjectIds)
                ->pluck('id')
                ->all();

            $enrolment->update(['class_id' => $target->getKey()]);
            EnrolmentSubject::query()->where('enrolment_id', $enrolment->getKey())->delete();
            $this->attach($enrolment, array_merge($this->compulsoryIds($target->getKey()), $keptElectives));
        });

        AuditService::record('update', 'Enrolment', $enrolment->getKey(), [
            'from_class_id' => $fromClassId,
            'to_class_id' => $target->getKey(),
        ]);

        return $enrolment->refresh();
    }

    // -------------------------------------------------------------- Electives

    /**
     * Make the enrolment's ELECTIVE subjects exactly the given class-subject ids.
     * Compulsory rows are never touched.
     *
     * @param  array<int, string>  $classSubjectIds
     * @return array{added:int, removed:int}
     */
    public function syncElectives(string $enrolmentId, array $classSubjectIds): array
    {
        $enrolment = Enrolment::query()->find($enrolmentId) ?? throw new DomainException('Enrolment not found.');
        if ($enrolment->status !== 'ACTIVE') {
            throw new DomainException('This student has left, so their subjects cannot be changed.');
        }

        $wanted = array_values(array_unique(array_filter($classSubjectIds)));
        $electives = ClassSubject::query()
            ->where('class_id', $enrolment->class_id)
            ->where('kind', self::ELECTIVE)
            ->pluck('id')
            ->all();

        if (array_diff($wanted, $electives)) {
            throw new DomainException('Choose elective subjects offered to this class.');
        }

        $result = DB::transaction(function () use ($enrolment, $wanted, $electives) {
            $current = EnrolmentSubject::query()
                ->where('enrolment_id', $enrolment->getKey())
                ->whereIn('class_subject_id', $electives)
                ->pluck('class_subject_id')
                ->all();

            $toAdd = array_values(array_diff($wanted, $current));
            $toRemove = array_values(array_diff($current, $wanted));

            $this->attach($enrolment, $toAdd);
            if ($toRemove) {
                EnrolmentSubject::query()
                    ->where('enrolment_id', $enrolment->getKey())
                    ->whereIn('class_subject_id', $toRemove)
                    ->delete();
            }

            return ['added' => count($toAdd), 'removed' => count($toRemove)];
        });

        AuditService::record('update', 'Enrolment', $enrolment->getKey(), ['electives' => $result]);

        return $result;
    }

    // ------------------------------------------------------------------ Leave

    public function markLeft(string $enrolmentId): Enrolment
    {
        $enrolment = Enrolment::query()->with('student')->find($enrolmentId) ?? throw new DomainException('Enrolment not found.');
        if ($enrolment->status === 'LEFT') {
            throw new DomainException('This student is already marked as left.');
        }

        $enrolment->update(['status' => 'LEFT', 'outcome' => 'LEFT']);
        if ($enrolment->student && $enrolment->student->departure !== 'GRADUATED') {
            $enrolment->student->update(['status' => 'LEFT', 'departure' => 'LEFT']);
        }
        AuditService::record('leave', 'Enrolment', $enrolment->getKey());

        return $enrolment;
    }

    // --------------------------------------------------------------- Promotion

    /**
     * Source classes (with ACTIVE student counts) and their suggested target class: same level + arm
     * in the target session, else the same display name.
     *
     * @return array<int, array{class: SchoolClass, students: int, suggested: ?string}>
     */
    public function promotionPlan(string $fromSessionId, string $toSessionId): array
    {
        $targets = $this->classes($toSessionId);
        $levels = Level::query()->orderBy('sort_order')->orderBy('name')->get()->values();
        $nextLevelId = [];
        foreach ($levels as $i => $level) {
            $nextLevelId[$level->getKey()] = $levels->get($i + 1)?->getKey();
        }

        return $this->classes($fromSessionId)
            ->map(function (SchoolClass $class) use ($targets, $nextLevelId) {
                $students = Enrolment::query()->where('class_id', $class->getKey())->where('status', 'ACTIVE')->count();
                $nextId = $nextLevelId[$class->level_id] ?? null;
                $ofNext = $nextId ? $targets->filter(fn (SchoolClass $t) => $t->level_id === $nextId) : collect();
                $match = $ofNext->first(fn (SchoolClass $t) => $t->arm_id === $class->arm_id) ?? $ofNext->first();

                return [
                    'class' => $class,
                    'students' => $students,
                    'suggested' => $match?->getKey() ?? self::PROMOTE_GRADUATED,
                ];
            })
            ->filter(fn (array $row) => $row['students'] > 0)
            ->values()
            ->all();
    }

    /**
     * Promote every ACTIVE enrolment of session A into session B.
     *
     * $mapping is [sourceClassId => targetClassId]. A source class missing from the map is matched
     * automatically to the next level (same arm when that class exists). Mapped to '' it is skipped.
     * Mapped to "graduated", the enrolment is marked left with outcome GRADUATED and the student
     * leaves the active roll as graduated. A real target makes the previous enrolment LEFT and the
     * new one ACTIVE, with the target class's compulsory subjects plus any elective the student
     * already took whose subject is also an elective in the target class.
     *
     * @param  array<string, ?string>  $mapping
     * @return array{created:int, graduated:int, skipped: array<int, array{student:string, reason:string}>}
     */
    public function promote(string $fromSessionId, string $toSessionId, array $mapping = []): array
    {
        if ($fromSessionId === $toSessionId) {
            throw new DomainException('Choose two different sessions.');
        }
        $from = AcademicSession::query()->find($fromSessionId) ?? throw new DomainException('Source session not found.');
        $to = AcademicSession::query()->find($toSessionId) ?? throw new DomainException('Target session not found.');

        $targets = $this->classes($to->getKey())->keyBy('id');
        $suggested = collect($this->promotionPlan($from->getKey(), $to->getKey()))
            ->mapWithKeys(fn (array $row) => [$row['class']->getKey() => $row['suggested']]);

        foreach ($mapping as $sourceId => $targetId) {
            if (filled($targetId) && $targetId !== self::PROMOTE_GRADUATED && ! $targets->has($targetId)) {
                throw new DomainException('Every target class must belong to '.$to->name.'.');
            }
        }

        $created = 0;
        $graduated = 0;
        $skipped = [];

        DB::transaction(function () use ($from, $to, $mapping, $targets, $suggested, &$created, &$graduated, &$skipped) {
            $sources = Enrolment::query()
                ->where('session_id', $from->getKey())
                ->where('status', 'ACTIVE')
                ->with(['student', 'class.level', 'class.arm', 'subjects.classSubject'])
                ->get();

            $alreadyPlaced = Enrolment::query()->where('session_id', $to->getKey())->pluck('student_id')->flip();
            $targetOffers = ClassSubject::query()->whereIn('class_id', $targets->keys())->get()->groupBy('class_id');

            foreach ($sources as $source) {
                $name = $source->student?->full_name ?? 'Student';
                $targetId = array_key_exists($source->class_id, $mapping)
                    ? ($mapping[$source->class_id] ?: null)
                    : $suggested->get($source->class_id);

                if (! $targetId) {
                    $skipped[] = ['student' => $name, 'reason' => 'No target class chosen for '.$source->class?->display_name.'.'];

                    continue;
                }
                if ($alreadyPlaced->has($source->student_id)) {
                    $skipped[] = ['student' => $name, 'reason' => 'Already placed in '.$to->name.'.'];

                    continue;
                }

                if ($targetId === self::PROMOTE_GRADUATED) {
                    $source->update(['status' => 'LEFT', 'outcome' => 'GRADUATED']);
                    $source->student?->update(['status' => 'LEFT', 'departure' => 'GRADUATED']);
                    $graduated++;

                    continue;
                }

                $offers = $targetOffers->get($targetId, collect());
                $takenElectiveSubjects = $source->subjects
                    ->map(fn (EnrolmentSubject $es) => $es->classSubject)
                    ->filter(fn (?ClassSubject $cs) => $cs?->kind === self::ELECTIVE)
                    ->pluck('subject_id');

                $subjectIds = $offers->where('kind', self::COMPULSORY)->pluck('id')
                    ->merge($offers->where('kind', self::ELECTIVE)->whereIn('subject_id', $takenElectiveSubjects)->pluck('id'))
                    ->all();

                $next = Enrolment::create([
                    'student_id' => $source->student_id,
                    'class_id' => $targetId,
                    'session_id' => $to->getKey(),
                    'status' => 'ACTIVE',
                ]);
                $this->attach($next, $subjectIds);
                $source->update(['status' => 'LEFT']);

                $created++;
            }
        });

        AuditService::record('promote', 'Enrolment', null, [
            'from_session_id' => $from->getKey(),
            'to_session_id' => $to->getKey(),
            'created' => $created,
            'graduated' => $graduated,
            'skipped' => count($skipped),
        ]);

        return ['created' => $created, 'graduated' => $graduated, 'skipped' => $skipped];
    }

    // ---------------------------------------------------------------- Helpers

    /** @return array<int, string> */
    private function compulsoryIds(string $classId): array
    {
        return ClassSubject::query()->where('class_id', $classId)->where('kind', self::COMPULSORY)->pluck('id')->all();
    }

    /** Attach class subjects to an enrolment, skipping ones it already has. */
    private function attach(Enrolment $enrolment, array $classSubjectIds): void
    {
        $classSubjectIds = array_values(array_unique($classSubjectIds));
        if (! $classSubjectIds) {
            return;
        }

        $rows = array_map(fn ($id) => [
            'id' => (string) Str::ulid(),
            'school_id' => $enrolment->school_id,
            'enrolment_id' => $enrolment->getKey(),
            'class_subject_id' => $id,
        ], $classSubjectIds);

        foreach (array_chunk($rows, 500) as $chunk) {
            EnrolmentSubject::query()->insertOrIgnore($chunk);
        }
    }
}
