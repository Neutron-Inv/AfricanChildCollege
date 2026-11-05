<?php

namespace App\Services\Legacy;

use App\Enums\ReportTrait;
use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassSubject;
use App\Models\Department;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
use App\Models\Guardian;
use App\Models\GradingBand;
use App\Models\GradingScale;
use App\Models\Level;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\SubjectScore;
use App\Models\Term;
use App\Models\TermReport;
use App\Models\TermReportRating;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports the legacy school database (the `legacy` connection, read-only) into one school.
 *
 * Mapping (port of backend/src/import/legacy-import.ts):
 *  - teacher/admin/accountant/registrar -> staff + users      - student -> students (+ users)
 *  - parents -> guardians (+ users)                            - grade -> level / arm / class (per session)
 *  - exam -> sessions + terms                                  - subject + subject_routing -> subjects + class_subjects (ELECTIVE)
 *  - student_grade / student_exam votes -> enrolments          - student_exam -> subject_scores + term_reports (days, comments)
 *
 * NOT imported: fees, chat, friends, petty cash, salary, demo timetable, punch attendance.
 *
 * Every step is idempotent through legacy_links (entity + legacy key -> new record id), so re-running
 * (or re-running a failed chunk) never duplicates data. Passwords are hashed with the application's
 * hasher (bcrypt, or Argon2id when HASH_DRIVER=argon2id) and users must change them at first login.
 * Plaintext legacy passwords are held in memory only while hashing and are never logged.
 */
class LegacyImporter
{
    public const CONNECTION = 'legacy';

    /** Tables the import cannot run without. */
    public const REQUIRED_TABLES = ['student', 'teacher', 'grade', 'subject', 'subject_routing', 'exam', 'student_exam', 'student_grade'];

    /** Optional tables: skipped when absent. */
    public const OPTIONAL_TABLES = ['parents', 'admin', 'accountant', 'registrar', 'user', 'department', 'student_subject'];

    private LegacyLinks $links;

    /** @var array<string, string>|null exam id => session name */
    private ?array $examSessions = null;

    public function __construct(private readonly School $school)
    {
        $this->links = new LegacyLinks($school->getKey());
    }

    // ------------------------------------------------------------ Connection

    /**
     * The legacy connection, or a LegacyImportException that says what to fix.
     */
    public function db(): ConnectionInterface
    {
        try {
            $connection = DB::connection(self::CONNECTION);
            $connection->getPdo();
        } catch (Throwable $e) {
            throw new LegacyImportException(
                'The legacy database is not reachable ('.class_basename($e).'). '
                .'Check the LEGACY_DB_* settings in .env and that the legacy dump has been loaded into that database.',
            );
        }

        return $connection;
    }

    /** Throws a LegacyImportException when the connection or required tables are missing. */
    public function assertReady(): void
    {
        $this->db();

        $schema = Schema::connection(self::CONNECTION);
        $missing = array_values(array_filter(self::REQUIRED_TABLES, fn (string $t) => ! $schema->hasTable($t)));

        if ($missing !== []) {
            throw new LegacyImportException(
                'The legacy database is missing required tables: '.implode(', ', $missing).'. Load the legacy dump first.',
            );
        }
    }

    /**
     * Inclusive [from, to] id ranges covering a legacy table, for chunked jobs.
     *
     * @return array<int, array{0:int,1:int}>
     */
    public function idRanges(string $table, int $size, string $column = 'id'): array
    {
        $db = $this->db();
        if (! Schema::connection(self::CONNECTION)->hasTable($table)) {
            return [];
        }

        $min = $db->table($table)->min($column);
        $max = $db->table($table)->max($column);
        if ($min === null || $max === null) {
            return [];
        }

        $size = max(1, $size);
        $ranges = [];
        for ($from = (int) $min; $from <= (int) $max; $from += $size) {
            $ranges[] = [$from, $from + $size - 1];
        }

        return $ranges;
    }

    // ----------------------------------------------------------------- Staff

    /** @return array<string, int> */
    public function importStaff(): array
    {
        return $this->scoped(function () {
            $stats = ['staff' => 0, 'skipped' => 0, 'users' => 0, 'duplicate_emails' => 0, 'random_passwords' => 0];

            $definitions = [
                ['teacher', 'T-', Role::TEACHER, true],
                ['admin', 'admin-', Role::ADMIN, false],
                ['accountant', 'accountant-', Role::ACCOUNTANT, false],
                ['registrar', 'registrar-', Role::REGISTRAR, false],
            ];

            foreach ($definitions as [$table, $prefix, $role, $isTeacher]) {
                if (! $this->hasTable($table)) {
                    continue;
                }

                foreach ($this->rows($this->db()->table($table)->orderBy('id'))->chunk(50) as $chunk) {
                    $passwords = $this->legacyPasswords($chunk->pluck('email')->map(fn ($e) => LegacyMapper::email($e))->filter()->all());
                    $existing = $this->links->many($table, $chunk->pluck('id'));

                    DB::transaction(function () use ($chunk, $table, $prefix, $role, $isTeacher, $passwords, $existing, &$stats) {
                        foreach ($chunk as $row) {
                            if (isset($existing[(string) $row['id']])) {
                                $stats['skipped']++;

                                continue;
                            }

                            $index = $prefix.LegacyMapper::text($row['index_number'] ?? $row['id']);
                            while (Staff::query()->where('index_number', $index)->exists()) {
                                $index .= '-2';
                            }

                            $parts = preg_split('/\s+/', LegacyMapper::text($row['full_name'] ?? '') ?: 'Staff', 2);
                            $email = LegacyMapper::email($row['email'] ?? '');

                            $staff = Staff::create([
                                'index_number' => $index,
                                'first_name' => $parts[0],
                                'last_name' => $parts[1] ?? '',
                                'gender' => LegacyMapper::gender($row['gender'] ?? ''),
                                'phone' => LegacyMapper::text($row['phone'] ?? ''),
                                'email' => $email,
                                'address' => LegacyMapper::text($row['address'] ?? ''),
                                'is_teacher' => $isTeacher,
                                'status' => 'ACTIVE',
                                'legacy_id' => LegacyMapper::int($row['id']),
                            ]);

                            $this->links->remember($table, $row['id'], $staff->getKey());
                            $stats['staff']++;

                            $this->createUser($email, $role, ['staff_id' => $staff->getKey()], $passwords, $stats);
                        }
                    });
                }
            }

            return $stats;
        });
    }

