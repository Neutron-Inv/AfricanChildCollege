<?php

namespace App\Jobs\Legacy;

use App\Services\Legacy\LegacyImporter;

/** student -> students (+ student logins), for legacy ids in [$from, $to]. */
class ImportLegacyStudentsJob extends LegacyStepJob
{
    public function __construct(string $schoolId, public int $from, public int $to)
    {
        parent::__construct($schoolId);
    }

    protected function label(): string
    {
        return 'ImportLegacyStudentsJob ['.$this->from.'-'.$this->to.']';
    }

    protected function import(LegacyImporter $importer): array
    {
        return $importer->importStudents($this->from, $this->to);
    }
}
