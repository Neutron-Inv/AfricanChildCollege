<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\PrimaryNewStudentImporter;
use DomainException;
use Illuminate\Console\Command;

class ImportPrimaryNewStudentsCommand extends Command
{
    protected $signature = 'students:import-primary-new
        {school=primary : Slug of the primary school}
        {--dry-run : Show what would be added without saving}';

    protected $description = 'Add the new (green) nursery and primary pupils from the 2026/2027 register into the current session.';

    public function handle(PrimaryNewStudentImporter $importer): int
    {
        $school = School::query()->where('slug', $this->argument('school'))->first();
        if (! $school) {
            $this->error('No school with slug "'.$this->argument('school').'".');

            return self::FAILURE;
        }

        $path = database_path('data/primary_new_students_2026.json');
        if (! is_file($path)) {
            $this->error('Roster file is missing: '.$path);

            return self::FAILURE;
        }

        $roster = json_decode((string) file_get_contents($path), true);
        if (! is_array($roster) || ! isset($roster['students'], $roster['workbook_indexes'], $roster['register'])) {
            $this->error('Roster file could not be read.');

            return self::FAILURE;
        }

        try {
            $stats = $importer->import($school, $roster, (bool) $this->option('dry-run'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($stats['lines'] as $line) {
            $this->line($line);
        }

        $this->info(sprintf(
            '%d created, %d placed, %d already on file, %d failed%s.',
            $stats['created'],
            $stats['placed'],
            $stats['skipped'],
            $stats['failed'],
            $this->option('dry-run') ? ' (dry run)' : '',
        ));
        $this->info(sprintf(
            'Classes: %d already correct, %d moved, %d enrolled, %d not in this school.',
            $stats['in_class'],
            $stats['moved'],
            $stats['enrolled'],
            $stats['missing'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
