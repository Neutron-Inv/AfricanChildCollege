<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\Assignment;
use App\Models\ClassSubject;
use App\Models\Department;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
use App\Models\FeeItem;
use App\Models\GradingScale;
use App\Models\Invoice;
use App\Models\LessonPlan;
use App\Models\Level;
use App\Models\Period;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Subject;
use App\Models\SubjectScore;
use App\Models\Term;
use App\Models\TermReport;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business rules for Academics (sessions, classes, subjects, departments, rooms, grading).
 *
 * All models are tenant-scoped, so lookups can never reach another school's data.
 * Rule violations throw DomainException with a user-facing message.
 */
class AcademicsService
{
    public const KIND_COMPULSORY = 'COMPULSORY';

    public const KIND_ELECTIVE = 'ELECTIVE';

    public const TERM_NAMES = [1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term'];

    // ---------------------------------------------------------------- Lookups

    /** Active teachers, for form teacher / HOD / subject teacher dropdowns. */
    public function teachers(): Collection
    {
        return Staff::query()
            ->where('is_teacher', true)
            ->where('status', 'ACTIVE')
            ->orderBy('first_name')->orderBy('last_name')
            ->get();
    }

    public function levels(): Collection
    {
        return Level::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    public function arms(): Collection
    {
        return Arm::query()->orderBy('name')->get();
    }

    // --------------------------------------------------------------- Sessions

    public function sessions(): Collection
    {
        return AcademicSession::query()
            ->with(['terms' => fn ($q) => $q->orderBy('sequence')])
            ->withCount(['classes', 'enrolments'])
            ->orderByDesc('start_year')
            ->get();
    }

    public function currentSession(): ?AcademicSession
    {
        return AcademicSession::query()->where('is_current', true)->first();
    }

    public function createSession(array $data): AcademicSession
    {
        $start = (int) $data['start_year'];
        $end = (int) $data['end_year'];
        if ($end < $start) {
            throw new DomainException('The end year cannot be before the start year.');
        }

        $name = trim((string) ($data['name'] ?? '')) ?: "{$start}/{$end}";
        if (AcademicSession::query()->where('name', $name)->exists()) {
            throw new DomainException("A session named {$name} already exists.");
        }

        $session = DB::transaction(function () use ($data, $name, $start, $end) {
            $isCurrent = (bool) ($data['is_current'] ?? false);
            if ($isCurrent) {
                AcademicSession::query()->update(['is_current' => false]);
            }

            $session = AcademicSession::create([
                'name' => $name,
                'start_year' => $start,
                'end_year' => $end,
                'is_current' => $isCurrent,
            ]);

            [$copied, $from] = $this->copyClassesFromPreviousSession($session);
            $session->setAttribute('copied_classes', $copied);
            $session->setAttribute('copied_from', $from);

            return $session;
        });

        AuditService::record('create', 'AcademicSession', $session->getKey(), [
            'copied_classes' => (int) $session->getAttribute('copied_classes'),
        ]);

        return $session;
    }

    /**
     * Classes (and their subject offerings) from the session immediately before this one.
     * Students stay in the session they were enrolled in.
     *
     * @return array{0: int, 1: ?string}
     */
    private function copyClassesFromPreviousSession(AcademicSession $session): array
    {
        $previous = AcademicSession::query()
            ->where('start_year', '<', $session->start_year)
            ->orderByDesc('start_year')
            ->orderByDesc('end_year')
            ->first();

        if (! $previous) {
            return [0, null];
        }

        $classes = SchoolClass::query()
            ->where('session_id', $previous->getKey())
            ->with('classSubjects')
            ->get();

        foreach ($classes as $class) {
            $copy = SchoolClass::create([
                'session_id' => $session->getKey(),
                'level_id' => $class->level_id,
                'arm_id' => $class->arm_id,
                'form_teacher_id' => $class->form_teacher_id,
                'admission_fee' => $class->admission_fee,
                'hall_charge' => $class->hall_charge,
            ]);

            foreach ($class->classSubjects as $offering) {
                ClassSubject::create([
                    'class_id' => $copy->getKey(),
                    'subject_id' => $offering->subject_id,
                    'teacher_id' => $offering->teacher_id,
                    'kind' => $offering->kind,
                    'fee' => $offering->fee,
                ]);
            }
        }

        return [$classes->count(), $previous->name];
    }

    public function markCurrent(string $sessionId): AcademicSession
    {
        $session = $this->findOrFail(AcademicSession::query(), $sessionId, 'Session not found.');

        DB::transaction(function () use ($session) {
            AcademicSession::query()->where('id', '!=', $session->getKey())->update(['is_current' => false]);
            $session->update(['is_current' => true]);
        });

        AuditService::record('update', 'AcademicSession', $session->getKey(), ['is_current' => true]);

        return $session->refresh();
    }

    /**
     * Rename a session and update the days open / result date of its terms.
     *
     * @param  array{name?: string|null, start_year: int, end_year: int, terms?: array<int, array{id?: string, days_school_open?: int|null, result_date?: string|null}>}  $data
     */
    public function updateSession(string $sessionId, array $data): AcademicSession
    {
        $session = $this->findOrFail(AcademicSession::query(), $sessionId, 'Session not found.');
        $start = (int) $data['start_year'];
        $end = (int) $data['end_year'];
        if ($end < $start) {
            throw new DomainException('The end year cannot be before the start year.');
        }

        $name = trim((string) ($data['name'] ?? '')) ?: "{$start}/{$end}";
        if (AcademicSession::query()->where('name', $name)->where('id', '!=', $session->getKey())->exists()) {
            throw new DomainException("A session named {$name} already exists.");
        }

        DB::transaction(function () use ($session, $name, $start, $end, $data) {
            $session->update([
                'name' => $name,
                'start_year' => $start,
                'end_year' => $end,
            ]);

            foreach ($data['terms'] ?? [] as $row) {
                $term = $session->terms()->whereKey($row['id'] ?? null)->first();
                if (! $term) {
                    continue;
                }

                $term->update([
                    'days_school_open' => (int) ($row['days_school_open'] ?? 0),
                    'result_date' => ($row['result_date'] ?? null) ?: null,
                ]);
            }
        });

        AuditService::record('update', 'AcademicSession', $session->getKey(), ['name' => $name]);

        return $session->refresh();
    }

    /** Remove a session and the classes, terms, enrolments, and results that belong to it. */
    public function deleteSession(string $sessionId): void
    {
        $session = $this->findOrFail(AcademicSession::query(), $sessionId, 'Session not found.');
        $name = $session->name;

        DB::transaction(function () use ($session) {
            $termIds = $session->terms()->pluck('id');
            $classIds = $session->classes()->pluck('id');
            $enrolmentIds = $session->enrolments()->pluck('id');
            $classSubjectIds = ClassSubject::query()->whereIn('class_id', $classIds)->pluck('id');

            if ($termIds->isNotEmpty()) {
                Invoice::query()->whereIn('term_id', $termIds)->delete();
            }
            if ($classIds->isNotEmpty()) {
                Invoice::query()->whereIn('class_id', $classIds)->delete();
            }

            $this->deleteWhereAny(SubjectScore::query(), [
                'enrolment_id' => $enrolmentIds,
                'term_id' => $termIds,
                'class_subject_id' => $classSubjectIds,
            ]);
            $this->deleteWhereAny(TermReport::query(), [
                'enrolment_id' => $enrolmentIds,
                'term_id' => $termIds,
            ]);
            $this->deleteWhereAny(LessonPlan::query(), [
                'class_subject_id' => $classSubjectIds,
                'term_id' => $termIds,
            ]);
            $this->deleteWhereAny(Assignment::query(), [
                'class_subject_id' => $classSubjectIds,
                'term_id' => $termIds,
            ]);

            if ($classSubjectIds->isNotEmpty()) {
                Period::query()->whereIn('class_subject_id', $classSubjectIds)->delete();
                ClassSubject::query()->whereIn('id', $classSubjectIds)->delete();
            }
            if ($enrolmentIds->isNotEmpty()) {
                EnrolmentSubject::query()->whereIn('enrolment_id', $enrolmentIds)->delete();
                Enrolment::query()->whereIn('id', $enrolmentIds)->delete();
            }

            FeeItem::query()->where('session_id', $session->getKey())->delete();
            if ($classIds->isNotEmpty()) {
                SchoolClass::query()->whereIn('id', $classIds)->delete();
            }
            if ($termIds->isNotEmpty()) {
                Term::query()->whereIn('id', $termIds)->delete();
            }

            $session->delete();
        });

        AuditService::record('delete', 'AcademicSession', $sessionId, ['name' => $name]);
    }

    /** Delete rows matching any of the given id lists. Empty lists are skipped. */
    private function deleteWhereAny($query, array $columns): void
    {
        $columns = array_filter($columns, fn ($ids) => $ids->isNotEmpty());
        if ($columns === []) {
            return;
        }

        $query->where(function ($inner) use ($columns) {
            foreach ($columns as $column => $ids) {
                $inner->orWhereIn($column, $ids);
            }
        })->delete();
    }

    public function createTerm(array $data): Term
    {
        $session = $this->findOrFail(AcademicSession::query(), $data['session_id'], 'Session not found.');
        $sequence = (int) $data['sequence'];

        if ($sequence < 1 || $sequence > 3) {
            throw new DomainException('A session has first, second, and third term only.');
        }
        if ($session->terms()->where('sequence', $sequence)->exists()) {
            throw new DomainException(self::TERM_NAMES[$sequence]." already exists in {$session->name}.");
        }

        $term = Term::create([
            'session_id' => $session->getKey(),
            'sequence' => $sequence,
            'name' => trim((string) ($data['name'] ?? '')) ?: self::TERM_NAMES[$sequence],
            'days_school_open' => (int) ($data['days_school_open'] ?? 0),
            'result_date' => $data['result_date'] ?? null,
        ]);

        AuditService::record('create', 'Term', $term->getKey());

        return $term;
    }

    // ---------------------------------------------------------------- Classes

    /**
     * Classes of a session with form teacher and active-student / subject counts.
     *
     * When $forTeacherStaffId is set (subject teachers), only classes they teach a
     * subject in, or form-teach, are returned — so Academics stays streamlined for them.
     */
    public function classes(?string $sessionId, ?string $forTeacherStaffId = null): Collection
    {
        if (! $sessionId) {
            return new Collection;
        }

        $query = SchoolClass::query()
            ->where('session_id', $sessionId)
            ->with(['level', 'arm', 'formTeacher'])
            ->withCount([
                'enrolments as students_count' => fn ($q) => $q->where('status', 'ACTIVE'),
                'classSubjects as subjects_count',
            ]);

        if ($forTeacherStaffId) {
            $query->where(fn ($q) => $q
                ->where('form_teacher_id', $forTeacherStaffId)
                ->orWhereHas('classSubjects', fn ($s) => $s->where('teacher_id', $forTeacherStaffId)));
        }

        $classes = $query
            ->get()
            ->sortBy([
                fn ($a, $b) => ($a->level?->sort_order ?? 0) <=> ($b->level?->sort_order ?? 0),
                fn ($a, $b) => strcmp((string) $a->arm?->name, (string) $b->arm?->name),
            ])
            ->values();

        if ($forTeacherStaffId) {
            $taught = ClassSubject::query()
                ->whereIn('class_id', $classes->modelKeys())
                ->where('teacher_id', $forTeacherStaffId)
                ->selectRaw('class_id, count(*) as aggregate')
                ->groupBy('class_id')
                ->pluck('aggregate', 'class_id');

            $classes->each(function (SchoolClass $class) use ($forTeacherStaffId, $taught) {
                if ($class->form_teacher_id !== $forTeacherStaffId) {
                    $class->subjects_count = (int) ($taught[$class->getKey()] ?? 0);
                }
            });
        }

        return $classes;
    }

    /** Whether this staff member teaches a subject in the class or is its form teacher. */
    public function teacherBelongsToClass(string $staffId, string $classId): bool
    {
        return SchoolClass::query()
            ->whereKey($classId)
            ->where(fn ($q) => $q
                ->where('form_teacher_id', $staffId)
                ->orWhereHas('classSubjects', fn ($s) => $s->where('teacher_id', $staffId)))
            ->exists();
    }

    public function findClass(string $classId, ?string $sessionId = null): ?SchoolClass
    {
        return SchoolClass::query()
            ->when($sessionId, fn ($q) => $q->where('session_id', $sessionId))
            ->with(['level', 'arm', 'session', 'formTeacher'])
            ->find($classId);
    }

    /** Class subjects for a class, ordered by subject name. */
    public function classSubjects(string $classId): Collection
    {
        return ClassSubject::query()
            ->where('class_id', $classId)
            ->with(['subject', 'teacher'])
            ->withCount('enrolmentSubjects as students_count')
            ->get()
            ->sortBy(fn ($cs) => strtolower($cs->subject?->name ?? ''))
            ->values();
    }

    public function createClass(array $data): SchoolClass
    {
        $session = $this->findOrFail(AcademicSession::query(), $data['session_id'], 'Session not found.');
        $level = $this->findOrFail(Level::query(), $data['level_id'], 'Level not found.');
        $arm = $this->findOrFail(Arm::query(), $data['arm_id'], 'Arm not found.');
        $teacherId = $this->requireTeacher($data['form_teacher_id'] ?? null);

        $exists = SchoolClass::query()
            ->where('session_id', $session->getKey())
            ->where('level_id', $level->getKey())
            ->where('arm_id', $arm->getKey())
            ->exists();
        if ($exists) {
            throw new DomainException("{$level->name} {$arm->name} already exists in {$session->name}.");
        }

        $class = SchoolClass::create([
            'session_id' => $session->getKey(),
            'level_id' => $level->getKey(),
            'arm_id' => $arm->getKey(),
            'form_teacher_id' => $teacherId,
        ]);

        AuditService::record('create', 'Class', $class->getKey());

        return $class;
    }

    /** Change the level and arm that name the class, and its fee amounts. */
    public function updateClass(string $classId, array $data): array
    {
        $class = $this->findOrFail(SchoolClass::query(), $classId, 'Class not found.');
        $level = $this->findOrFail(Level::query(), $data['level_id'] ?? null, 'Level not found.');
        $arm = $this->findOrFail(Arm::query(), $data['arm_id'] ?? null, 'Arm not found.');

        $taken = SchoolClass::query()
            ->where('session_id', $class->session_id)
            ->where('level_id', $level->getKey())
            ->where('arm_id', $arm->getKey())
            ->where('id', '!=', $class->getKey())
            ->exists();
        if ($taken) {
            throw new DomainException("{$level->name} {$arm->name} already exists in this session.");
        }

        $class->update([
            'level_id' => $level->getKey(),
            'arm_id' => $arm->getKey(),
            'admission_fee' => $this->money($data['admission_fee'] ?? 0),
            'hall_charge' => $this->money($data['hall_charge'] ?? 0),
        ]);

        AuditService::record('update', 'Class', $class->getKey(), [
            'level_id' => $level->getKey(),
            'arm_id' => $arm->getKey(),
        ]);

        return $this->presentClassPanel($class->getKey());
    }

    private function money(mixed $amount): string
    {
        if (! is_numeric($amount) || (float) $amount < 0) {
            throw new DomainException('Enter a fee of zero or more.');
        }

        return number_format((float) $amount, 2, '.', '');
    }

    public function updateFormTeacher(string $classId, ?string $teacherId): SchoolClass
    {
        $class = $this->findOrFail(SchoolClass::query(), $classId, 'Class not found.');
        $teacherId = $this->requireTeacher($teacherId);

        $class->update(['form_teacher_id' => $teacherId]);

        AuditService::record('update', 'Class', $class->getKey(), ['form_teacher_id' => $teacherId]);

        return $class;
    }

    /**
     * Save form teacher + every subject row (kind + teacher) in one go for the class panel AJAX form.
     *
     * @param  list<array{id:string,kind:string,teacher_id:?string}>  $rows
     */
    public function syncClassPanel(string $classId, ?string $formTeacherId, array $rows): array
    {
        $class = $this->findOrFail(SchoolClass::query(), $classId, 'Class not found.');

        DB::transaction(function () use ($class, $formTeacherId, $rows) {
            $teacherId = $this->requireTeacher($formTeacherId);
            if ($class->form_teacher_id !== $teacherId) {
                $class->update(['form_teacher_id' => $teacherId]);
                AuditService::record('update', 'Class', $class->getKey(), ['form_teacher_id' => $teacherId]);
            }

            $ids = collect($rows)->pluck('id')->all();
            $existing = ClassSubject::query()
                ->where('class_id', $class->getKey())
                ->whereIn('id', $ids)
                ->get()
                ->keyBy(fn (ClassSubject $cs) => (string) $cs->getKey());

            if ($existing->count() !== count($rows)) {
                throw new DomainException('One of the subjects is not in this class.');
            }

            foreach ($rows as $row) {
                $classSubject = $existing->get((string) $row['id']);
                $kind = $this->requireKind($row['kind']);
                $subjectTeacherId = $this->requireTeacher($row['teacher_id'] ?? null);

                if ($classSubject->kind !== $kind) {
                    $classSubject->update(['kind' => $kind]);
                    if ($kind === self::KIND_COMPULSORY) {
                        $this->attachToActiveEnrolments($classSubject);
                    }
                    AuditService::record('update', 'ClassSubject', $classSubject->getKey(), ['kind' => $kind]);
                }

                if ($classSubject->teacher_id !== $subjectTeacherId) {
                    $classSubject->update(['teacher_id' => $subjectTeacherId]);
                    AuditService::record('update', 'ClassSubject', $classSubject->getKey(), ['teacher_id' => $subjectTeacherId]);
                }
            }
        });

        return $this->presentClassPanel($class->getKey());
    }

    /**
     * JSON payload for the Academics class panel (AJAX select / after save).
     *
     * A subject teacher sees only the subjects they teach. The form teacher of the class sees every subject and its teacher.
     */
    public function presentClassPanel(string $classId, ?string $viewerStaffId = null): array
    {
        $class = $this->findClass($classId);
        if (! $class) {
            throw new DomainException('Class not found.');
        }

        $class->loadMissing(['level', 'arm', 'session', 'formTeacher']);
        $subjects = $this->classSubjects($class->getKey());
        if ($viewerStaffId && $class->form_teacher_id !== $viewerStaffId) {
            $subjects = $subjects->where('teacher_id', $viewerStaffId)->values();
        }

        return [
            'id' => $class->getKey(),
            'name' => $class->display_name,
            'level_id' => $class->level_id,
            'arm_id' => $class->arm_id,
            'admission_fee' => (float) $class->admission_fee,
            'hall_charge' => (float) $class->hall_charge,
            'session_id' => $class->session_id,
            'session_name' => $class->session?->name,
            'form_teacher_id' => $class->form_teacher_id ?? '',
            'form_teacher_name' => $class->formTeacher
                ? trim($class->formTeacher->first_name.' '.$class->formTeacher->last_name)
                : null,
            'full_subjects' => $viewerStaffId === null || $class->form_teacher_id === $viewerStaffId,
            'subjects' => $subjects
                ->map(fn (ClassSubject $cs) => $this->presentClassSubject($cs))
                ->values()
                ->all(),
        ];
    }

    /** @return array{id:string,subject_id:string,subject_name:?string,kind:string,teacher_id:string,teacher_name:?string,students_count:int} */
    public function presentClassSubject(ClassSubject $cs): array
    {
        $cs->loadMissing(['subject', 'teacher']);

        if (! isset($cs->students_count)) {
            $cs->loadCount('enrolmentSubjects as students_count');
        }

        return [
            'id' => $cs->getKey(),
            'subject_id' => $cs->subject_id,
            'subject_name' => $cs->subject?->name,
            'kind' => $cs->kind,
            'teacher_id' => $cs->teacher_id ?? '',
            'teacher_name' => $cs->teacher ? trim($cs->teacher->first_name.' '.$cs->teacher->last_name) : null,
            'students_count' => (int) $cs->students_count,
        ];
    }

    // --------------------------------------------------------- Class subjects

    public function createClassSubject(array $data): ClassSubject
    {
        $class = $this->findOrFail(SchoolClass::query(), $data['class_id'], 'Class not found.');
        $subject = $this->findOrFail(Subject::query(), $data['subject_id'], 'Subject not found.');
        $teacherId = $this->requireTeacher($data['teacher_id'] ?? null);
        $kind = $this->requireKind($data['kind'] ?? self::KIND_COMPULSORY);

        if (ClassSubject::query()->where('class_id', $class->getKey())->where('subject_id', $subject->getKey())->exists()) {
            throw new DomainException("{$subject->name} is already offered in this class.");
        }

        $classSubject = DB::transaction(function () use ($class, $subject, $teacherId, $kind) {
            $classSubject = ClassSubject::create([
                'class_id' => $class->getKey(),
                'subject_id' => $subject->getKey(),
                'teacher_id' => $teacherId,
                'kind' => $kind,
            ]);

            if ($kind === self::KIND_COMPULSORY) {
                $this->attachToActiveEnrolments($classSubject);
            }

            return $classSubject;
        });

        AuditService::record('create', 'ClassSubject', $classSubject->getKey());

        return $classSubject;
    }

    public function setSubjectKind(string $classSubjectId, string $kind): ClassSubject
    {
        $classSubject = $this->findOrFail(ClassSubject::query(), $classSubjectId, 'Class subject not found.');
        $kind = $this->requireKind($kind);

        DB::transaction(function () use ($classSubject, $kind) {
            $classSubject->update(['kind' => $kind]);

            if ($kind === self::KIND_COMPULSORY) {
                $this->attachToActiveEnrolments($classSubject);
            }
        });

        AuditService::record('update', 'ClassSubject', $classSubject->getKey(), ['kind' => $kind]);

        return $classSubject;
    }

    public function setSubjectTeacher(string $classSubjectId, ?string $teacherId): ClassSubject
    {
        $classSubject = $this->findOrFail(ClassSubject::query(), $classSubjectId, 'Class subject not found.');
        $teacherId = $this->requireTeacher($teacherId);

        $classSubject->update(['teacher_id' => $teacherId]);

        AuditService::record('update', 'ClassSubject', $classSubject->getKey(), ['teacher_id' => $teacherId]);

        return $classSubject;
    }

    /** Removes the subject from the class, including students' enrolment_subjects rows. */
    public function removeClassSubject(string $classSubjectId): ClassSubject
    {
        $classSubject = $this->findOrFail(ClassSubject::query(), $classSubjectId, 'Class subject not found.');
        $classId = (string) $classSubject->class_id;
        $id = (string) $classSubject->getKey();

        DB::transaction(function () use ($classSubject) {
            EnrolmentSubject::query()->where('class_subject_id', $classSubject->getKey())->delete();
            $classSubject->delete();
        });

        AuditService::record('delete', 'ClassSubject', $id);

        // Keep class_id readable for callers that refresh the panel after delete.
        $classSubject->class_id = $classId;

        return $classSubject;
    }

    /** Give every ACTIVE enrolment in the class this subject, skipping ones that already have it. */
    private function attachToActiveEnrolments(ClassSubject $classSubject): void
    {
        $rows = Enrolment::query()
            ->where('class_id', $classSubject->class_id)
            ->where('status', 'ACTIVE')
            ->pluck('id')
            ->map(fn ($enrolmentId) => [
                'id' => (string) Str::ulid(),
                'school_id' => $classSubject->school_id,
                'enrolment_id' => $enrolmentId,
                'class_subject_id' => $classSubject->getKey(),
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            // Unique (enrolment_id, class_subject_id) makes this skip-duplicates.
            EnrolmentSubject::query()->insertOrIgnore($chunk);
        }
    }

    // --------------------------------------------------- Subjects / depts / rooms

    public function subjects(): Collection
    {
        return Subject::query()->with('department')->orderBy('name')->get();
    }

    /**
     * Catalogue rows for the Subjects tab. Offerings are limited to one session.
     *
     * @return list<array<string, mixed>>
     */
    public function subjectCatalogue(?string $sessionId): array
    {
        return Subject::query()
            ->with([
                'department',
                'classSubjects' => function ($query) use ($sessionId) {
                    if ($sessionId) {
                        $query->whereHas('class', fn ($class) => $class->where('session_id', $sessionId));
                    } else {
                        $query->whereRaw('0 = 1');
                    }
                },
                'classSubjects.class.level',
                'classSubjects.class.arm',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Subject $subject) => $this->presentSubject($subject, $sessionId))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function presentSubject(Subject $subject, ?string $sessionId = null): array
    {
        $subject->loadMissing([
            'department',
            'classSubjects.class.level',
            'classSubjects.class.arm',
        ]);

        $offerings = $subject->classSubjects
            ->filter(fn (ClassSubject $cs) => $sessionId && $cs->class?->session_id === $sessionId)
            ->sortBy(fn (ClassSubject $cs) => (string) $cs->class?->display_name)
            ->map(fn (ClassSubject $cs) => [
                'class_name' => $cs->class?->display_name,
                'kind' => $cs->kind,
            ])
            ->values()
            ->all();

        return [
            'id' => $subject->getKey(),
            'name' => $subject->name,
            'department_id' => $subject->department_id ?? '',
            'department_name' => $subject->department?->name,
            'offerings' => $offerings,
        ];
    }

    public function createSubject(array $data): Subject
    {
        $name = trim($data['name']);
        $departmentId = $data['department_id'] ?? null;

        if ($departmentId) {
            $this->findOrFail(Department::query(), $departmentId, 'Department not found.');
        }
        if (Subject::query()->where('name', $name)->exists()) {
            throw new DomainException("A subject named {$name} already exists.");
        }

        $subject = Subject::create(['name' => $name, 'department_id' => $departmentId ?: null]);

        AuditService::record('create', 'Subject', $subject->getKey());

        return $subject;
    }

    /** @param  array{name:string,department_id:?string}  $data */
    public function updateSubject(string $subjectId, array $data, ?string $sessionId = null): array
    {
        $subject = $this->findOrFail(Subject::query(), $subjectId, 'Subject not found.');
        $name = trim($data['name']);
        $departmentId = $data['department_id'] ?? null;

        if ($departmentId) {
            $this->findOrFail(Department::query(), $departmentId, 'Department not found.');
        }

        $duplicate = Subject::query()->where('name', $name)->whereKeyNot($subject->getKey())->exists();
        if ($duplicate) {
            throw new DomainException("A subject named {$name} already exists.");
        }

        $subject->update([
            'name' => $name,
            'department_id' => $departmentId ?: null,
        ]);

        AuditService::record('update', 'Subject', $subject->getKey(), ['name' => $name, 'department_id' => $departmentId]);

        $sessionId ??= AcademicSession::query()->where('is_current', true)->value('id');

        return $this->presentSubject($subject->fresh(), $sessionId);
    }

    public function departments(): Collection
    {
        return Department::query()->with('hod')->withCount('subjects')->orderBy('name')->get();
    }

    public function createDepartment(array $data): Department
    {
        $name = trim($data['name']);
        $hodId = $this->requireTeacher($data['hod_id'] ?? null);

        if (Department::query()->where('name', $name)->exists()) {
            throw new DomainException("A department named {$name} already exists.");
        }

        $department = Department::create(['name' => $name, 'hod_id' => $hodId]);

        AuditService::record('create', 'Department', $department->getKey());

        return $department;
    }

    public function rooms(): Collection
    {
        return Room::query()->orderBy('name')->get();
    }

    public function createRoom(array $data): Room
    {
        $name = trim($data['name']);

        if (Room::query()->where('name', $name)->exists()) {
            throw new DomainException("A room named {$name} already exists.");
        }

        $room = Room::create(['name' => $name, 'capacity' => (int) ($data['capacity'] ?? 0)]);

        AuditService::record('create', 'Room', $room->getKey());

        return $room;
    }

    // ---------------------------------------------------------------- Grading

    public function defaultScale(): ?GradingScale
    {
        return GradingScale::query()
            ->where('is_default', true)
            ->with(['bands' => fn ($q) => $q->orderByDesc('min_score')])
            ->first();
    }

    /**
     * Replace all bands of the default scale.
     *
     * @param  array<int, array{min_score:int|string, max_score:int|string, letter:string, remark:string}>  $bands
     */
    public function replaceBands(array $bands): GradingScale
    {
        $scale = GradingScale::query()->where('is_default', true)->first()
            ?? throw new DomainException('Grading scale not found.');

        $ordered = collect($bands)
            ->map(fn ($b) => [
                'min_score' => (int) $b['min_score'],
                'max_score' => (int) $b['max_score'],
                'letter' => trim($b['letter']),
                'remark' => trim($b['remark']),
            ])
            ->sortBy('min_score')
            ->values();

        if ($ordered->isEmpty()) {
            throw new DomainException('Add at least one grading band.');
        }

        foreach ($ordered as $i => $band) {
            if ($band['min_score'] > $band['max_score'] || $band['min_score'] < 0 || $band['max_score'] > 100) {
                throw new DomainException('Score bands must sit between 0 and 100.');
            }
            $next = $ordered[$i + 1] ?? null;
            if ($next && $band['max_score'] >= $next['min_score']) {
                throw new DomainException('Score bands cannot overlap.');
            }
        }

        DB::transaction(function () use ($scale, $ordered) {
            $scale->bands()->delete();
            foreach ($ordered as $band) {
                $scale->bands()->create($band);
            }
        });

        AuditService::record('update', 'GradingScale', $scale->getKey(), ['bands' => $ordered->count()]);

        return $scale->load(['bands' => fn ($q) => $q->orderByDesc('min_score')]);
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<T>  $query
     * @return T
     */
    private function findOrFail($query, ?string $id, string $message)
    {
        return ($id ? $query->find($id) : null) ?? throw new DomainException($message);
    }

    /** Returns the id when it points at an active teacher of this school; null when empty. */
    private function requireTeacher(?string $staffId): ?string
    {
        if (! $staffId) {
            return null;
        }

        $ok = Staff::query()->whereKey($staffId)->where('is_teacher', true)->where('status', 'ACTIVE')->exists();

        return $ok ? $staffId : throw new DomainException('Choose an active teacher.');
    }

    private function requireKind(string $kind): string
    {
        return in_array($kind, [self::KIND_COMPULSORY, self::KIND_ELECTIVE], true)
            ? $kind
            : throw new DomainException('Subject kind must be compulsory or elective.');
    }
}
