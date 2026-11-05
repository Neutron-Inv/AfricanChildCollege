<?php

namespace App\Jobs\Legacy;

use App\Services\Legacy\LegacyImporter;

/** parents -> guardians (+ parent logins, linked to the child), for legacy ids in [$from, $to]. */
class ImportLegacyGuardiansJob extends LegacyStepJob
{
    public function __construct(string $schoolId, public int $from, public int $to)
    {
        parent::__construct($schoolId);
    }

    protected function label(): string
    {
        return 'ImportLegacyGuardiansJob ['.$this->from.'-'.$this->to.']';
    }

    protected function import(LegacyImporter $importer): array
    {
        return $importer->importGuardians($this->from, $this->to);
    }
}
