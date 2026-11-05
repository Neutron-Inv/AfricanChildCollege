<?php

namespace App\Jobs\Legacy;

use App\Services\Legacy\LegacyImporter;

/** student_grade + student_exam votes -> enrolments (+ registered subjects), for legacy ids in [$from, $to]. */
class ImportLegacyEnrolmentsJob extends LegacyStepJob
{
    public function __construct(string $schoolId, public int $from, public int $to)
    {
        parent::__construct($schoolId);
    }

    protected function label(): string
    {
        return 'ImportLegacyEnrolmentsJob ['.$this->from.'-'.$this->to.']';
    }

    protected function import(LegacyImporter $importer): array
    {
        return $importer->importEnrolments($this->from, $this->to);
    }
}
