<?php

namespace App\Jobs\Legacy;

use App\Services\Legacy\LegacyImporter;

/** grade / exam / department / subject / subject_routing -> sessions, terms, classes, subjects, class_subjects. */
class ImportLegacyClassesSubjectsJob extends LegacyStepJob
{
    protected function import(LegacyImporter $importer): array
    {
        $result = $importer->importClassesAndSubjects();

        // Lists can be long; log how many instead.
        $result['unusual_classes'] = count($result['unusual_classes']);
        $result['unparsed_exams'] = count($result['unparsed_exams']);

        return $result;
    }
}