    // -------------------------------------------------------------- Students

    /** @return array<string, int> */
    public function importStudents(int $from, int $to): array
    {
        return $this->scoped(function () use ($from, $to) {
            $stats = ['students' => 0, 'adopted' => 0, 'skipped' => 0, 'users' => 0, 'duplicate_emails' => 0, 'random_passwords' => 0];

            $rows = $this->rows($this->db()->table('student')->whereBetween('id', [$from, $to])->orderBy('id'));
            if ($rows->isEmpty()) {
                return $stats;
            }

            $passwords = $this->legacyPasswords($rows->pluck('email')->map(fn ($e) => LegacyMapper::email($e))->filter()->all());
            $existing = $this->links->many('student', $rows->pluck('index_number'));

            DB::transaction(function () use ($rows, $passwords, $existing, &$stats) {
                foreach ($rows as $row) {
                    $index = LegacyMapper::text($row['index_number'] ?? '');
                    if ($index === '') {
                        continue;
                    }
                    if (isset($existing[$index])) {
                        $stats['skipped']++;

                        continue;
                    }

                    // Adopt a student that already exists with this index (e.g. entered by hand).
                    $student = Student::query()->where('index_number', $index)->first();
                    if ($student) {
                        $this->links->remember('student', $index, $student->getKey());
                        $stats['adopted']++;

                        continue;
                    }

                    $email = LegacyMapper::email($row['email'] ?? '');
                    $student = Student::create([
                        'index_number' => $index,
                        'full_name' => LegacyMapper::text($row['full_name'] ?? '') ?: 'Unnamed student',
                        'gender' => LegacyMapper::gender($row['gender'] ?? ''),
                        'phone' => LegacyMapper::text($row['phone'] ?? ''),
                        'email' => $email,
                        'address' => LegacyMapper::text($row['address'] ?? ''),
                        'birth_date' => LegacyMapper::date($row['b_date'] ?? null),
                        'status' => strtolower(LegacyMapper::text($row['_status'] ?? '')) === 'leave' ? 'LEFT' : 'ACTIVE',
                        'legacy_id' => LegacyMapper::int($row['id']),
                    ]);

                    $this->links->remember('student', $index, $student->getKey());
                    $stats['students']++;

                    $this->createUser($email, Role::STUDENT, ['student_id' => $student->getKey()], $passwords, $stats);
                }
            });

            return $stats;
        });
    }

    // ------------------------------------------------------------- Guardians

    /** @return array<string, int> */
    public function importGuardians(int $from, int $to): array
    {
        return $this->scoped(function () use ($from, $to) {
            $stats = ['guardians' => 0, 'skipped' => 0, 'children_linked' => 0, 'users' => 0, 'duplicate_emails' => 0, 'random_passwords' => 0];

            if (! $this->hasTable('parents')) {
                return $stats;
            }

            $rows = $this->rows($this->db()->table('parents')->whereBetween('id', [$from, $to])->orderBy('id'));
            if ($rows->isEmpty()) {
                return $stats;
            }

            $passwords = $this->legacyPasswords($rows->pluck('email')->map(fn ($e) => LegacyMapper::email($e))->filter()->all());
            $existing = $this->links->many('guardian', $rows->pluck('id'));
            $children = $this->links->many('student', $rows->pluck('my_son_index')->filter());

            DB::transaction(function () use ($rows, $passwords, $existing, $children, &$stats) {
                foreach ($rows as $row) {
                    if (isset($existing[(string) $row['id']])) {
                        $stats['skipped']++;

                        continue;
                    }

                    $email = LegacyMapper::email($row['email'] ?? '');
                    $guardian = Guardian::create([
                        'full_name' => LegacyMapper::text($row['full_name'] ?? '') ?: 'Guardian',
                        'phone' => LegacyMapper::text($row['phone'] ?? ''),
                        'email' => $email,
                        'address' => LegacyMapper::text($row['address'] ?? ''),
                        'gender' => LegacyMapper::gender($row['gender'] ?? ''),
                        'legacy_index' => LegacyMapper::text($row['index_number'] ?? '') ?: null,
                    ]);

                    $childId = $children[(string) ($row['my_son_index'] ?? '')] ?? null;
                    if ($childId) {
                        StudentGuardian::query()->firstOrCreate(
                            ['student_id' => $childId, 'guardian_id' => $guardian->getKey()],
                            ['relationship' => 'parent'],
                        );
                        $stats['children_linked']++;
                    }

                    $this->links->remember('guardian', $row['id'], $guardian->getKey());
                    $stats['guardians']++;

                    $this->createUser($email, Role::PARENT, ['guardian_id' => $guardian->getKey()], $passwords, $stats);
                }
            });

            return $stats;
        });
    }

