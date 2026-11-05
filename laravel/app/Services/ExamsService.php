<?php

namespace App\Services;

use App\Enums\ReportTrait;
use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
use App\Models\GradingScale;
use App\Models\SchoolClass;
use App\Models\StudentGuardian;
use App\Models\SubjectScore;
use App\Models\Term;
use App\Models\TermReport;
use App\Models\TermReportRating;
use App\Models\User;
use App\Support\Tenant;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Business rules for Exams: score entry, broadsheets and report cards.
 *
 * Permissions: ADMIN / REGISTRAR / PLATFORM_OWNER ("office") can edit every subject;
 * a TEACHER can only edit class_subjects where they are the assigned subject teacher.
 * All models are tenant-scoped, so nothing here can reach another school's data.
 */
class ExamsService
{
    /** Sensible ceilings for each assessment (the legacy data peaks around CA 10 / exam 40-70). */
    public const MAX_CA = 10;

    public const MAX_EXAM = 70;

    public const MAX_TOTAL = 100;

    /** Request key => subject_scores column. */
    public const FIELDS = [
        'ca1' => 'ca1',
        'ca2' => 'ca2',
        'ca3' => 'ca3',
        'exam' => 'exam_score',
    ];

    // ----------------------------------------------------------------- Access

    public function isOffice(User $user): bool
    {
        return $user->isPlatformOwner() || $user->hasRole(Role::ADMIN, Role::REGISTRAR);
    }

    public function isTeacher(User $user): bool
    {
        return $user->role === Role::TEACHER && $user->staff_id !== null;
    }

    /**
     * Tabs the user may open on /exams (key => label).
     *
     * Subject teachers: mark sheet + broadsheet (their subjects only).
     * Report card is included only when this teacher form-teaches the selected class.
     */
    public function viewsFor(User $user, ?Term $term = null, ?SchoolClass $class = null): array
    {
        if ($this->isOffice($user)) {
            return ['marksheet' => 'Mark sheet', 'broadsheet' => 'Broadsheet', 'report' => 'Report card'];
        }

        if ($this->isTeacher($user)) {
            $views = ['subject' => 'Mark sheet', 'broadsheet' => 'Broadsheet'];
            if ($this->isFormTeacherOf($user, $class)) {
                $views['report'] = 'Report card';
            }

            return $views;
        }

        return ['report' => 'Report card'];
    }

    public function isFormTeacherOf(User $user, ?SchoolClass $class): bool
    {
        return $this->isTeacher($user)
            && $class
            && $class->form_teacher_id !== null
            && $class->form_teacher_id === $user->staff_id;
    }

    public function isFormTeacherInSession(User $user, string $sessionId): bool
    {
        if (! $this->isTeacher($user)) {
            return false;
        }

        return SchoolClass::query()
            ->where('session_id', $sessionId)
            ->where('form_teacher_id', $user->staff_id)
            ->exists();
    }

    public function canEditSubject(User $user, ClassSubject $classSubject): bool
    {
        if ($this->isOffice($user)) {
            return true;
        }

        return $this->isTeacher($user) && $classSubject->teacher_id === $user->staff_id;
    }

    /** Can the user open this student's report card? */
    public function canViewEnrolment(User $user, Enrolment $enrolment): bool
    {
        if ($this->isOffice($user)) {
            return true;
        }

        if ($user->role === Role::STUDENT) {
            return $user->student_id !== null && $user->student_id === $enrolment->student_id;
        }

        if ($user->role === Role::PARENT) {
            return $user->guardian_id !== null
                && StudentGuardian::query()
                    ->where('guardian_id', $user->guardian_id)
                    ->where('student_id', $enrolment->student_id)
                    ->exists();
        }

        if ($this->isTeacher($user)) {
            return $this->canViewClass($user, $enrolment->class()->first());
        }

        return false;
    }

    /** Teachers can read a class they form-teach or teach a subject in. */
    public function canViewClass(User $user, ?SchoolClass $class): bool
    {
        if (! $class) {
            return false;
        }

        if ($this->isOffice($user)) {
            return true;
        }

        if (! $this->isTeacher($user)) {
            return false;
        }

        return $class->form_teacher_id === $user->staff_id
            || ClassSubject::query()->where('class_id', $class->getKey())->where('teacher_id', $user->staff_id)->exists();
    }

    // ---------------------------------------------------------------- Lookups

    /** Terms newest first, with their session. */
    public function terms(): Collection
    {
        return Term::query()
            ->with('session')
            ->get()
            ->sortBy([
                fn ($a, $b) => ($b->session?->start_year ?? 0) <=> ($a->session?->start_year ?? 0),
                fn ($a, $b) => $b->sequence <=> $a->sequence,
            ])
            ->values();
    }

    /**
     * Terms the user may pick. Students and parents only see sessions they (or their children) were enrolled in.
     */
    public function termsFor(User $user): Collection
    {
        $terms = $this->terms();
        if (! $user->hasRole(Role::STUDENT, Role::PARENT)) {
            return $terms;
        }

        $sessionIds = $this->enrolledSessionIds($user);

        return $terms->filter(fn (Term $term) => $sessionIds->contains($term->session_id))->values();
    }

    /** @return BaseCollection<int, string> */
    private function enrolledSessionIds(User $user): BaseCollection
    {
        $studentIds = [];
        if ($user->role === Role::STUDENT && $user->student_id) {
            $studentIds = [$user->student_id];
        } elseif ($user->role === Role::PARENT && $user->guardian_id) {
            $studentIds = StudentGuardian::query()->where('guardian_id', $user->guardian_id)->pluck('student_id')->all();
        }

        if ($studentIds === []) {
            return collect();
        }

        return Enrolment::query()->whereIn('student_id', $studentIds)->pluck('session_id')->unique()->values();
    }

    public function findTerm(?string $id): ?Term
    {
        return $id ? Term::query()->with('session')->find($id) : null;
    }

    /** Latest term of the current session, falling back to the newest term overall. */
    public function defaultTerm(): ?Term
    {
        $current = AcademicSession::query()->where('is_current', true)->first();

        if ($current) {
            $term = Term::query()->with('session')->where('session_id', $current->getKey())->orderByDesc('sequence')->first();
            if ($term) {
                return $term;
            }
        }

        return $this->terms()->first();
    }

