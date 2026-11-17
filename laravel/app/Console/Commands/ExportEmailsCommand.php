<?php

namespace App\Console\Commands;

use App\Models\AcademicSession;
use App\Models\Enrolment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Support\Tenant;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ExportEmailsCommand extends Command
{
    protected $signature = 'people:export-emails
        {school : School slug, for example primary or secondary}
        {who : students or teachers}
        {--session= : Session name. Defaults to the current session}';

    protected $description = 'Write a CSV of student or teacher emails, grouped by class and arm.';

    public function handle(): int
    {
        $who = strtolower((string) $this->argument('who'));
        if (! in_array($who, ['students', 'teachers'], true)) {
            $this->error('Choose students or teachers.');

            return self::FAILURE;
        }

        $school = School::query()->where('slug', $this->argument('school'))->first();
        if (! $school) {
            $this->error('No school with slug "'.$this->argument('school').'".');

            return self::FAILURE;
        }

        try {
            $rows = Tenant::run($school, function () use ($who) {
                $session = $this->session();

                return $who === 'students'
                    ? $this->students($session)
                    : $this->teachers($session);
            });
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = storage_path('app/emails-'.$school->slug.'-'.$who.'.csv');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['Class', 'Arm', 'Index', 'Name', 'Email']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        $this->info(count($rows).' '.$who.' written to '.$path);

        return self::SUCCESS;
    }

    private function session(): AcademicSession
    {
        $name = trim((string) $this->option('session'));
        $sessions = $name === ''
            ? AcademicSession::query()->where('is_current', true)->get()
            : AcademicSession::query()->where('name', $name)->get();

        if ($sessions->count() !== 1) {
            throw new DomainException($name === ''
                ? 'The school needs one current session.'
                : 'No session named "'.$name.'".');
        }

        return $sessions->first();
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    private function students(AcademicSession $session): array
    {
        $enrolments = Enrolment::query()
            ->where('session_id', $session->getKey())
            ->where('status', 'ACTIVE')
            ->with(['student', 'class.level', 'class.arm'])
            ->get()
            ->filter(fn (Enrolment $enrolment) => $enrolment->student !== null);

        $rows = [];
        $placed = [];
        foreach ($this->orderedClasses($enrolments->pluck('class')->filter()) as $class) {
            $inClass = $enrolments
                ->filter(fn (Enrolment $enrolment) => $enrolment->class_id === $class->getKey())
                ->sortBy(fn (Enrolment $enrolment) => strtolower($enrolment->student->full_name));
            foreach ($inClass as $enrolment) {
                $rows[] = $this->row($class->level?->name ?? '', $class->arm?->name ?? '', $enrolment->student);
                $placed[$enrolment->student_id] = true;
            }
        }

        $unplaced = Student::query()
            ->orderBy('full_name')
            ->get()
            ->reject(fn (Student $student) => isset($placed[$student->getKey()]));
        foreach ($unplaced as $student) {
            $rows[] = $this->row('Unplaced', '', $student);
        }

        return $rows;
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    private function teachers(AcademicSession $session): array
    {
        $classes = SchoolClass::query()
            ->where('session_id', $session->getKey())
            ->with(['level', 'arm', 'formTeacher', 'classSubjects.teacher'])
            ->get();

        $rows = [];
        $seen = [];
        foreach ($this->orderedClasses($classes) as $class) {
            $teachers = collect([$class->formTeacher])
                ->merge($class->classSubjects->map(fn ($subject) => $subject->teacher))
                ->filter()
                ->unique(fn (Staff $teacher) => $teacher->getKey())
                ->sortBy(fn (Staff $teacher) => strtolower($teacher->full_name));
            foreach ($teachers as $teacher) {
                $rows[] = $this->row($class->level?->name ?? '', $class->arm?->name ?? '', $teacher);
                $seen[$teacher->getKey()] = true;
            }
        }

        $unassigned = Staff::query()
            ->where('is_teacher', true)
            ->orderBy('last_name')
            ->get()
            ->reject(fn (Staff $teacher) => isset($seen[$teacher->getKey()]));
        foreach ($unassigned as $teacher) {
            $rows[] = $this->row('Unassigned', '', $teacher);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, SchoolClass>  $classes
     * @return Collection<int, SchoolClass>
     */
    private function orderedClasses(Collection $classes): Collection
    {
        return $classes
            ->unique(fn (SchoolClass $class) => $class->getKey())
            ->sortBy([
                fn (SchoolClass $class) => $class->level?->sort_order ?? 999,
                fn (SchoolClass $class) => strtolower($class->arm?->name ?? ''),
                fn (SchoolClass $class) => strtolower($class->level?->name ?? ''),
            ])
            ->values();
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} */
    private function row(string $class, string $arm, Student|Staff $person): array
    {
        return [$class, $arm, (string) $person->index_number, $person->full_name, (string) $person->email];
    }
}
