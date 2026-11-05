<?php

namespace App\Jobs\Legacy;

use App\Services\Legacy\LegacyImporter;

/** student_exam -> subject_scores + term_reports (days, comments), for legacy ids in [$from, $to]. */
class ImportLegacyScoresJob extends LegacyStepJob
{
    public function __construct(string $schoolId, public int $from, public int $to)
    {
        parent::__construct($schoolId);
    }

    protected function label(): string
    {
        return 'ImportLegacyScoresJob ['.$this->from.'-'.$this->to.']';
    }

    protected function import(LegacyImporter $importer): array
    {
        return $importer->importScores($this->from, $this->to);
    }
}
