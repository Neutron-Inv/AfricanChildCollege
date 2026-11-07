<?php

namespace App\Console\Commands;

use App\Models\Arm;
use App\Models\Level;
use App\Models\School;
use App\Models\SchoolClass;
use App\Services\Legacy\LegacyMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairPrimaryClassNamesCommand extends Command
{
    protected $signature = 'classes:repair-primary-names
        {school=primary : Slug of the primary school}
        {--dry-run : Show what would change without saving}';

    protected $description = 'Move a class name that was stored as the arm into the level. Pass the school slug, for example bwari.';

    public function handle(): int
    {
        $school = School::query()->where('slug', $this->argument('school'))->first();
        if (! $school) {
            $this->error('No school with slug "'.$this->argument('school').'".');

            return self::FAILURE;
        }

        $classes = SchoolClass::withoutGlobalScopes()
            ->where('school_id', $school->getKey())
            ->with(['level', 'arm'])
            ->get();

        $plans = [];
        $failures = [];

        foreach ($classes as $class) {
            $label = $this->label($class);
            $parsed = LegacyMapper::className($label);

            if ($parsed['level'] === 'Other') {
                if (($class->level?->name ?? '') === 'Other') {
                    $failures[] = $label !== '' ? $label : '(blank class name)';
                }

                continue;
            }

            $plans[] = ['class' => $class, 'level' => $parsed['level'], 'arm' => $parsed['arm'], 'label' => $label];
        }

        if ($failures !== []) {
            $this->error('These class names could not be read, so nothing was changed:');
            foreach ($failures as $name) {
                $this->line('  '.$name);
            }

            return self::FAILURE;
        }

        if ($plans === []) {
            $this->info('No classes to repair.');

            return self::SUCCESS;
        }

        $summary = [];
        foreach ($plans as $plan) {
            $key = $plan['level'].'|'.$plan['arm'];
            $summary[$key]['level'] = $plan['level'];
            $summary[$key]['arm'] = $plan['arm'];
            $summary[$key]['count'] = ($summary[$key]['count'] ?? 0) + 1;
        }

        $changed = 0;
        foreach ($plans as $plan) {
            $class = $plan['class'];
            if ($class->level?->name !== $plan['level'] || ($class->arm?->name ?? '') !== $plan['arm']) {
                $changed++;
            }
        }

        $this->info($school->name.': '.$changed.' of '.count($plans).' classes need a new level and arm.');
        foreach ($summary as $row) {
            $arm = $row['arm'] !== '' ? $row['arm'] : '(no arm)';
            $this->line('  '.$row['level'].' · '.$arm.' · '.$row['count'].' '.($row['count'] === 1 ? 'class' : 'classes'));
        }

        if ($changed === 0) {
            $this->info('Already repaired.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run. Nothing was saved.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($school, $plans) {
            $levelIds = [];
            $armIds = [];

            $order = array_flip(LegacyMapper::LEVEL_ORDER);
            foreach (array_unique(array_column($plans, 'level')) as $name) {
                $sort = ($order[$name] ?? 100) + 1;
                $level = Level::withoutGlobalScopes()->firstOrCreate(
                    ['school_id' => $school->getKey(), 'name' => $name],
                    ['sort_order' => $sort],
                );
                $level->update(['sort_order' => $sort]);
                $levelIds[$name] = $level->getKey();
            }

            foreach ($plans as $plan) {
                $armName = $plan['arm'];
                if (! isset($armIds[$armName])) {
                    $armIds[$armName] = Arm::withoutGlobalScopes()->firstOrCreate(
                        ['school_id' => $school->getKey(), 'name' => $armName],
                    )->getKey();
                }

                $plan['class']->update([
                    'level_id' => $levelIds[$plan['level']],
                    'arm_id' => $armIds[$armName],
                ]);
            }

            $usedLevels = SchoolClass::withoutGlobalScopes()->where('school_id', $school->getKey())->pluck('level_id');
            $usedArms = SchoolClass::withoutGlobalScopes()->where('school_id', $school->getKey())->pluck('arm_id');

            Level::withoutGlobalScopes()
                ->where('school_id', $school->getKey())
                ->whereNotIn('id', $usedLevels)
                ->delete();
            Arm::withoutGlobalScopes()
                ->where('school_id', $school->getKey())
                ->whereNotIn('id', $usedArms)
                ->delete();
        });

        $this->info('Saved. Class lists now show the level, then the arm.');

        return self::SUCCESS;
    }

    private function label(SchoolClass $class): string
    {
        $level = $class->level?->name ?? '';
        $arm = $class->arm?->name ?? '';

        if ($level === '' || $level === 'Other') {
            return $arm;
        }

        return trim($level.' '.$arm);
    }
}