    /**
     * Classes of the term's session the user can open, sorted by level then arm.
     *
     * Teachers see classes they teach a subject in, plus any class they form-teach
     * (so form teachers can open the full broadsheet / report for their class).
     */
    public function classesFor(User $user, Term $term): Collection
    {
        $query = SchoolClass::query()->where('session_id', $term->session_id)->with(['level', 'arm', 'formTeacher']);

        if (! $this->isOffice($user)) {
            if (! $this->isTeacher($user)) {
                return new Collection;
            }

            $staffId = $user->staff_id;
            $query->where(fn ($q) => $q
                ->where('form_teacher_id', $staffId)
                ->orWhereHas('classSubjects', fn ($s) => $s->where('teacher_id', $staffId)));
        }

        return $this->sortClasses($query->get());
    }

    /**
     * @param  Collection<int, SchoolClass>  $classes
     * @return Collection<int, SchoolClass>
     */
    private function sortClasses(Collection $classes): Collection
    {
        return $classes
            ->sortBy([
                fn ($a, $b) => ($a->level?->sort_order ?? 0) <=> ($b->level?->sort_order ?? 0),
                fn ($a, $b) => strcmp((string) $a->level?->name, (string) $b->level?->name),
                fn ($a, $b) => strcmp((string) $a->arm?->name, (string) $b->arm?->name),
            ])
            ->values();
    }

    public function findClass(?string $classId): ?SchoolClass
    {
        return $classId ? SchoolClass::query()->with(['level', 'arm', 'formTeacher'])->find($classId) : null;
    }

    /** All subjects offered by a class, sorted by subject name. */
    public function classSubjects(SchoolClass $class): Collection
    {
        return ClassSubject::query()
            ->where('class_id', $class->getKey())
            ->with(['subject', 'teacher'])
            ->get()
            ->sortBy(fn (ClassSubject $cs) => strtolower((string) $cs->subject?->name))
            ->values();
    }

    /** Class subjects (across the term's classes) a teacher is assigned to; everything for the office. */
    public function taughtSubjects(User $user, Term $term, ?SchoolClass $class = null): Collection
    {
        $query = ClassSubject::query()
            ->whereHas('class', fn ($q) => $q->where('session_id', $term->session_id))
            ->with(['subject', 'class.level', 'class.arm', 'teacher']);

        if ($class) {
            $query->where('class_id', $class->getKey());
        }

        if (! $this->isOffice($user)) {
            if (! $this->isTeacher($user)) {
                return new Collection;
            }
            $query->where('teacher_id', $user->staff_id);
        }

        return $query->get()
            ->sortBy([
                fn ($a, $b) => strcmp(strtolower((string) $a->subject?->name), strtolower((string) $b->subject?->name)),
                fn ($a, $b) => ($a->class?->level?->sort_order ?? 0) <=> ($b->class?->level?->sort_order ?? 0),
                fn ($a, $b) => strcmp((string) $a->class?->arm?->name, (string) $b->class?->arm?->name),
            ])
            ->values();
    }

    /**
     * Students a teacher should mark for a class subject this term.
     *
     * Compulsory: every ACTIVE enrolment in the class (everyone takes it).
     * Elective: only students with an EnrolmentSubject row (Nest/legacy registered list).
     * Anyone who already has a score for the term is kept so past marks stay editable.
     *
     * @return Collection<int, Enrolment>
     */
    public function offeringRoster(ClassSubject $classSubject, Term $term): Collection
    {
        $class = $classSubject->relationLoaded('class')
            ? $classSubject->class
            : SchoolClass::query()->find($classSubject->class_id);

        if (! $class || $class->session_id !== $term->session_id) {
            return new Collection;
        }

        $scoredIds = SubjectScore::query()
            ->where('class_subject_id', $classSubject->getKey())
            ->where('term_id', $term->getKey())
            ->pluck('enrolment_id');

        if ($classSubject->kind !== 'ELECTIVE') {
            return $this->roster($class, $term);
        }

        $registeredIds = EnrolmentSubject::query()
            ->where('class_subject_id', $classSubject->getKey())
            ->pluck('enrolment_id');

        $ids = $registeredIds->merge($scoredIds)->unique()->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return Enrolment::query()
            ->whereIn('id', $ids)
            ->where('class_id', $class->getKey())
            ->where('session_id', $term->session_id)
            ->where(fn ($q) => $q
                ->where('status', 'ACTIVE')
                ->orWhereIn('id', $scoredIds))
            ->with(['student.studentGuardians.guardian'])
            ->get()
            ->sortBy(fn (Enrolment $e) => strtolower((string) $e->student?->full_name))
            ->values();
    }

    public function findClassSubject(?string $id): ?ClassSubject
    {
        return $id ? ClassSubject::query()->with(['subject', 'class.level', 'class.arm', 'teacher'])->find($id) : null;
    }

    /**
     * Enrolments of the class for the term's session, sorted by student name:
     * ACTIVE ones, plus anyone (e.g. promoted since) who already has scores for the term.
     *
     * @return Collection<int, Enrolment>
     */
    public function roster(SchoolClass $class, Term $term): Collection
    {
        return Enrolment::query()
            ->where('class_id', $class->getKey())
            ->where('session_id', $term->session_id)
            ->where(fn ($q) => $q
                ->where('status', 'ACTIVE')
                ->orWhereHas('scores', fn ($s) => $s->where('term_id', $term->getKey())))
            ->with(['student.studentGuardians.guardian'])
            ->get()
            ->sortBy(fn (Enrolment $e) => strtolower((string) $e->student?->full_name))
            ->values();
    }

    /**
     * Existing scores of one class subject in a term, keyed by enrolment id.
     *
     * @return BaseCollection<string, SubjectScore>
     */
    public function scoresFor(ClassSubject $classSubject, Term $term): BaseCollection
    {
        return SubjectScore::query()
            ->where('class_subject_id', $classSubject->getKey())
            ->where('term_id', $term->getKey())
            ->get()
            ->keyBy('enrolment_id');
    }

    /** Enrolment ids that registered the (elective) class subject. */
    public function registeredFor(ClassSubject $classSubject): array
    {
        return EnrolmentSubject::query()
            ->where('class_subject_id', $classSubject->getKey())
            ->pluck('enrolment_id')
            ->all();
    }

