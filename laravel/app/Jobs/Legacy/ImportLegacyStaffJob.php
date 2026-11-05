<?php

namespace App\Jobs\Legacy;

use App\Services\Legacy\LegacyImporter;

/** teacher / admin / accountant / registrar -> staff + users. */
class ImportLegacyStaffJob extends LegacyStepJob
{
    protected function import(LegacyImporter $importer): array
    {
        return $importer->importStaff();
    }
}
