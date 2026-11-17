<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Enrolment;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Support\Tenant;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Adds the green (new) rows from the 2026/2027 nursery and primary register,
 * then puts every pupil on that register into the class written on their sheet.
 * Someone already in that class is left there. A register number that is already
 * used is replaced only when a new pupil is created.
 */
class PrimaryNewStudentImporter
{
    /** When the target session is already current, a new enrolment retires the other sessions. */
    private bool $closeOtherSessions = true;

    public function __construct(private EnrolmentService $enrolment) {}

    /**
     * @param  array{workbook_indexes: array<int|string, int>, students: list<array<string, mixed>>, register: list<array<string, mixed>>}  $roster
     * @return array{created: int, placed: int, skipped: int, failed: int, in_class: int, moved: int, enrolled: int, missing: int, lines: list<string>}
     */
    public function import(School $school, array $roster, bool $dryRun = false, ?string $sessionName = null): array
    {
        return Tenant::run($school, function () use ($roster, $dryRun, $sessionName) {
            $session = $this->resolveSession($sessionName);
            $this->closeOtherSessions = (bool) $session->is_current;
            $classes = $this->classes($session);
            $workbook = $this->counts($roster['workbook_indexes'] ?? []);
            $students = $roster['students'] ?? [];
            $green = [];
            foreach ($students as $row) {
                $index = trim((string) ($row['index'] ?? ''));
                if ($index !== '') {
                    $green[$index] = ($green[$index] ?? 0) + 1;
                }
            }

            $taken = [];
            foreach (Student::query()->pluck('index_number') as $index) {
                $taken[(string) $index] = true;
            }

            $claimed = [];
            $handled = [];
            $stats = [
                'created' => 0, 'placed' => 0, 'skipped' => 0, 'failed' => 0,
                'in_class' => 0, 'moved' => 0, 'enrolled' => 0, 'missing' => 0,
                'lines' => [],
            ];
            if (! $session->is_current) {
                $stats['lines'][] = "Session {$session->name} is not the current session, so other sessions were left as they are.";
            }

            foreach ($students as $row) {
                $name = trim((string) ($row['full_name'] ?? ''));
                $level = trim((string) ($row['level'] ?? ''));
                $arm = trim((string) ($row['arm'] ?? ''));
                $label = trim($level.' '.$arm);
                $want = trim((string) ($row['index'] ?? ''));

                if ($name === '' || $level === '') {
                    $stats['failed']++;
                    $stats['lines'][] = "Skipped a row with no name or class.";

                    continue;
                }

                $class = $classes[$this->key($level, $arm)] ?? null;
                if ($class === null) {
                    $stats['failed']++;
                    $stats['lines'][] = "{$name}: no current class {$label}.";

                    continue;
                }

                $keep = $want !== ''
                    && ! isset($taken[$want])
                    && ! isset($claimed[$want])
                    && ($workbook[$want] ?? 0) <= ($green[$want] ?? 0);

                if ($keep) {
                    $index = $want;
                    $claimed[$want] = true;
                    $note = 'created';
                } else {
                    $index = $this->nextIndex($workbook, $taken);
                    $note = $want === ''
                        ? "created as {$index} (no register number)"
                        : "created as {$index} ({$want} was already used)";
                }
                $taken[$index] = true;

                $existing = Student::query()->where('full_name', $name)->first();
                if ($existing) {
                    $result = $this->settleExisting($existing, $class, $row, $label, $dryRun);
                    $this->remember($stats, $result);
                    if ($result['status'] === 'placed') {
                        $handled[$name] = $class->getKey();
                    }

                    continue;
                }

                if ($dryRun) {
                    $handled[$name] = $class->getKey();
                    $stats['created']++;
                    $stats['lines'][] = "{$name}: {$label}, {$index} ({$note}, dry run).";

                    continue;
                }

                DB::transaction(function () use ($row, $name, $index, $class) {
                    $student = Student::create([
                        'index_number' => $index,
                        'full_name' => $name,
                        'gender' => $this->gender($row['gender'] ?? ''),
                        'phone' => $this->phone($row['phone'] ?? ''),
                        'email' => '',
                        'address' => mb_substr(trim((string) ($row['address'] ?? '')), 0, 255),
                        'birth_date' => $this->birthDate($row['birth_date'] ?? null),
                        'status' => 'ACTIVE',
                    ]);
                    $this->linkGuardian($student, $row);
                    $this->enrolment->place($student->getKey(), $class->getKey(), $this->closeOtherSessions);
                });

                $handled[$name] = $class->getKey();
                $stats['created']++;
                $stats['lines'][] = "{$name}: {$label}, {$index} ({$note}).";
            }

            $this->routeRegister($roster['register'] ?? [], $session, $classes, $dryRun, $handled, $stats);

            return $stats;
        });
    }

