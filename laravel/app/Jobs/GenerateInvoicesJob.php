<?php

namespace App\Jobs;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Term;
use App\Services\FeesService;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bulk invoice generation for a term (whole school, or a single class).
 *
 * Jobs run outside a request, so the tenant is restored from the school id. The work is idempotent:
 * students who already have an invoice for the term are skipped, so a retry cannot create duplicates.
 */
class GenerateInvoicesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $termId,
        public readonly ?string $classId = null,
        public readonly string $status = 'ISSUED',
    ) {}

    public function handle(FeesService $fees): void
    {
        $school = School::query()->find($this->schoolId);
        if (! $school) {
            return;
        }

        Tenant::run($school, function () use ($fees) {
            $term = Term::query()->find($this->termId);
            $class = $this->classId ? SchoolClass::query()->find($this->classId) : null;

            if (! $term || ($this->classId && ! $class)) {
                return;
            }

            $fees->generateInvoices($term, $class, $this->status);
        });
    }
}