    // ---------------------------------------------------------------- Grading

    /** Bands of the default scale, highest first. */
    public function bands(): BaseCollection
    {
        $scale = GradingScale::query()->where('is_default', true)->first();

        if (! $scale) {
            return collect();
        }

        return $scale->bands()->orderByDesc('min_score')->get(['min_score', 'max_score', 'letter', 'remark'])
            ->map(fn ($b) => [
                'min' => (int) $b->min_score,
                'max' => (int) $b->max_score,
                'letter' => $b->letter,
                'remark' => $b->remark,
            ])
            ->values();
    }

    /**
     * Letter + remark for a total (rounded to a whole mark, since bands are whole numbers).
     *
     * @param  BaseCollection<int, array{min:int,max:int,letter:string,remark:string}>  $bands
     * @return array{letter: string, remark: string}|null
     */
    public function gradeFor(?float $total, BaseCollection $bands): ?array
    {
        if ($total === null) {
            return null;
        }

        $rounded = $this->wholeNumber($total);
        $band = $bands->first(fn ($b) => $rounded >= $b['min'] && $rounded <= $b['max']);

        return $band ? ['letter' => $band['letter'], 'remark' => $band['remark']] : null;
    }

    public function totalOf(SubjectScore $score): float
    {
        $raw = $score->total !== null
            ? (float) $score->total
            : (float) $score->ca1 + (float) $score->ca2 + (float) $score->ca3 + (float) $score->exam_score;

        return (float) $this->wholeNumber($raw);
    }

    /** Nearest whole number. Scores and averages are never shown with a decimal. */
    public function wholeNumber(float|int $value): int
    {
        return (int) round((float) $value);
    }

    // ------------------------------------------------------------ Score entry

    /**
     * Save (upsert) scores for a term.
     *
     * Entries are keyed [enrolment_id][class_subject_id] => ['ca1' =>, 'ca2' =>, 'ca3' =>, 'exam' =>, 'absent' =>].
     * A row whose four marks are all blank (and not absent) is skipped, so empty rows never create zero scores.
     * The whole batch is validated first; any problem aborts it without saving anything.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $entries
     * @return int number of score rows created or updated
     *
     * @throws AuthorizationException when a teacher tries to edit a subject they do not teach
     * @throws DomainException on invalid marks or mismatched student / subject / term
     */
    public function saveScores(User $user, Term $term, array $entries): int
    {
        $enrolmentIds = array_map('strval', array_keys($entries));
        $classSubjectIds = [];
        foreach ($entries as $perSubject) {
            foreach (array_keys((array) $perSubject) as $id) {
                $classSubjectIds[(string) $id] = true;
            }
        }

        $enrolments = Enrolment::query()->with('student')->whereIn('id', $enrolmentIds)->get()->keyBy('id');
        $classSubjects = ClassSubject::query()->with('subject')->whereIn('id', array_keys($classSubjectIds))->get()->keyBy('id');

        // Permission check first, so a forbidden request never reveals validation details.
        foreach ($classSubjectIds as $id => $_) {
            $classSubject = $classSubjects->get($id);
            if (! $classSubject) {
                throw new DomainException('One of the subjects could not be found.');
            }
            if (! $this->canEditSubject($user, $classSubject)) {
                throw new AuthorizationException('You can only enter scores for subjects you teach.');
            }
        }

        $existing = SubjectScore::query()
            ->where('term_id', $term->getKey())
            ->whereIn('enrolment_id', $enrolmentIds)
            ->whereIn('class_subject_id', array_keys($classSubjectIds))
            ->get()
            ->keyBy(fn (SubjectScore $s) => $s->enrolment_id.'|'.$s->class_subject_id);

        $operations = [];

        foreach ($entries as $enrolmentId => $perSubject) {
            $enrolment = $enrolments->get((string) $enrolmentId);
            if (! $enrolment) {
                throw new DomainException('One of the students could not be found.');
            }

            foreach ((array) $perSubject as $classSubjectId => $input) {
                $classSubject = $classSubjects->get((string) $classSubjectId);
                $label = ($enrolment->student?->full_name ?? 'Student').' / '.($classSubject->subject?->name ?? 'subject');

                if ($enrolment->class_id !== $classSubject->class_id) {
                    throw new DomainException("{$label}: the student is not in the class that offers this subject.");
                }
                if ($enrolment->session_id !== $term->session_id) {
                    throw new DomainException("{$label}: the term does not belong to the student's session.");
                }

                $values = $this->parseMarks((array) $input, $label);
                if ($values === null) {
                    continue; // blank row
                }

                $operations[] = [$existing->get($enrolment->getKey().'|'.$classSubject->getKey()), $enrolment, $classSubject, $values];
            }
        }

        if ($operations === []) {
            return 0;
        }

        DB::transaction(function () use ($operations, $term) {
            foreach ($operations as [$score, $enrolment, $classSubject, $values]) {
                if ($score) {
                    $score->update($values);
                } else {
                    SubjectScore::create($values + [
                        'enrolment_id' => $enrolment->getKey(),
                        'class_subject_id' => $classSubject->getKey(),
                        'term_id' => $term->getKey(),
                    ]);
                }
            }
        });

        AuditService::record('update', 'SubjectScore', $term->getKey(), [
            'term' => $term->name,
            'count' => count($operations),
            'subjects' => array_keys($classSubjectIds),
        ]);

        return count($operations);
    }