    // ------------------------------------------------- Classes and subjects

    /**
     * Sessions, terms, levels/arms, classes, departments, subjects and class_subjects
     * (small tables, so a single job). Imported offerings default to ELECTIVE.
     *
     * @return array<string, mixed>
     */
    public function importClassesAndSubjects(): array
    {
        return $this->scoped(function () {
            $stats = [
                'sessions' => 0, 'terms' => 0, 'classes' => 0, 'departments' => 0, 'subjects' => 0, 'class_subjects' => 0,
                'unusual_classes' => [], 'unparsed_exams' => [],
            ];
            $db = $this->db();

            $this->ensureGradingScale();

            $grades = $this->rows($db->table('grade')->orderBy('id'));
            $exams = $this->rows($db->table('exam')->orderBy('id'));
            $teachers = $this->links->all('teacher');

            // Levels and arms.
            $levels = Level::query()->pluck('id', 'name')->all();
            $arms = Arm::query()->pluck('id', 'name')->all();
            foreach ($grades as $grade) {
                $parsed = LegacyMapper::className($grade['name'] ?? '');
                if ($parsed['unusual'] && LegacyMapper::text($grade['name'] ?? '') !== '') {
                    $stats['unusual_classes'][] = $grade['name'];
                }
                if (! isset($levels[$parsed['level']])) {
                    $levels[$parsed['level']] = Level::create(['name' => $parsed['level'], 'sort_order' => min(255, count($levels) + 1)])->getKey();
                }
                if (! isset($arms[$parsed['arm']])) {
                    $arms[$parsed['arm']] = Arm::create(['name' => $parsed['arm']])->getKey();
                }
            }

            // Sessions and terms (from the exam names).
            $sessions = []; // name => AcademicSession
            foreach ($exams as $exam) {
                $parsed = LegacyMapper::examName($exam['name'] ?? '');
                if (! $parsed) {
                    if (LegacyMapper::text($exam['name'] ?? '') !== '') {
                        $stats['unparsed_exams'][] = $exam['name'];
                    }

                    continue;
                }

                if (! isset($sessions[$parsed['session']])) {
                    $sessions[$parsed['session']] = $this->session($parsed, $stats);
                }

                $this->term($sessions[$parsed['session']], $exam, $parsed, $stats);
            }

            // One current session if none is set yet: the latest one.
            if ($sessions !== [] && ! AcademicSession::query()->where('is_current', true)->exists()) {
                collect($sessions)->sortByDesc('start_year')->first()->update(['is_current' => true]);
            }

            // Classes: every legacy grade exists in every session.
            foreach ($sessions as $sessionName => $session) {
                foreach ($grades as $grade) {
                    $key = $sessionName.':'.$grade['id'];
                    if ($this->links->get('class', $key)) {
                        continue;
                    }

                    $parsed = LegacyMapper::className($grade['name'] ?? '');
                    $class = SchoolClass::query()
                        ->where('session_id', $session->getKey())
                        ->where('level_id', $levels[$parsed['level']])
                        ->where('arm_id', $arms[$parsed['arm']])
                        ->first();

                    if (! $class) {
                        $class = SchoolClass::create([
                            'session_id' => $session->getKey(),
                            'level_id' => $levels[$parsed['level']],
                            'arm_id' => $arms[$parsed['arm']],
                            'form_teacher_id' => $teachers[(string) ($grade['teacher_id'] ?? '')] ?? null,
                            'admission_fee' => LegacyMapper::number($grade['admission_fee'] ?? 0),
                            'hall_charge' => LegacyMapper::number($grade['hall_charge'] ?? 0),
                            'legacy_grade_id' => LegacyMapper::int($grade['id']),
                        ]);
                        $stats['classes']++;
                    }

                    $this->links->remember('class', $key, $class->getKey());
                }
            }

            // Departments.
            $departmentIds = [];
            if ($this->hasTable('department')) {
                foreach ($this->rows($db->table('department')->orderBy('dept_id')) as $row) {
                    $departmentIds[(string) $row['dept_id']] = $this->department($row, $teachers, $stats);
                }
            }

            // Subjects.
            $subjectIds = [];
            foreach ($this->rows($db->table('subject')->orderBy('id')) as $row) {
                $subjectIds[(string) $row['id']] = $this->subject($row, $departmentIds, $stats);
            }

            // Subject routing -> class_subjects (ELECTIVE by default).
            $classLinks = $this->links->all('class');
            $pairs = ClassSubject::query()->get(['class_id', 'subject_id'])
                ->mapWithKeys(fn ($cs) => [$cs->class_id.'|'.$cs->subject_id => true])->all();
            $routing = $this->rows($db->table('subject_routing')->orderBy('id'));

            foreach (array_keys($sessions) as $sessionName) {
                DB::transaction(function () use ($routing, $sessionName, $classLinks, $subjectIds, $teachers, &$pairs, &$stats) {
                    foreach ($routing as $row) {
                        $classId = $classLinks[$sessionName.':'.($row['grade_id'] ?? '')] ?? null;
                        $subjectId = $subjectIds[(string) ($row['subject_id'] ?? '')] ?? null;
                        if (! $classId || ! $subjectId || isset($pairs[$classId.'|'.$subjectId])) {
                            continue;
                        }

                        ClassSubject::create([
                            'class_id' => $classId,
                            'subject_id' => $subjectId,
                            'teacher_id' => $teachers[(string) ($row['teacher_id'] ?? '')] ?? null,
                            'kind' => 'ELECTIVE',
                            'fee' => 0,
                            'legacy_routing_id' => LegacyMapper::int($row['id']),
                        ]);
                        $pairs[$classId.'|'.$subjectId] = true;
                        $stats['class_subjects']++;
                    }
                });
            }

            $stats['unusual_classes'] = array_values(array_unique($stats['unusual_classes']));

            return $stats;
        });
    }

