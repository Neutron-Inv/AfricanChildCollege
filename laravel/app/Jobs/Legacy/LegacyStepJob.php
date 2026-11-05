<?php

namespace App\Jobs\Legacy;

use App\Models\School;
use App\Services\Legacy\LegacyImporter;
use App\Services\Legacy\LegacyImportException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base for the legacy import steps. Each step is idempotent (legacy_links), so a failed
 * chunk can simply be retried. Failures log a short, secret-free message.
 */
abstract class LegacyStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $schoolId) {}

    /** @return array<string, mixed> counts for the log */
    abstract protected function import(LegacyImporter $importer): array;

    /** Human label used in logs. */
    protected function label(): string
    {
        return class_basename(static::class);
    }

    public function handle(): void
    {
        $school = School::query()->find($this->schoolId)
            ?? throw new LegacyImportException('School '.$this->schoolId.' no longer exists.');

        $importer = new LegacyImporter($school);
        $importer->assertReady();

        $result = $this->import($importer);

        Log::info('legacy-import: '.$this->label().' finished', ['school' => $school->slug] + $result);
    }

    public function failed(Throwable $e): void
    {
        // Only our own messages are safe to print; anything else (SQL, bindings) stays in failed_jobs.
        $message = $e instanceof LegacyImportException ? $e->getMessage() : 'unexpected '.class_basename($e).' - see failed_jobs / laravel.log';

        Log::error('legacy-import: '.$this->label().' failed - '.$message, ['school_id' => $this->schoolId]);
    }
}