    /**
     * Validate one row of marks.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null column => value, or null when the row is blank
     */
    private function parseMarks(array $input, string $label): ?array
    {
        $absent = filter_var($input['absent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $values = [];
        $filled = false;
        foreach (self::FIELDS as $key => $column) {
            $max = $key === 'exam' ? self::MAX_EXAM : self::MAX_CA;
            $mark = $this->parseMark($input[$key] ?? null, $max, strtoupper($key === 'exam' ? 'exam' : $key), $label);
            $filled = $filled || $mark !== null;
            $values[$column] = $absent ? 0.0 : ($mark ?? 0.0);
        }

        if (! $absent && ! $filled) {
            return null;
        }

        if (array_sum($values) > self::MAX_TOTAL) {
            throw new DomainException("{$label}: the total cannot be more than ".self::MAX_TOTAL.'.');
        }

        return $values + ['absent' => $absent];
    }

    private function parseMark(mixed $value, int $max, string $name, string $label): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (! is_numeric($value)) {
            throw new DomainException("{$label}: {$name} must be a whole number.");
        }

        $raw = (float) $value;
        if ($raw != (int) $raw) {
            throw new DomainException("{$label}: {$name} must be a whole number.");
        }

        $mark = (float) (int) $raw;

        if ($mark < 0 || $mark > $max) {
            throw new DomainException("{$label}: {$name} must be between 0 and {$max}.");
        }

        return $mark;
    }

    // -------------------------------------------------------------- Broadsheet

    /**
     * Class + term matrix of totals.
     *
     * Office and the class form teacher see every subject. Other teachers only see
     * subjects they are assigned to teach; totals / average / position use those columns only.
     *
     * @param  'name'|'position'  $sort
     * @param  'asc'|'desc'  $dir
     * @return array{subjects: Collection<int, ClassSubject>, rows: BaseCollection<int, array<string, mixed>>, limited: bool, sort: string, dir: string}
     */
    public function broadsheet(SchoolClass $class, Term $term, ?User $user = null, string $sort = 'position', string $dir = 'asc'): array
    {
        $sort = in_array($sort, ['name', 'position'], true) ? $sort : 'position';
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        $roster = $this->roster($class, $term);
        $allSubjects = $this->classSubjects($class);

        $limited = false;
        if ($user && ! $this->isOffice($user) && ! $this->isFormTeacherOf($user, $class)) {
            $allSubjects = $allSubjects
                ->filter(fn (ClassSubject $cs) => $cs->teacher_id === $user->staff_id)
                ->values();
            $limited = true;
        }

        $scores = SubjectScore::query()
            ->where('term_id', $term->getKey())
            ->whereIn('enrolment_id', $roster->pluck('id'))
            ->when($limited, fn ($q) => $q->whereIn('class_subject_id', $allSubjects->modelKeys()))
            ->get();

        // Hide electives nobody has scores for; compulsory subjects always show (within the visible set).
        $scored = $scores->pluck('class_subject_id')->unique()->all();
        $subjects = $allSubjects
            ->filter(fn (ClassSubject $cs) => $cs->kind === AcademicsService::KIND_COMPULSORY || in_array($cs->getKey(), $scored, true))
            ->values();

        $visibleIds = $subjects->modelKeys();
        $byEnrolment = $scores->groupBy('enrolment_id');

        $rows = $roster->map(function (Enrolment $enrolment) use ($byEnrolment, $visibleIds) {
            $mine = $byEnrolment->get($enrolment->getKey(), collect());
            $cells = [];
            foreach ($mine as $score) {
                if (! in_array($score->class_subject_id, $visibleIds, true)) {
                    continue;
                }
                $cells[$score->class_subject_id] = $this->totalOf($score);
            }
            $sum = array_sum($cells);

            return [
                'enrolment' => $enrolment,
                'student' => $enrolment->student,
                'cells' => $cells,
                'sum' => $sum,
                'count' => count($cells),
                'average' => count($cells) ? (float) $this->wholeNumber($sum / count($cells)) : null,
                'position' => null,
            ];
        })->values();

        // Competition ranking over students with at least one score: ties share a place.
        $ranked = $rows->filter(fn ($r) => $r['count'] > 0)->sortByDesc('sum')->values();
        $place = 0;
        $previous = null;
        $positions = [];
        foreach ($ranked as $i => $row) {
            if ($previous === null || $row['sum'] !== $previous) {
                $place = $i + 1;
                $previous = $row['sum'];
            }
            $positions[$row['enrolment']->getKey()] = $place;
        }
        $rows = $rows->map(function ($r) use ($positions) {
            $r['position'] = $positions[$r['enrolment']->getKey()] ?? null;

            return $r;
        });

        $rows = $this->sortBroadsheetRows($rows, $sort, $dir);

        return [
            'subjects' => $subjects,
            'rows' => $rows,
            'limited' => $limited,
            'sort' => $sort,
            'dir' => $dir,
        ];
    }

    /**
     * @param  BaseCollection<int, array<string, mixed>>  $rows
     * @return BaseCollection<int, array<string, mixed>>
     */
    public function sortBroadsheetRows(BaseCollection $rows, string $sort, string $dir): BaseCollection
    {
        $descending = $dir === 'desc';

        if ($sort === 'name') {
            $sorted = $rows->sortBy(
                fn ($r) => strtolower((string) ($r['student']?->full_name ?? '')),
                SORT_NATURAL,
                $descending,
            );
        } else {
            // Position: ranked students first (1, 2, …); unranked last. Name breaks ties.
            $sorted = $rows->sort(function ($a, $b) use ($descending) {
                $pa = $a['position'];
                $pb = $b['position'];
                if ($pa === null && $pb === null) {
                    return strcasecmp((string) ($a['student']?->full_name ?? ''), (string) ($b['student']?->full_name ?? ''));
                }
                if ($pa === null) {
                    return 1;
                }
                if ($pb === null) {
                    return -1;
                }
                $cmp = $pa <=> $pb;

                return $descending ? -$cmp : $cmp;
            });
        }

        return $sorted->values();
    }

    /**
     * Branding payload for printed / PDF exam documents.
     *
     * @return array{school: \App\Models\School, logo_data_uri: ?string, signature_data_uri: ?string, primary: string, generated_at: \Illuminate\Support\Carbon}
     */
    public function documentBrand(): array
    {
        $school = Tenant::get() ?? throw new DomainException('No school context.');
        $school->loadMissing(['logo', 'principalSignature']);

        return [
            'school' => $school,
            'logo_data_uri' => $this->storedFileDataUri($school->logo),
            'signature_data_uri' => $this->storedFileDataUri($school->principalSignature),
            'primary' => $school->primary_color ?: '#a01010',
            'generated_at' => now(),
        ];
    }

    /**
     * Gold star for the highest broadsheet total. Drawn as an image because DomPDF's
     * bold face has no ★ glyph, so the character exports as a blank or a box.
     */
    public function highestStarDataUri(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAABf0lEQVR4nO1aWw6DMAzrpomr7IicYUfkKvthX0gIddCHEzck/kTT0rp2GrOlFAgEmFjmaWXWfzKLj4AHs/j+9N+fL2Ut7hXgngCaBXLNj2GDUACj6NnVp60C9wpwT4C6BUomP00buFeAewJULVATfLRsEArQKtQSezVU4F4B7glQsUDPWx9pG4QCjg/Y7+g0sFdVVl53JuFoqb/+uiMJuX5y2WDuQMRZIy3qsJZJuLpFiq8YiySUXKFVd6wlEkrnh6YhY2Qiagen5ilrRBJapsauMXMkElpH5u45ewQSevICLGgwiEAEJWjS0iQBlRLhUVODBGREdh+HTRKAVBmUgBFuhFqYVAASQQB7Aa1A2Q1GgEX/p2RYASjQCWD9P3ADLQvkNs74/RCiAMTmz55L4qVZrGSD22e0mqpaD6g9XS01QIpcnVbvZiS/X/SNEPIUpeqIWQAtYSlLwJugpHclGiRUAVqN61iHNoYv87SyMwB1DezNbxhlHYFAIBAIGMMPsJuk/b03AsQAAAAASUVORK5CYII=';
    }

    /**
     * DomPDF paper box [x0, y0, x1, y1] in points. Widens past A4 landscape when there
     * are many subject columns so the sheet is not clipped horizontally.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function broadsheetPaper(int $subjectCount): array
    {
        // A4 landscape ≈ 297 × 210 mm. Name + # + total/avg/pos ≈ 95 mm; each subject ≈ 16 mm
        // so a two-row header can show a readable run of each subject name.
        $widthMm = 95 + max($subjectCount, 1) * 16;
        $widthMm = max(297, min($widthMm, 840)); // grow up to ~A1 landscape width
        $heightMm = 210;

        $toPt = static fn (float $mm): float => $mm * 72 / 25.4;

        return [0.0, 0.0, $toPt($widthMm), $toPt($heightMm)];
    }

    private function storedFileDataUri(?\App\Models\StoredFile $file): ?string
    {
        if (! $file || ! Storage::disk('local')->exists($file->storage_key)) {
            return null;
        }

        $bytes = Storage::disk('local')->get($file->storage_key);
        $mime = $file->mime_type ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    // ------------------------------------------------------------ Report card

    /** Enrolments a student/parent may open for the term's session. */
    public function myEnrolments(User $user, Term $term): Collection
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

        return Enrolment::query()
            ->whereIn('student_id', $studentIds)
            ->where('session_id', $term->session_id)
            ->with(['student.studentGuardians.guardian', 'class.level', 'class.arm'])
            ->get()
            ->sortBy(fn (Enrolment $e) => strtolower((string) $e->student?->full_name))
            ->values();
    }

