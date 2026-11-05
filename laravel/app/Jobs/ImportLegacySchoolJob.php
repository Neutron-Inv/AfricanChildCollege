<?php

namespace App\Jobs;

use App\Jobs\Legacy\ImportLegacyClassesSubjectsJob;
use App\Jobs\Legacy\ImportLegacyEnrolmentsJob;
use App\Jobs\Legacy\ImportLegacyGuardiansJob;
use App\Jobs\Legacy\ImportLegacyScoresJob;
use App\Jobs\Legacy\ImportLegacyStaffJob;
use App\Jobs\Legacy\ImportLegacyStudentsJob;
use App\Models\School;
use App\Services\Legacy\LegacyImporter;
use App\Services\Legacy\LegacyImportException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates the legacy import of one school: checks the legacy database, then dispatches an
 * ordered chain of (chunked) step jobs:
 *
 *   staff -> students[chunks] -> guardians[chunks] -> classes/subjects -> enrolments[chunks] -> scores[chunks]
 *
 * If the legacy database is unavailable the job fails with a clear message and dispatches nothing.
 */
class ImportLegacySchoolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  bool  $runSync  run the whole chain on the sync connection (no queue worker needed)
     * @param  int  $peopleChunk  legacy ids per students / guardians / enrolments job
     * @param  int  $scoreChunk  legacy student_exam ids per scores job
     */
    public function __construct(
        public string $schoolId,
        public bool $runSync = false,
        public int $peopleChunk = 100,
        public int $scoreChunk = 2000,
    ) {}

    public function handle(): void
    {
        $school = School::query()->find($this->schoolId)
            ?? throw new LegacyImportException('School '.$this->schoolId.' no longer exists.');

        $importer = new LegacyImporter($school);
        $importer->assertReady(); // throws LegacyImportException with a clear message

        $id = $school->getKey();
        $jobs = [new ImportLegacyStaffJob($id)];

        $studentRanges = $importer->idRanges('student', $this->peopleChunk);
        foreach ($studentRanges as [$from, $to]) {
            $jobs[] = new ImportLegacyStudentsJob($id, $from, $to);
        }
        foreach ($importer->idRanges('parents', $this->peopleChunk) as [$from, $to]) {
            $jobs[] = new ImportLegacyGuardiansJob($id, $from, $to);
        }

        $jobs[] = new ImportLegacyClassesSubjectsJob($id);

        foreach ($studentRanges as [$from, $to]) {
            $jobs[] = new ImportLegacyEnrolmentsJob($id, $from, $to);
        }
        foreach ($importer->idRanges('student_exam', $this->scoreChunk) as [$from, $to]) {
            $jobs[] = new ImportLegacyScoresJob($id, $from, $to);
        }

        $chain = Bus::chain($jobs);
        if ($this->runSync) {
            $chain->onConnection('sync');
        }
        $chain->dispatch();

        Log::info('legacy-import: '.$school->slug.' queued '.count($jobs).' jobs');
    }

    public function failed(Throwable $e): void
    {
        $message = $e instanceof LegacyImportException ? $e->getMessage() : 'unexpected '.class_basename($e).' - see failed_jobs / laravel.log';

        Log::error('legacy-import: '.$this->schoolId.' failed - '.$message);
    }
}