    /**
     * @param  array{created: int, placed: int, skipped: int, failed: int, lines: list<string>}  $stats
     * @param  array{status: string, line: string}  $result
     */
    private function remember(array &$stats, array $result): void
    {
        $stats[$result['status']]++;
        $stats['lines'][] = $result['line'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{status: string, line: string}
     */
    private function settleExisting(Student $student, SchoolClass $class, array $row, string $label, bool $dryRun): array
    {
        $active = $student->enrolments()
            ->where('session_id', $class->session_id)
            ->where('status', 'ACTIVE')
            ->with('class.level', 'class.arm')
            ->first();

        if ($active) {
            $where = trim(($active->class?->level?->name ?? '').' '.($active->class?->arm?->name ?? ''));

            return ['status' => 'skipped', 'line' => "{$student->full_name}: already in {$where}."];
        }

        if ($dryRun) {
            return ['status' => 'placed', 'line' => "{$student->full_name}: would place in {$label}."];
        }

        DB::transaction(function () use ($student, $class, $row) {
            if ($student->status !== 'ACTIVE' || $student->departure !== null) {
                $student->update(['status' => 'ACTIVE', 'departure' => null]);
            }
            $this->linkGuardian($student, $row);
            $this->enrolment->place($student->getKey(), $class->getKey(), $this->closeOtherSessions);
        });

        return ['status' => 'placed', 'line' => "{$student->full_name}: placed in {$label}."];
    }

    /** @param  array<string, mixed>  $row */
    private function linkGuardian(Student $student, array $row): void
    {
        $name = trim((string) ($row['guardian'] ?? ''));
        if ($name === '') {
            return;
        }

        $phone = $this->phone($row['phone'] ?? '');
        $guardian = Guardian::query()
            ->where('full_name', $name)
            ->where('phone', $phone)
            ->first();

        if (! $guardian) {
            $guardian = Guardian::create([
                'full_name' => mb_substr($name, 0, 255),
                'phone' => $phone,
                'email' => '',
                'address' => mb_substr(trim((string) ($row['address'] ?? '')), 0, 255),
            ]);
        }

        $linked = StudentGuardian::query()
            ->where('student_id', $student->getKey())
            ->where('guardian_id', $guardian->getKey())
            ->exists();

        if (! $linked) {
            StudentGuardian::create([
                'student_id' => $student->getKey(),
                'guardian_id' => $guardian->getKey(),
                'relationship' => 'parent',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $register
     * @param  array<string, SchoolClass>  $classes
     * @param  array<string, string>  $handled  full name => class id already put in that class
     * @param  array{created: int, placed: int, skipped: int, failed: int, in_class: int, moved: int, enrolled: int, missing: int, lines: list<string>}  $stats
     */
    private function routeRegister(array $register, AcademicSession $session, array $classes, bool $dryRun, array $handled, array &$stats): void
    {
        $pupils = Student::query()->get();
        $byIndex = [];
        $byName = [];
        foreach ($pupils as $pupil) {
            $byIndex[(string) $pupil->index_number] = $pupil;
            $byName[$this->normalName($pupil->full_name)][] = $pupil;
        }

        $enrolments = Enrolment::query()
            ->where('session_id', $session->getKey())
            ->with('class.level', 'class.arm')
            ->get()
            ->groupBy('student_id');
        $routed = [];

        foreach ($register as $row) {
            $name = trim((string) ($row['full_name'] ?? ''));
            $level = trim((string) ($row['level'] ?? ''));
            $arm = trim((string) ($row['arm'] ?? ''));
            $index = trim((string) ($row['index'] ?? ''));
            $label = trim($level.' '.$arm);

            if ($name === '' || $level === '' || $index === '') {
                $stats['failed']++;
                $stats['lines'][] = 'Skipped a register row with no name, number, or class.';

                continue;
            }

            $class = $classes[$this->key($level, $arm)] ?? null;
            if ($class === null) {
                $stats['failed']++;
                $stats['lines'][] = "{$name}: no current class {$label}.";

                continue;
            }

            $student = $this->findPupil($byIndex, $byName, $index, $name);
            if ($student !== null && isset($routed[$student->getKey()])) {
                $stats['lines'][] = "{$student->full_name}: also listed in {$label}; left in {$routed[$student->getKey()]}.";

                continue;
            }
            if ($student === null) {
                if (($handled[$name] ?? null) === $class->getKey()) {
                    $stats['in_class']++;

                    continue;
                }
                $stats['missing']++;
                $stats['lines'][] = "{$name} ({$index}): not in this school.";

                continue;
            }

            $active = ($enrolments[$student->getKey()] ?? collect())
                ->first(fn (Enrolment $enrolment) => $enrolment->status === 'ACTIVE');

            if ($active && $active->class_id === $class->getKey()) {
                $stats['in_class']++;
                $routed[$student->getKey()] = $label;

                continue;
            }

            $from = $active
                ? trim(($active->class?->level?->name ?? '').' '.($active->class?->arm?->name ?? ''))
                : '';

            if ($dryRun) {
                $stats[$active ? 'moved' : 'enrolled']++;
                $stats['lines'][] = $active
                    ? "{$student->full_name}: would move from {$from} to {$label}."
                    : "{$student->full_name}: would enrol in {$label}.";
                $routed[$student->getKey()] = $label;

                continue;
            }

            if ($active) {
                $this->enrolment->move($active->getKey(), $class->getKey());
                $stats['moved']++;
                $stats['lines'][] = "{$student->full_name}: moved from {$from} to {$label}.";
                $routed[$student->getKey()] = $label;

                continue;
            }

            DB::transaction(function () use ($student, $class) {
                if ($student->status !== 'ACTIVE' || $student->departure !== null) {
                    $student->update(['status' => 'ACTIVE', 'departure' => null]);
                }
                $this->enrolment->place($student->getKey(), $class->getKey(), $this->closeOtherSessions);
            });
            $stats['enrolled']++;
            $stats['lines'][] = "{$student->full_name}: enrolled in {$label}.";
            $routed[$student->getKey()] = $label;
        }
    }

    /**
     * @param  array<string, Student>  $byIndex
     * @param  array<string, list<Student>>  $byName
     */
    private function findPupil(array $byIndex, array $byName, string $index, string $name): ?Student
    {
        $holder = $byIndex[$index] ?? null;
        if ($holder && $this->sameRecord($name, $holder->full_name)) {
            return $holder;
        }

        $matches = $byName[$this->normalName($name)] ?? [];
        if (count($matches) === 1) {
            return $matches[0];
        }

        foreach ($matches as $match) {
            if ((string) $match->index_number === $index) {
                return $match;
            }
        }

        return null;
    }

    private function sameRecord(string $registerName, string $storedName): bool
    {
        if ($this->samePerson($registerName, $storedName)) {
            return true;
        }

        $surname = $this->tokens($registerName)[0] ?? '';
        if (strlen($surname) < 3) {
            return false;
        }

        foreach ($this->tokens($storedName) as $token) {
            if ($this->tokenClose($surname, $token)) {
                return true;
            }
        }

        return false;
    }

    private function samePerson(string $left, string $right): bool
    {
        $leftTokens = $this->tokens($left);
        $rightTokens = $this->tokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $shorter = count($leftTokens) <= count($rightTokens) ? $leftTokens : $rightTokens;
        $longer = count($leftTokens) <= count($rightTokens) ? $rightTokens : $leftTokens;
        foreach ($shorter as $token) {
            $close = false;
            foreach ($longer as $other) {
                if ($this->tokenClose($token, $other)) {
                    $close = true;
                    break;
                }
            }
            if (! $close) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function tokens(string $name): array
    {
        $parts = preg_split('/[^a-z0-9]+/', strtolower($name)) ?: [];

        return array_values(array_filter($parts, fn (string $token) => strlen($token) >= 2));
    }

    private function tokenClose(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }
        if (strlen($left) >= 3 && strlen($right) >= 3 && (str_starts_with($left, $right) || str_starts_with($right, $left))) {
            return true;
        }
        if (strlen($left) < 5 || strlen($right) < 5) {
            return false;
        }

        return levenshtein($left, $right) <= 2;
    }

    private function normalName(string $name): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($name))) ?? '';
    }

    private function resolveSession(?string $name): AcademicSession
    {
        if ($name === null || trim($name) === '') {
            return $this->currentSession();
        }

        $sessions = AcademicSession::query()->where('name', trim($name))->get();
        if ($sessions->count() !== 1) {
            throw new DomainException('No session named "'.trim($name).'".');
        }

        return $sessions->first();
    }

    private function currentSession(): AcademicSession
    {
        $sessions = AcademicSession::query()->where('is_current', true)->get();
        if ($sessions->count() !== 1) {
            throw new DomainException('The school needs one current session before these students can be placed.');
        }

        return $sessions->first();
    }

    /**
     * @return array<string, SchoolClass>
     */
    private function classes(AcademicSession $session): array
    {
        $map = [];
        $classes = SchoolClass::query()->where('session_id', $session->getKey())->with(['level', 'arm'])->get();
        foreach ($classes as $class) {
            $key = $this->key($class->level?->name ?? '', $class->arm?->name ?? '');
            $map[$key] = $class;
        }

        return $map;
    }

    /**
     * @param  array<int|string, int|string>  $counts
     * @return array<string, int>
     */
    private function counts(array $counts): array
    {
        $out = [];
        foreach ($counts as $index => $count) {
            $index = trim((string) $index);
            if ($index === '') {
                continue;
            }
            $out[$index] = ($out[$index] ?? 0) + (int) $count;
        }

        return $out;
    }

    /** @param  array<string, int>  $workbook
     * @param  array<string, bool>  $taken
     */
    private function nextIndex(array $workbook, array $taken): string
    {
        $highest = 0;
        foreach (array_keys($workbook) as $index) {
            if (preg_match('/^\d+$/', (string) $index)) {
                $highest = max($highest, (int) $index);
            }
        }

        $n = $highest + 1;
        while (isset($workbook[(string) $n]) || isset($taken[(string) $n])) {
            $n++;
        }

        return (string) $n;
    }

    private function key(string $level, string $arm): string
    {
        return strtolower(trim($level)).'|'.strtolower(trim($arm));
    }

    private function gender(mixed $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'm', 'male' => 'Male',
            'f', 'female' => 'Female',
            default => '',
        };
    }

    private function phone(mixed $value): string
    {
        return mb_substr(trim(trim((string) $value), '/'), 0, 50);
    }

    private function birthDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $date->gt(Carbon::today()) ? null : $date->toDateString();
    }
}