    public function findEnrolment(?string $id): ?Enrolment
    {
        return $id ? Enrolment::query()->with(['student', 'class.level', 'class.arm', 'session'])->find($id) : null;
    }

    /**
     * Report card data for one enrolment and term.
     *
     * @return array<string, mixed>
     */
    public function reportCard(Enrolment $enrolment, Term $term): array
    {
        $class = $enrolment->class()->with(['level', 'arm', 'formTeacher'])->first();
        $bands = $this->bands();

        $scores = SubjectScore::query()
            ->where('enrolment_id', $enrolment->getKey())
            ->where('term_id', $term->getKey())
            ->get()
            ->keyBy('class_subject_id');

        $registered = $this->registeredElectives($enrolment);

        $lines = $this->classSubjects($class)
            ->filter(fn (ClassSubject $cs) => $scores->has($cs->getKey())
                || $cs->kind === AcademicsService::KIND_COMPULSORY
                || in_array($cs->getKey(), $registered, true))
            ->map(function (ClassSubject $cs) use ($scores, $bands) {
                $score = $scores->get($cs->getKey());
                $total = $score ? $this->totalOf($score) : null;

                return [
                    'class_subject_id' => $cs->getKey(),
                    'subject' => $cs->subject?->name,
                    'score' => $score,
                    'total' => $score && $score->absent ? null : $total,
                    'grade' => $this->gradeFor($score && $score->absent ? null : $total, $bands),
                ];
            })
            ->values();

        $cumulative = (int) $term->sequence === 3 ? $this->cumulativeStanding($class, $term, $enrolment) : null;
        if ($cumulative) {
            $lines = $lines->map(function (array $line) use ($cumulative, $bands) {
                $subject = $cumulative['subjects'][$line['class_subject_id']] ?? ['terms' => [], 'aggregate' => null];
                $line['terms'] = $subject['terms'];
                $line['aggregate'] = $subject['aggregate'];
                $line['grade'] = $this->gradeFor($subject['aggregate'], $bands);

                return $line;
            });
        }

        $totals = $lines->pluck('total')->filter(fn ($t) => $t !== null);
        $sum = $totals->sum();

        if ($cumulative) {
            $mine = $cumulative['rows'][$enrolment->getKey()] ?? ['average' => null, 'sum' => 0.0, 'count' => 0, 'position' => null];

            return [
                'class' => $class,
                'lines' => $lines,
                'cumulative' => true,
                'sum' => $mine['sum'],
                'termSum' => $sum,
                'count' => $mine['count'],
                'average' => $mine['average'],
                'position' => $mine['position'],
                'classSize' => $cumulative['classSize'],
                'report' => TermReport::query()->with('ratings')->where('enrolment_id', $enrolment->getKey())->where('term_id', $term->getKey())->first(),
            ];
        }

        $broadsheet = $this->broadsheet($class, $term);
        $mine = $broadsheet['rows']->first(fn ($r) => $r['enrolment']->getKey() === $enrolment->getKey());

        return [
            'class' => $class,
            'lines' => $lines,
            'cumulative' => false,
            'sum' => $sum,
            'termSum' => $sum,
            'count' => $totals->count(),
            'average' => $totals->count() ? (float) $this->wholeNumber($sum / $totals->count()) : null,
            'position' => $mine['position'] ?? null,
            'classSize' => $broadsheet['rows']->filter(fn ($r) => $r['count'] > 0)->count(),
            'report' => TermReport::query()->with('ratings')->where('enrolment_id', $enrolment->getKey())->where('term_id', $term->getKey())->first(),
        ];
    }

