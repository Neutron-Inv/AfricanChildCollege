<?php

namespace App\Console\Commands;

use App\Jobs\ImportLegacySchoolJob;
use App\Models\School;
use App\Services\Legacy\LegacyImporter;
use App\Services\Legacy\LegacyImportException;
use Illuminate\Console\Command;

class LegacyImportCommand extends Command
{
    protected $signature = 'legacy:import
        {schoolSlug : Slug of the target school, e.g. "secondary"}
        {--sync : Run the whole import now in this process instead of on the queue (no worker needed)}
        {--skip-check : Dispatch even if the legacy database cannot be reached right now}
        {--people-chunk=100 : Legacy ids per students / guardians / enrolments job}
        {--score-chunk=2000 : Legacy student_exam ids per scores job}';

    protected $description = 'Import the legacy school database (LEGACY_DB_* connection) into a school. Idempotent.';

    public function handle(): int
    {
        $school = School::query()->where('slug', $this->argument('schoolSlug'))->first();

        if (! $school) {
            $this->error('No school with slug "'.$this->argument('schoolSlug').'". Seed the school first (php artisan db:seed).');

            return self::FAILURE;
        }

        if (! $this->option('skip-check')) {
            try {
                (new LegacyImporter($school))->assertReady();
            } catch (LegacyImportException $e) {
                $this->error($e->getMessage());
                $this->line('Nothing was dispatched. Use --skip-check to queue the job anyway.');

                return self::FAILURE;
            }
        }

        $args = [
            $school->getKey(),
            (bool) $this->option('sync'),
            max(1, (int) $this->option('people-chunk')),
            max(1, (int) $this->option('score-chunk')),
        ];

        if ($this->option('sync')) {
            $this->info('Importing legacy data into "'.$school->slug.'" now (this can take a few minutes)...');

            try {
                ImportLegacySchoolJob::dispatchSync(...$args);
            } catch (LegacyImportException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info('Legacy import finished. Per-step counts are in storage/logs/laravel.log.');

            return self::SUCCESS;
        }

        ImportLegacySchoolJob::dispatch(...$args);

        $this->info('Queued ImportLegacySchoolJob for "'.$school->slug.'". Run a worker to process it: php artisan queue:work');

        return self::SUCCESS;
    }
}