    // ------------------------------------------------------------ Enrolments

    /**
     * Enrolments (and registered subjects) for the students with legacy ids in [from, to].
     *
     * A student's class for a session is the grade used by most of their exam rows that session
     * (the authoritative record), falling back to student_grade.
     *
     * @return array<string, int>
     */
    public function importEnrolments(int $from, int $to): array
    {
        return $this->scoped(function () use ($from, $to) {
            $stats = ['enrolments' => 0, 'skipped' => 0, 'subject_links' => 0];
            $db = $this->db();

            $students = $this->rows($db->table('student')->whereBetween('id', [$from, $to]))->keyBy(fn ($r) => (string) $r['index_number']);
            if ($students->isEmpty()) {
                return $stats;
            }

            $indexes = $students->keys()->all();
            $studentIds = $this->links->many('student', $indexes); // index => student id
            $sessions = $this->sessionsByName();                  // name => AcademicSession
            $examSessions = $this->examSessions();
            $classLinks = $this->links->all('class');
            $currentStart = (int) AcademicSession::query()->where('is_current', true)->value('start_year');

            // Votes: index => session => grade => count.
            $votes = [];
            $voteRows = $db->table('student_exam')
                ->whereIn('index_number', $indexes)
                ->groupBy('index_number', 'exam_id', 'grade_id')
                ->selectRaw('index_number, exam_id, grade_id, count(*) as n')
                ->get();
            foreach ($voteRows as $vote) {
                $vote = array_change_key_case((array) $vote);
                $session = $examSessions[(string) $vote['exam_id']] ?? null;
                if ($session) {
                    $key = (string) $vote['index_number'];
                    $votes[$key][$session][(string) $vote['grade_id']] = ($votes[$key][$session][(string) $vote['grade_id']] ?? 0) + (int) $vote['n'];
                }
            }

            // Wanted enrolment per (index, session): [grade id, legacy student_grade id|null].
            $wanted = [];
            foreach ($votes as $index => $bySession) {
                foreach ($bySession as $session => $byGrade) {
                    uksort($byGrade, fn ($a, $b) => (int) $a <=> (int) $b);
                    arsort($byGrade); // stable: keeps the lower grade id first on ties
                    $wanted[$index][$session] = [(string) array_key_first($byGrade), null];
                }
            }
            foreach ($this->rows($db->table('student_grade')->whereIn('index_number', $indexes)->orderBy('id')) as $row) {
                $index = (string) $row['index_number'];
                $session = $this->sessionForYear($sessions, (string) ($row['year'] ?? ''));
                if ($session && ! isset($wanted[$index][$session]) && ! empty($row['grade_id'])) {
                    $wanted[$index][$session] = [(string) $row['grade_id'], LegacyMapper::int($row['id'])];
                }
            }

            $existing = Enrolment::query()->whereIn('student_id', array_values($studentIds))->get()
                ->keyBy(fn (Enrolment $e) => $e->student_id.'|'.$e->session_id);

            DB::transaction(function () use ($wanted, $studentIds, $sessions, $classLinks, $students, $currentStart, $existing, &$stats) {
                $newLinks = [];
                foreach ($wanted as $index => $bySession) {
                    $studentId = $studentIds[(string) $index] ?? null;
                    if (! $studentId) {
                        continue;
                    }

                    foreach ($bySession as $sessionName => [$gradeId, $legacyId]) {
                        $session = $sessions[$sessionName] ?? null;
                        $classId = $classLinks[$sessionName.':'.$gradeId] ?? null;
                        if (! $session || ! $classId) {
                            continue;
                        }

                        $current = $existing->get($studentId.'|'.$session->getKey());
                        if ($current) {
                            $stats['skipped']++;
                            $newLinks[$index.':'.$sessionName] = $current->getKey();

                            continue;
                        }

                        $left = strtolower(LegacyMapper::text($students[(string) $index]['_status'] ?? '')) === 'leave'
                            || ($currentStart > 0 && $session->start_year < $currentStart);

                        $enrolment = Enrolment::create([
                            'student_id' => $studentId,
                            'class_id' => $classId,
                            'session_id' => $session->getKey(),
                            'status' => $left ? 'LEFT' : 'ACTIVE',
                            'legacy_id' => $legacyId,
                        ]);
                        $newLinks[$index.':'.$sessionName] = $enrolment->getKey();
                        $stats['enrolments']++;
                    }
                }

                $this->links->rememberMany('enrolment', $newLinks);
            });

            $stats['subject_links'] = $this->importStudentSubjects($indexes, array_values($studentIds));

            return $stats;
        });
    }