    /**
     * On-screen charts for a report card. The PDF stays the plain card.
     *
     * Class overview (form teacher and office) adds the class subject averages and the grade spread.
     * Students and parents get their own comparison, classwork against the exam, and, in third term, the session line.
     *
     * @return array{
     *     cumulative: bool,
     *     comparison: list<array{subject: string, student: int|null, class: int|null}>,
     *     classwork: list<array{subject: string, ca: int, exam: int, caPercent: int, examPercent: int}>,
     *     classSubjects: list<array{subject: string, average: int}>,
     *     grades: list<array{letter: string, count: int}>,
     *     terms: list<array{label: string, student: int|null, class: int|null}>
     * }
     */
    public function reportCharts(Enrolment $focus, Term $term, bool $classOverview): array
    {
        $empty = [
            'cumulative' => (int) $term->sequence === 3,
            'comparison' => [],
            'classwork' => [],
            'classSubjects' => [],
            'grades' => [],
            'terms' => [],
        ];

        $class = $focus->class;
        if (! $class) {
            return $empty;
        }

        $cumulative = (int) $term->sequence === 3;
        $roster = $this->roster($class, $term);
        $subjects = $this->classSubjects($class);
        $bands = $this->bands();
        $sessionTerms = Term::query()->where('session_id', $term->session_id)->get()->keyBy(fn (Term $row) => $row->getKey());
        $scores = SubjectScore::query()
            ->whereIn('enrolment_id', $roster->modelKeys())
            ->whereIn('term_id', $cumulative ? $sessionTerms->keys() : [$term->getKey()])
            ->get()
            ->groupBy('enrolment_id');
        $registered = EnrolmentSubject::query()
            ->whereIn('enrolment_id', $roster->modelKeys())
            ->get()
            ->groupBy('enrolment_id');

        $buckets = [];
        $comparison = [];
        $classwork = [];
        $averages = [];
        $focusAverage = null;
        $termStudent = [];
        $termClass = [];

        foreach ($roster as $enrolment) {
            $bySubject = ($scores->get($enrolment->getKey()) ?? collect())->groupBy('class_subject_id');
            $offered = ($registered->get($enrolment->getKey()) ?? collect())->pluck('class_subject_id')->all();
            $values = [];
            $bySequence = [];

            foreach ($subjects as $classSubject) {
                $rowsForSubject = $bySubject->get($classSubject->getKey()) ?? collect();
                $current = $rowsForSubject->first(fn (SubjectScore $score) => $score->term_id === $term->getKey());
                $onCard = $current !== null
                    || $classSubject->kind === AcademicsService::KIND_COMPULSORY
                    || in_array($classSubject->getKey(), $offered, true);
                if (! $onCard) {
                    continue;
                }

                $termTotals = [];
                foreach ($rowsForSubject as $score) {
                    $sequence = (int) ($sessionTerms->get($score->term_id)?->sequence ?? 0);
                    if ($sequence < 1) {
                        continue;
                    }
                    $termTotals[$sequence] = $score->absent ? null : $this->totalOf($score);
                }

                $value = $cumulative
                    ? $this->mean(array_values(array_filter($termTotals, fn ($total) => $total !== null)))
                    : (($current && ! $current->absent) ? $this->totalOf($current) : null);

                if ($value !== null) {
                    $buckets[$classSubject->getKey()][] = $value;
                    $values[] = $value;
                }

                foreach ($termTotals as $sequence => $total) {
                    if ($total !== null) {
                        $bySequence[$sequence][] = $total;
                    }
                }

                if ($enrolment->getKey() !== $focus->getKey()) {
                    continue;
                }

                $comparison[] = [
                    'id' => $classSubject->getKey(),
                    'subject' => (string) $classSubject->subject?->name,
                    'student' => $value,
                ];

                if ($current && ! $current->absent) {
                    $ca = $this->wholeNumber((float) $current->ca1 + (float) $current->ca2 + (float) $current->ca3);
                    $exam = $this->wholeNumber((float) $current->exam_score);
                    $classwork[] = [
                        'subject' => (string) $classSubject->subject?->name,
                        'ca' => $ca,
                        'exam' => $exam,
                        'caPercent' => $this->share($ca, self::MAX_CA * 3),
                        'examPercent' => $this->share($exam, self::MAX_EXAM),
                    ];
                }
            }

            $average = $cumulative ? $this->percent($values) : $this->mean($values);
            if ($average !== null) {
                $averages[] = $average;
            }
            if ($enrolment->getKey() === $focus->getKey()) {
                $focusAverage = $average;
            }

            foreach ($bySequence as $sequence => $totals) {
                $point = $this->mean($totals);
                if ($point === null) {
                    continue;
                }
                if ($enrolment->getKey() === $focus->getKey()) {
                    $termStudent[$sequence] = $point;
                }
                $termClass[$sequence][] = $point;
            }
        }

        $classAverage = [];
        foreach ($buckets as $id => $totals) {
            $classAverage[$id] = $this->mean($totals);
        }

        $comparison = array_values(array_map(function (array $row) use ($classAverage) {
            $row['class'] = $classAverage[$row['id']] ?? null;
            unset($row['id']);

            return $row;
        }, array_filter($comparison, fn (array $row) => $row['student'] !== null || ($classAverage[$row['id']] ?? null) !== null)));

        $classSubjects = [];
        if ($classOverview) {
            foreach ($subjects as $classSubject) {
                $average = $classAverage[$classSubject->getKey()] ?? null;
                if ($average === null) {
                    continue;
                }
                $classSubjects[] = [
                    'subject' => (string) $classSubject->subject?->name,
                    'average' => $average,
                ];
            }
        }

        $grades = [];
        if ($classOverview && $averages !== []) {
            $counts = [];
            foreach ($averages as $average) {
                $letter = $this->gradeFor((float) $average, $bands)['letter'] ?? null;
                if ($letter) {
                    $counts[$letter] = ($counts[$letter] ?? 0) + 1;
                }
            }
            foreach ($bands as $band) {
                $grades[] = ['letter' => $band['letter'], 'count' => $counts[$band['letter']] ?? 0];
            }
        }

        $terms = [];
        if ($cumulative) {
            foreach ([1 => '1st', 2 => '2nd', 3 => '3rd'] as $sequence => $label) {
                $terms[] = [
                    'label' => $label,
                    'student' => $termStudent[$sequence] ?? null,
                    'class' => $this->mean($termClass[$sequence] ?? []),
                ];
            }
            $hasPoint = collect($terms)->contains(fn (array $point) => $point['student'] !== null || $point['class'] !== null);
            if (! $hasPoint) {
                $terms = [];
            }
        }

        return [
            'cumulative' => $cumulative,
            'comparison' => $comparison,
            'classwork' => $classwork,
            'classSubjects' => $classSubjects,
            'grades' => $grades,
            'terms' => $terms,
            'verdict' => $this->performanceVerdict($focusAverage, $averages, $comparison, $cumulative),
        ];
    }

