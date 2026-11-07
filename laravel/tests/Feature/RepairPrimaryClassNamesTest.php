<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\Level;
use App\Models\School;
use App\Models\SchoolClass;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairPrimaryClassNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_class_names_are_split_into_levels_and_arms(): void
    {
        $primary = School::create(['name' => 'Primary', 'slug' => 'primary', 'subdomain' => 'primary']);
        $secondary = School::create(['name' => 'Secondary', 'slug' => 'secondary', 'subdomain' => 'secondary']);

        $reception = Tenant::run($primary, fn () => $this->misnamed($primary, 'Reception'));
        $nursery = Tenant::run($primary, fn () => $this->misnamed($primary, 'Nursery 1 Joy'));
        $success = Tenant::run($primary, fn () => $this->misnamed($primary, 'Primary 4 Success'));
        $secondaryClass = Tenant::run($secondary, function () use ($secondary) {
            $session = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $level = Level::create(['name' => 'JS1', 'sort_order' => 1]);
            $arm = Arm::create(['name' => 'Diamond']);

            return SchoolClass::create(['session_id' => $session->id, 'level_id' => $level->id, 'arm_id' => $arm->id]);
        });

        $this->artisan('classes:repair-primary-names', ['school' => 'primary'])->assertSuccessful();

        $reception = $reception->fresh()->load(['level', 'arm']);
        $this->assertSame('Reception', $reception->level->name);
        $this->assertSame('', $reception->arm->name);

        $nursery = $nursery->fresh()->load(['level', 'arm']);
        $this->assertSame('Nursery 1', $nursery->level->name);
        $this->assertSame('Joy', $nursery->arm->name);

        $success = $success->fresh()->load(['level', 'arm']);
        $this->assertSame('Primary 4', $success->level->name);
        $this->assertSame('Success', $success->arm->name);

        $this->assertSame(
            ['Reception', 'Nursery 1', 'Primary 4'],
            Level::withoutGlobalScopes()->where('school_id', $primary->id)->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame(3, $reception->level->sort_order);
        $this->assertSame(5, $nursery->level->sort_order);
        $this->assertSame(10, $success->level->sort_order);
        $this->assertSame(0, Level::withoutGlobalScopes()->where('school_id', $primary->id)->where('name', 'Other')->count());
        $this->assertSame(0, Arm::withoutGlobalScopes()->where('school_id', $primary->id)->where('name', 'Nursery 1 Joy')->count());

        $secondaryClass = $secondaryClass->fresh()->load(['level', 'arm']);
        $this->assertSame('JS1', $secondaryClass->level->name);
        $this->assertSame('Diamond', $secondaryClass->arm->name);

        $this->artisan('classes:repair-primary-names', ['school' => 'primary'])->assertSuccessful();
        $this->assertSame(1, SchoolClass::withoutGlobalScopes()->where('school_id', $primary->id)->where('level_id', $nursery->level_id)->count());
    }

    public function test_bwari_level_names_stored_as_arms_are_moved_to_levels(): void
    {
        $bwari = School::create(['name' => 'Bwari', 'slug' => 'bwari', 'subdomain' => 'bwari']);
        $jss = Tenant::run($bwari, fn () => $this->misnamed($bwari, 'JSS 1'));
        $creche = Tenant::run($bwari, fn () => $this->misnamed($bwari, 'Creche'));

        $this->artisan('classes:repair-primary-names', ['school' => 'bwari'])->assertSuccessful();

        $jss = $jss->fresh()->load(['level', 'arm']);
        $this->assertSame('JSS 1', $jss->level->name);
        $this->assertSame('', $jss->arm->name);

        $creche = $creche->fresh()->load(['level', 'arm']);
        $this->assertSame('Creche', $creche->level->name);
        $this->assertSame('', $creche->arm->name);
        $this->assertTrue($creche->level->sort_order < $jss->level->sort_order);
    }

    private function misnamed(School $school, string $armName): SchoolClass
    {
        $session = AcademicSession::create(['name' => '2026/2027 '.$armName, 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
        $level = Level::query()->firstOrCreate(['school_id' => $school->id, 'name' => 'Other'], ['sort_order' => 1]);
        $arm = Arm::create(['name' => $armName]);

        return SchoolClass::create([
            'session_id' => $session->id,
            'level_id' => $level->id,
            'arm_id' => $arm->id,
        ]);
    }
}