    /**
     * student_subject -> enrolment_subjects for every enrolment (of those students) whose class
     * carries the routed legacy grade.
     *
     * @param  array<int, string>  $indexes
     * @param  array<int, string>  $studentIds
     */
    private function importStudentSubjects(array $indexes, array $studentIds): int
    {
        if (! $this->hasTable('student_subject')) {
            return 0;
        }

        $db = $this->db();
        $rows = $this->rows($db->table('student_subject')->whereIn('index_number', $indexes));
        if ($rows->isEmpty()) {
            return 0;
        }

        $routing = $this->rows($db->table('subject_routing')->whereIn('id', $rows->pluck('sr_id')->unique()->all()))
            ->keyBy(fn ($r) => (string) $r['id']);
        $studentByIndex = $this->links->many('student', $indexes);
        $subjects = $this->links->all('subject');
        $enrolments = Enrolment::query()->whereIn('student_id', $studentIds)->with('class')->get()->groupBy('student_id');
        $offers = ClassSubject::query()->whereIn('class_id', $enrolments->flatten()->pluck('class_id')->unique()->all())
            ->get(['id', 'class_id', 'subject_id'])->mapWithKeys(fn ($cs) => [$cs->class_id.'|'.$cs->subject_id => $cs->getKey()]);
        $have = EnrolmentSubject::query()->whereIn('enrolment_id', $enrolments->flatten()->pluck('id')->all())
            ->get(['enrolment_id', 'class_subject_id'])->mapWithKeys(fn ($es) => [$es->enrolment_id.'|'.$es->class_subject_id => true])->all();

        $new = [];
        foreach ($rows as $row) {
            $route = $routing->get((string) ($row['sr_id'] ?? ''));
            $studentId = $studentByIndex[(string) $row['index_number']] ?? null;
            $subjectId = $route ? ($subjects[(string) $route['subject_id']] ?? null) : null;
            if (! $route || ! $studentId || ! $subjectId) {
                continue;
            }

            foreach ($enrolments->get($studentId, collect()) as $enrolment) {
                if ((string) $enrolment->class?->legacy_grade_id !== (string) $route['grade_id']) {
                    continue;
                }
                $offerId = $offers->get($enrolment->class_id.'|'.$subjectId);
                $key = $enrolment->getKey().'|'.$offerId;
                if (! $offerId || isset($have[$key])) {
                    continue;
                }
                $have[$key] = true;
                $new[] = $this->row(['enrolment_id' => $enrolment->getKey(), 'class_subject_id' => $offerId]);
            }
        }

        foreach (array_chunk($new, 500) as $chunk) {
            EnrolmentSubject::query()->insert($chunk);
        }

        return count($new);
    }

    // ---------------------------------------------------------------- Scores