    /**
     * A plain reading of one student: where they sit in the class, their best subjects, and the low ones.
     *
     * @param  list<int>  $classAverages
     * @param  list<array{subject: string, student: int|null, class: int|null}>  $comparison
     * @return array{average: int, classAverage: int|null, position: int, classSize: int, marker: int, side: string, sentence: string, strong: list<array{subject: string, score: int, class: int|null, note: string}>, weak: list<array{subject: string, score: int, class: int|null, note: string}>}|null
     */
    private function performanceVerdict(int|float|null $focusAverage, array $classAverages, array $comparison, bool $precise = false): ?array
    {
        if ($focusAverage === null || $classAverages === []) {
            return null;
        }

        $ranked = $classAverages;
        rsort($ranked);
        $position = 1;
        $previous = null;
        foreach ($ranked as $index => $average) {
            if ($previous === null || $average !== $previous) {
                $position = $index + 1;
                $previous = $average;
            }
            if ($average === $focusAverage) {
                break;
            }
        }

        $classSize = count($classAverages);
        $marker = $classSize <= 1 ? 50 : (int) round((($classSize - $position) / ($classSize - 1)) * 100);
        $side = match (true) {
            $classSize <= 1 => 'only',
            $marker >= 67 => 'top',
            $marker <= 33 => 'bottom',
            default => 'middle',
        };
        $sentence = match ($side) {
            'only' => 'The only student with scores in this class.',
            'top' => 'Near the top of the class.',
            'bottom' => 'Near the bottom of the class.',
            default => 'In the middle of the class.',
        };

        $scored = array_values(array_filter($comparison, fn (array $row) => $row['student'] !== null));
        usort($scored, fn (array $a, array $b) => $b['student'] <=> $a['student']);

        $strong = [];
        foreach ($scored as $row) {
            if (count($strong) >= 3 || $row['student'] < 70) {
                break;
            }
            $strong[] = [
                'subject' => $row['subject'],
                'score' => $row['student'],
                'class' => $row['class'],
                'note' => $this->standingNote($row['student'], $row['class']),
            ];
        }

        $strongNames = array_column($strong, 'subject');
        $weak = [];
        foreach (array_reverse($scored) as $row) {
            if (count($weak) >= 4 || in_array($row['subject'], $strongNames, true) || $row['student'] >= 70) {
                continue;
            }
            if ($row['student'] >= 60 && ($row['class'] === null || $row['student'] + 9 >= $row['class'])) {
                continue;
            }
            $weak[] = [
                'subject' => $row['subject'],
                'score' => $row['student'],
                'class' => $row['class'],
                'note' => $this->standingNote($row['student'], $row['class']),
            ];
        }

        return [
            'average' => $precise ? round((float) $focusAverage, 2) : $focusAverage,
            'classAverage' => $precise
                ? round(array_sum($classAverages) / count($classAverages), 2)
                : $this->mean($classAverages),
            'position' => $position,
            'place' => $this->placeLabel($position),
            'classSize' => $classSize,
            'marker' => $marker,
            'side' => $side,
            'sentence' => $sentence,
            'strong' => $strong,
            'weak' => $weak,
        ];
    }

    private function standingNote(int $score, ?int $class): string
    {
        if ($class === null) {
            return 'No class average yet';
        }

        $gap = $score - $class;
        if ($gap >= 5) {
            return $gap.' above the class';
        }
        if ($gap <= -5) {
            return abs($gap).' below the class';
        }

        return 'Level with the class';
    }

    private function placeLabel(int $position): string
    {
        $tail = $position % 100;

        $suffix = match (true) {
            $tail >= 11 && $tail <= 13 => 'th',
            $position % 10 === 1 => 'st',
            $position % 10 === 2 => 'nd',
            $position % 10 === 3 => 'rd',
            default => 'th',
        };

        return $position.$suffix;
    }

    /**
     * Cumulative percentage: the mean of the subject session scores, to two decimal places.
     *
     * @param  list<float|int>  $values
     */
    private function percent(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /** @param  list<float|int>  $values */
    private function mean(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        return $this->wholeNumber(array_sum($values) / count($values));
    }

    private function share(int $part, int $max): int
    {
        if ($max <= 0) {
            return 0;
        }

        return $this->wholeNumber($part / $max * 100);
    }

    /**
     * Third-term standing for a class: each subject average across the session's terms,
     * then each student's average of those, ranked against the class.
     *
     * A term is left out of a subject's average when the student has no score or was absent.
     *
     * @return array{
     *     subjects: array<string, array{terms: array<int, float|null>, aggregate: float|null}>,
     *     rows: array<string, array{average: float|null, sum: float, count: int, position: int|null}>,
     *     classSize: int
     * }
     */
    private function cumulativeStanding(SchoolClass $class, Term $term, Enrolment $focus): array
    {
        $terms = Term::query()->where('session_id', $term->session_id)->get()->keyBy(fn (Term $row) => $row->getKey());
        $roster = $this->roster($class, $term);
        $subjects = $this->classSubjects($class);
        $registered = EnrolmentSubject::query()
            ->whereIn('enrolment_id', $roster->modelKeys())
            ->get()
            ->groupBy('enrolment_id');
        $scores = SubjectScore::query()
            ->whereIn('enrolment_id', $roster->modelKeys())
            ->whereIn('term_id', $terms->keys())
            ->get()
            ->groupBy('enrolment_id');

        $rows = [];
        $breakdowns = [];
        foreach ($roster as $enrolment) {
            $bySubject = ($scores->get($enrolment->getKey()) ?? collect())->groupBy('class_subject_id');
            $offered = ($registered->get($enrolment->getKey()) ?? collect())->pluck('class_subject_id')->all();
            $aggregates = [];
            $breakdown = [];

            foreach ($subjects as $classSubject) {
                $rowsForSubject = $bySubject->get($classSubject->getKey()) ?? collect();
                $current = $rowsForSubject->first(fn (SubjectScore $score) => $score->term_id === $term->getKey());
                $onCard = $current !== null
                    || $classSubject->kind === AcademicsService::KIND_COMPULSORY
                    || in_array($classSubject->getKey(), $offered, true);
                if (! $onCard) {
                    continue;
                }

                $termTotals = [];
                foreach ($rowsForSubject as $score) {
                    $sequence = (int) ($terms->get($score->term_id)?->sequence ?? 0);
                    if ($sequence < 1) {
                        continue;
                    }
                    $termTotals[$sequence] = $score->absent ? null : $this->totalOf($score);
                }
                $counted = array_values(array_filter($termTotals, fn ($total) => $total !== null));
                $aggregate = $counted === [] ? null : (float) $this->wholeNumber(array_sum($counted) / count($counted));
                $breakdown[$classSubject->getKey()] = ['terms' => $termTotals, 'aggregate' => $aggregate];
                if ($aggregate !== null) {
                    $aggregates[] = $aggregate;
                }
            }

            $breakdowns[$enrolment->getKey()] = $breakdown;
            $average = $this->percent($aggregates);
            $rows[$enrolment->getKey()] = [
                'average' => $average,
                'sum' => (float) array_sum($aggregates),
                'count' => count($aggregates),
                'position' => null,
                'rankKey' => $average === null ? null : (int) round($average * 100),
            ];
        }

        $ranked = collect($rows)
            ->map(fn ($row, $id) => $row + ['id' => $id])
            ->filter(fn ($row) => $row['count'] > 0)
            ->sortByDesc('rankKey')
            ->values();
        $place = 0;
        $previous = null;
        foreach ($ranked as $index => $row) {
            if ($previous === null || $row['rankKey'] !== $previous) {
                $place = $index + 1;
                $previous = $row['rankKey'];
            }
            $rows[$row['id']]['position'] = $place;
        }

        return [
            'subjects' => $breakdowns[$focus->getKey()] ?? [],
            'rows' => $rows,
            'classSize' => $ranked->count(),
        ];
    }

    private function registeredElectives(Enrolment $enrolment): array
    {
        return EnrolmentSubject::query()->where('enrolment_id', $enrolment->getKey())->pluck('class_subject_id')->all();
    }

    // ------------------------------------------------------------- Term report

    /**
     * Save attendance days (office), behaviour ratings, and comments.
     * A form teacher may set the ratings and the teacher's comment for their own class.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function saveReport(User $user, Enrolment $enrolment, Term $term, array $input): TermReport
    {
        $enrolment->loadMissing('class');
        $office = $this->isOffice($user);
        if (! $office && ! $this->isFormTeacherOf($user, $enrolment->class)) {
            throw new AuthorizationException('Only the form teacher of this class can edit the report.');
        }

        if ($enrolment->session_id !== $term->session_id) {
            throw new DomainException('The term does not belong to the student\'s session.');
        }

        $days = null;
        if ($office && (array_key_exists('days_present', $input) || array_key_exists('days_absent', $input))) {
            $present = $this->parseDays($input['days_present'] ?? null, 'Days present');
            $absent = $this->parseDays($input['days_absent'] ?? null, 'Days absent');
            $open = (int) $term->days_school_open;
            if ($open > 0 && ($present > $open || $absent > $open || $present + $absent > $open)) {
                throw new DomainException("Days present and absent cannot add up to more than the {$open} days the school was open.");
            }
            $days = [$present, $absent];
        }

        $teacherComment = array_key_exists('teacher_comment', $input) ? $this->comment($input['teacher_comment']) : null;
        $principalComment = $office && array_key_exists('principal_comment', $input) ? $this->comment($input['principal_comment']) : null;
        $ratings = is_array($input['ratings'] ?? null) ? $input['ratings'] : null;

        $report = DB::transaction(function () use ($enrolment, $term, $days, $teacherComment, $principalComment, $ratings) {
            $report = TermReport::query()->firstOrCreate(
                ['enrolment_id' => $enrolment->getKey(), 'term_id' => $term->getKey()],
                ['days_present' => 0, 'days_absent' => 0],
            );

            if ($days) {
                [$report->days_present, $report->days_absent] = $days;
            }
            if ($teacherComment !== null) {
                $report->teacher_comment = $teacherComment;
            }
            if ($principalComment !== null) {
                $report->principal_comment = $principalComment;
            }
            $report->save();

            if ($ratings !== null) {
                $this->syncRatings($report, $ratings);
            }

            return $report;
        });

        AuditService::record('update', 'TermReport', $report->getKey(), [
            'enrolment_id' => $enrolment->getKey(),
            'term' => $term->name,
            'days_present' => $report->days_present,
            'days_absent' => $report->days_absent,
        ]);

        return $report->load('ratings');
    }

    /**
     * @param  array<string, mixed>  $ratings
     */
    private function syncRatings(TermReport $report, array $ratings): void
    {
        $report->load('ratings');

        foreach ($ratings as $key => $score) {
            $trait = ReportTrait::tryFrom((string) $key);
            if (! $trait) {
                continue;
            }

            $existing = $report->ratings->first(fn (TermReportRating $rating) => $rating->trait === $trait);
            if ($score === null || $score === '') {
                $existing?->delete();

                continue;
            }

            $score = (int) $score;
            if ($score < 1 || $score > 5) {
                throw new DomainException($trait->label().' must be from 1 to 5.');
            }

            if ($existing) {
                $existing->update(['score' => $score]);
            } else {
                $report->ratings()->create(['trait' => $trait, 'score' => $score]);
            }
        }
    }

    private function comment(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if (mb_strlen($text) > 255) {
            throw new DomainException('A comment must be 255 characters or fewer.');
        }

        return $text;
    }

    private function parseDays(mixed $value, string $name): int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return 0;
        }

        if (! is_numeric($value) || (float) $value != (int) $value || (int) $value < 0 || (int) $value > 366) {
            throw new DomainException("{$name} must be a whole number between 0 and 366.");
        }

        return (int) $value;
    }
}