    /**
     * student_exam rows with legacy ids in [from, to] -> subject_scores + term_reports.
     *
     * @return array<string, int>
     */
    public function importScores(int $from, int $to): array
    {
        return $this->scoped(function () use ($from, $to) {
            $stats = [
                'scores' => 0, 'skipped' => 0, 'term_reports' => 0, 'missing_refs' => 0, 'cross_class' => 0,
                'absent' => 0, 'score_mismatches' => 0, 'class_subjects_created' => 0,
            ];

            $rows = $this->rows($this->db()->table('student_exam')->whereBetween('id', [$from, $to])->orderBy('id'));
            if ($rows->isEmpty()) {
                return $stats;
            }

            $examSessions = $this->examSessions();
            $terms = $this->links->all('term');
            $subjects = $this->links->all('subject');
            $classLinks = $this->links->all('class');

            // Resolve references for every row.
            $enrolmentKeys = [];
            foreach ($rows as $row) {
                $session = $examSessions[(string) $row['exam_id']] ?? null;
                if ($session) {
                    $enrolmentKeys[] = $row['index_number'].':'.$session;
                }
            }
            $enrolmentLinks = $this->links->many('enrolment', $enrolmentKeys);
            $enrolmentClass = Enrolment::query()->whereIn('id', array_values($enrolmentLinks))->pluck('class_id', 'id')->all();
            $scoreLinks = $this->links->many('score', $rows->pluck('id'));

            $valid = [];
            foreach ($rows as $row) {
                if (isset($scoreLinks[(string) $row['id']])) {
                    $stats['skipped']++;

                    continue;
                }

                $session = $examSessions[(string) $row['exam_id']] ?? null;
                $termId = $terms[(string) $row['exam_id']] ?? null;
                $subjectId = $subjects[(string) $row['subject_id']] ?? null;
                $enrolmentId = $session ? ($enrolmentLinks[$row['index_number'].':'.$session] ?? null) : null;

                if (! $termId || ! $subjectId || ! $enrolmentId) {
                    $stats['missing_refs']++;

                    continue;
                }

                $classId = $enrolmentClass[$enrolmentId] ?? null;
                if (! $classId || ($classLinks[$session.':'.$row['grade_id']] ?? null) !== $classId) {
                    $stats['cross_class']++; // score recorded against a different class than the enrolment

                    continue;
                }

                $valid[] = [$row, $termId, $subjectId, $enrolmentId, $classId];
            }

            if ($valid === []) {
                return $stats;
            }

            $enrolmentIds = array_values(array_unique(array_column($valid, 3)));
            $classIds = array_values(array_unique(array_column($valid, 4)));

            DB::transaction(function () use ($valid, $enrolmentIds, $classIds, &$stats) {
                // Class subjects (create missing offerings as ELECTIVE).
                $offers = ClassSubject::query()->whereIn('class_id', $classIds)->get(['id', 'class_id', 'subject_id'])
                    ->mapWithKeys(fn ($cs) => [$cs->class_id.'|'.$cs->subject_id => $cs->getKey()])->all();

                $haveScores = SubjectScore::query()->whereIn('enrolment_id', $enrolmentIds)
                    ->get(['enrolment_id', 'class_subject_id', 'term_id'])
                    ->mapWithKeys(fn ($s) => [$s->enrolment_id.'|'.$s->class_subject_id.'|'.$s->term_id => true])->all();
                $haveReports = TermReport::query()->whereIn('enrolment_id', $enrolmentIds)
                    ->get(['enrolment_id', 'term_id'])->mapWithKeys(fn ($r) => [$r->enrolment_id.'|'.$r->term_id => true])->all();
                $haveRegistered = EnrolmentSubject::query()->whereIn('enrolment_id', $enrolmentIds)
                    ->get(['enrolment_id', 'class_subject_id'])->mapWithKeys(fn ($r) => [$r->enrolment_id.'|'.$r->class_subject_id => true])->all();

                $scoreRows = [];
                $scoreLinks = [];
                $reportRows = [];
                $ratingRows = [];
                $reportLinks = [];
                $registered = [];

                foreach ($valid as [$row, $termId, $subjectId, $enrolmentId, $classId]) {
                    $offerKey = $classId.'|'.$subjectId;
                    if (! isset($offers[$offerKey])) {
                        $offers[$offerKey] = ClassSubject::create([
                            'class_id' => $classId, 'subject_id' => $subjectId, 'kind' => 'ELECTIVE', 'fee' => 0,
                        ])->getKey();
                        $stats['class_subjects_created']++;
                    }
                    $offerId = $offers[$offerKey];

                    $scoreKey = $enrolmentId.'|'.$offerId.'|'.$termId;
                    if (! isset($haveScores[$scoreKey])) {
                        $absent = (bool) preg_match('/absent/i', (string) ($row['marks'] ?? ''))
                            || (bool) preg_match('/absent/i', (string) ($row['total'] ?? ''));
                        $ca1 = $absent ? 0 : max(0, LegacyMapper::number($row['ca1'] ?? 0));
                        $ca2 = $absent ? 0 : max(0, LegacyMapper::number($row['ca2'] ?? 0));
                        $ca3 = $absent ? 0 : max(0, LegacyMapper::number($row['ca3'] ?? 0));
                        $exam = $absent ? 0 : max(0, LegacyMapper::number($row['marks'] ?? 0));

                        if ($absent) {
                            $stats['absent']++;
                        } elseif (abs($ca1 + $ca2 + $ca3 + $exam - LegacyMapper::number($row['total'] ?? 0)) > 0.01) {
                            $stats['score_mismatches']++;
                        }

                        $id = $this->newId();
                        $scoreRows[] = [
                            'id' => $id,
                            'school_id' => $this->school->getKey(),
                            'enrolment_id' => $enrolmentId,
                            'class_subject_id' => $offerId,
                            'term_id' => $termId,
                            'ca1' => $ca1,
                            'ca2' => $ca2,
                            'ca3' => $ca3,
                            'exam_score' => $exam,
                            'absent' => $absent ? 1 : 0,
                            'legacy_id' => LegacyMapper::int($row['id']),
                        ];
                        $scoreLinks[(string) $row['id']] = $id;
                        $haveScores[$scoreKey] = true;
                    } else {
                        $stats['skipped']++;
                    }

                    $registerKey = $enrolmentId.'|'.$offerId;
                    if (! isset($haveRegistered[$registerKey])) {
                        $haveRegistered[$registerKey] = true;
                        $registered[] = $this->row(['enrolment_id' => $enrolmentId, 'class_subject_id' => $offerId]);
                    }

                    // Days present/absent, comments and traits live on every exam row; the first one wins.
                    $reportKey = $enrolmentId.'|'.$termId;
                    if (! isset($haveReports[$reportKey])) {
                        $haveReports[$reportKey] = true;
                        $report = $this->termReportRow($row, $enrolmentId, $termId);
                        if ($report) {
                            $reportRows[] = $report['report'];
                            array_push($ratingRows, ...$report['ratings']);
                            $reportLinks[(string) $row['id']] = $report['report']['id'];
                        }
                    }
                }

                foreach (array_chunk($scoreRows, 500) as $chunk) {
                    SubjectScore::query()->insert($chunk);
                }
                foreach (array_chunk($registered, 500) as $chunk) {
                    EnrolmentSubject::query()->insert($chunk);
                }
                foreach (array_chunk($reportRows, 500) as $chunk) {
                    TermReport::query()->insert($chunk);
                }
                foreach (array_chunk($ratingRows, 500) as $chunk) {
                    TermReportRating::query()->insert($chunk);
                }

                $this->links->rememberMany('score', $scoreLinks);
                $this->links->rememberMany('term_report', $reportLinks);

                $stats['scores'] += count($scoreRows);
                $stats['term_reports'] += count($reportRows);
            });

            return $stats;
        });
    }

    /**
     * Days, comments, and behaviour ratings from one legacy exam row.
     * Null when the row carries nothing worth a report.
     *
     * @return array{report: array<string, mixed>, ratings: list<array<string, mixed>>}|null
     */
    private function termReportRow(array $row, string $enrolmentId, string $termId): ?array
    {
        $ratings = [];
        foreach (ReportTrait::cases() as $trait) {
            $score = LegacyMapper::trait($row[$trait->value] ?? null);
            if ($score !== null) {
                $ratings[$trait->value] = $score;
            }
        }

        $present = max(0, (int) round(LegacyMapper::number($row['present'] ?? 0)));
        $absent = max(0, (int) round(LegacyMapper::number($row['absent'] ?? 0)));
        $teacher = Str::limit(LegacyMapper::text($row['tcomment'] ?? ''), 250, '');
        $principal = Str::limit(LegacyMapper::text($row['pcomment'] ?? ''), 250, '');

        if (! $present && ! $absent && $teacher === '' && $principal === '' && $ratings === []) {
            return null;
        }

        $report = $this->row([
            'enrolment_id' => $enrolmentId,
            'term_id' => $termId,
            'days_present' => min($present, 65535),
            'days_absent' => min($absent, 65535),
            'teacher_comment' => $teacher,
            'principal_comment' => $principal,
        ]);

        $ratingRows = [];
        foreach ($ratings as $trait => $score) {
            $ratingRows[] = $this->row([
                'term_report_id' => $report['id'],
                'trait' => $trait,
                'score' => $score,
            ]);
        }

        return ['report' => $report, 'ratings' => $ratingRows];
    }

    // --------------------------------------------------------------- Helpers

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    private function scoped(callable $callback): mixed
    {
        return Tenant::run($this->school, $callback);
    }

    private function hasTable(string $table): bool
    {
        return Schema::connection(self::CONNECTION)->hasTable($table);
    }

    /**
     * Legacy rows as arrays with lower-cased keys (the dump mixes CA1 / Total with lower-case columns).
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function rows($query): Collection
    {
        return $query->get()->map(fn ($row) => array_change_key_case((array) $row, CASE_LOWER));
    }

    private function newId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * A bulk-insert row with id + school_id filled in.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function row(array $attributes): array
    {
        return ['id' => $this->newId(), 'school_id' => $this->school->getKey()] + $attributes;
    }

    /**
     * Legacy login passwords by (lower-case) email, from the legacy `user` table (first row wins).
     * Held in memory only long enough to be hashed.
     *
     * @param  array<int, string>  $emails
     * @return array<string, string>
     */
    private function legacyPasswords(array $emails): array
    {
        $emails = array_values(array_unique($emails));
        if ($emails === [] || ! $this->hasTable('user')) {
            return [];
        }

        $map = [];
        foreach ($this->rows($this->db()->table('user')->whereIn('email', $emails)->orderBy('id')) as $row) {
            if (($row['type'] ?? '') === 'type') {
                continue; // header row that leaked into the dump
            }
            $email = strtolower(trim((string) $row['email']));
            $map[$email] ??= (string) ($row['password'] ?? '');
        }

        return $map;
    }

    /**
     * Create a login for an imported person (skipped without a usable, unused email).
     * The password is the legacy one when known, otherwise 12345 (the shared school password).
     * Either way it is hashed and never logged. Imported people are not forced to change it,
     * so a short legacy password still signs them in.
     *
     * @param  array<string, string>  $link  one of staff_id / student_id / guardian_id
     * @param  array<string, string>  $passwords
     * @param  array<string, int>  $stats
     */
    private function createUser(string $email, Role $role, array $link, array $passwords, array &$stats): void
    {
        if ($email === '') {
            return;
        }

        if (User::query()->where('school_id', $this->school->getKey())->where('email', $email)->exists()) {
            $stats['duplicate_emails']++;

            return;
        }

        $plain = $passwords[$email] ?? '';
        if ($plain === '') {
            $plain = '12345';
            $stats['random_passwords']++;
        }

        User::create([
            'school_id' => $this->school->getKey(),
            'email' => $email,
            'password' => Hash::make($plain),
            'role' => $role,
            'must_change_password' => false,
        ] + $link);
        $stats['users']++;
    }

    private function ensureGradingScale(): void
    {
        if (GradingScale::query()->where('is_default', true)->exists()) {
            return;
        }

        $scale = GradingScale::create(['name' => 'Default', 'is_default' => true]);
        foreach ([[70, 100, 'A', 'Excellent'], [60, 69, 'B', 'V.Good'], [50, 59, 'C', 'Good'], [45, 49, 'D', 'Credit'], [40, 44, 'E', 'Pass'], [0, 39, 'F', 'Failed']] as [$min, $max, $letter, $remark]) {
            GradingBand::create(['scale_id' => $scale->getKey(), 'min_score' => $min, 'max_score' => $max, 'letter' => $letter, 'remark' => $remark]);
        }
    }

    /**
     * @param  array{session: string, start_year: int, end_year: int}  $parsed
     * @param  array<string, mixed>  $stats
     */
    private function session(array $parsed, array &$stats): AcademicSession
    {
        $linked = $this->links->get('session', $parsed['session']);
        $session = $linked ? AcademicSession::query()->find($linked) : null;

        if (! $session) {
            $session = AcademicSession::query()->where('name', $parsed['session'])->first();
            if (! $session) {
                $session = AcademicSession::create([
                    'name' => $parsed['session'],
                    'start_year' => $parsed['start_year'],
                    'end_year' => $parsed['end_year'],
                    'is_current' => false,
                ]);
                $stats['sessions']++;
            }
            $this->links->remember('session', $parsed['session'], $session->getKey());
        }

        return $session;
    }

    /**
     * @param  array<string, mixed>  $exam
     * @param  array{sequence: int, term_name: string}  $parsed
     * @param  array<string, mixed>  $stats
     */
    private function term(AcademicSession $session, array $exam, array $parsed, array &$stats): void
    {
        if ($this->links->get('term', $exam['id'])) {
            return;
        }

        $term = Term::query()->where('session_id', $session->getKey())->where('sequence', $parsed['sequence'])->first();
        if (! $term) {
            $term = Term::create([
                'session_id' => $session->getKey(),
                'sequence' => $parsed['sequence'],
                'name' => $parsed['term_name'],
                'days_school_open' => (int) LegacyMapper::number($exam['schopen'] ?? 0),
                'result_date' => LegacyMapper::date($exam['r_date'] ?? null),
                'legacy_exam_id' => LegacyMapper::int($exam['id']),
            ]);
            $stats['terms']++;
        }

        $this->links->remember('term', $exam['id'], $term->getKey());
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $teachers
     * @param  array<string, mixed>  $stats
     */
    private function department(array $row, array $teachers, array &$stats): ?string
    {
        $existing = $this->links->get('department', $row['dept_id']);
        if ($existing) {
            return $existing;
        }

        $name = LegacyMapper::text($row['dept_name'] ?? '') ?: 'Department '.$row['dept_id'];
        $linked = $this->links->all('department');
        $department = Department::query()->where('name', $name)->first();

        if ($department && in_array($department->getKey(), $linked, true)) {
            // Name taken by another legacy department.
            $name = $name.' ('.$row['dept_id'].')';
            $department = Department::query()->where('name', $name)->first();
        }

        if (! $department) {
            $department = Department::create([
                'name' => $name,
                'hod_id' => $teachers[(string) ($row['teacher_id'] ?? '')] ?? null,
            ]);
            $stats['departments']++;
        }

        $this->links->remember('department', $row['dept_id'], $department->getKey());

        return $department->getKey();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, ?string>  $departmentIds
     * @param  array<string, mixed>  $stats
     */
    private function subject(array $row, array $departmentIds, array &$stats): string
    {
        $existing = $this->links->get('subject', $row['id']);
        if ($existing) {
            return $existing;
        }

        $name = LegacyMapper::text($row['name'] ?? '') ?: 'Subject '.$row['id'];
        $subject = Subject::query()->where('name', $name)->first();

        if ($subject && in_array($subject->getKey(), $this->links->all('subject'), true)) {
            $name = $name.' ('.$row['id'].')';
            $subject = Subject::query()->where('name', $name)->first();
        }

        if (! $subject) {
            $deptKey = (string) ($row['dept_id'] ?? '');
            $subject = Subject::create([
                'name' => $name,
                'department_id' => $deptKey !== '' && $deptKey !== '0' ? ($departmentIds[$deptKey] ?? null) : null,
            ]);
            $stats['subjects']++;
        }

        $this->links->remember('subject', $row['id'], $subject->getKey());

        return $subject->getKey();
    }

    /** @return array<string, AcademicSession> session name => session, for sessions created by this import */
    private function sessionsByName(): array
    {
        $ids = $this->links->all('session'); // name => id
        $sessions = AcademicSession::query()->whereIn('id', array_values($ids))->get()->keyBy('id');

        $map = [];
        foreach ($ids as $name => $id) {
            if ($sessions->has($id)) {
                $map[$name] = $sessions->get($id);
            }
        }

        return $map;
    }

    /** exam id => session name (only exams whose name parses). */
    private function examSessions(): array
    {
        if ($this->examSessions === null) {
            $this->examSessions = [];
            foreach ($this->rows($this->db()->table('exam')) as $exam) {
                $parsed = LegacyMapper::examName($exam['name'] ?? '');
                if ($parsed) {
                    $this->examSessions[(string) $exam['id']] = $parsed['session'];
                }
            }
        }

        return $this->examSessions;
    }

    /**
     * Session a student_grade "year" belongs to: the one starting that year, else the one ending in it.
     *
     * @param  array<string, AcademicSession>  $sessions
     */
    private function sessionForYear(array $sessions, string $year): ?string
    {
        foreach ($sessions as $name => $session) {
            if ((string) $session->start_year === $year) {
                return $name;
            }
        }
        foreach ($sessions as $name => $session) {
            if ((string) $session->end_year === $year) {
                return $name;
            }
        }

        return null;
    }
}
