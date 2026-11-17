<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\Enrolment;
use App\Models\Level;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportSecondaryNewStudentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_secondary_register_fills_2026_and_leaves_the_current_year(): void
    {
        Carbon::setTestNow('2026-11-17');

        $school = School::create(['name' => 'Secondary', 'slug' => 'secondary', 'subdomain' => 'secondary']);

        Tenant::run($school, function () {
            $current = AcademicSession::create(['name' => '2025/2026', 'start_year' => 2025, 'end_year' => 2026, 'is_current' => true]);
            $next = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => false]);
            $arms = [];
            foreach (['Diamond', 'Gold', 'Pearl', 'Emerald'] as $name) {
                $arms[$name] = Arm::create(['name' => $name]);
            }
            $order = 1;
            foreach (['JS1', 'JS2', 'JS3', 'SS1', 'SS2', 'SS3'] as $levelName) {
                $level = Level::create(['name' => $levelName, 'sort_order' => $order++]);
                foreach ($arms as $arm) {
                    SchoolClass::create(['session_id' => $current->id, 'level_id' => $level->id, 'arm_id' => $arm->id]);
                    SchoolClass::create(['session_id' => $next->id, 'level_id' => $level->id, 'arm_id' => $arm->id]);
                }
            }

            $currentGold = SchoolClass::query()
                ->where('session_id', $current->id)
                ->whereHas('level', fn ($query) => $query->where('name', 'JS1'))
                ->whereHas('arm', fn ($query) => $query->where('name', 'Gold'))
                ->firstOrFail();
            $student = Student::create(['index_number' => '3758', 'full_name' => 'Amabebe Fortune David', 'status' => 'ACTIVE']);
            Enrolment::create([
                'student_id' => $student->id,
                'class_id' => $currentGold->id,
                'session_id' => $current->id,
                'status' => 'ACTIVE',
            ]);
            $currentPearl = SchoolClass::query()
                ->where('session_id', $current->id)
                ->whereHas('level', fn ($query) => $query->where('name', 'JS1'))
                ->whereHas('arm', fn ($query) => $query->where('name', 'Pearl'))
                ->firstOrFail();
            $left = Student::create([
                'index_number' => '3849',
                'full_name' => 'Adebayo Kayode Victor',
                'status' => 'LEFT',
                'departure' => 'LEFT',
            ]);
            Enrolment::create([
                'student_id' => $left->id,
                'class_id' => $currentPearl->id,
                'session_id' => $current->id,
                'status' => 'LEFT',
            ]);
        });

        $this->artisan('students:import-secondary-new')->assertSuccessful();

        $amabebe = Student::withoutGlobalScopes()->where('full_name', 'Amabebe Fortune David')->firstOrFail();
        $this->assertSame('JS1 Gold', $this->className($amabebe, '2025/2026'));
        $this->assertSame('JS2 Diamond', $this->className($amabebe, '2026/2027'));

        $abimbola = Student::withoutGlobalScopes()->where('index_number', '4078')->firstOrFail();
        $this->assertSame('Abimbola Muhammed Mahi Onoru Oyiza', $abimbola->full_name);
        $this->assertSame('JS1 Gold', $this->className($abimbola, '2026/2027'));

        $adebayo = Student::withoutGlobalScopes()->where('full_name', 'Adebayo Kayode Victor')->firstOrFail();
        $this->assertSame('ACTIVE', $adebayo->status);
        $this->assertSame('JS1 Pearl', $this->className($adebayo, '2025/2026', 'LEFT'));
        $this->assertSame('JS1 Diamond', $this->className($adebayo, '2026/2027'));

        $emiola = Student::withoutGlobalScopes()->where('full_name', 'Emiola Divine Modupe')->firstOrFail();
        $this->assertSame('SS1 Diamond', $this->className($emiola, '2026/2027'));
        $this->assertSame(219, Student::withoutGlobalScopes()->where('school_id', $school->id)->count());

        $this->artisan('students:import-secondary-new')->assertSuccessful();
        $this->assertSame(219, Student::withoutGlobalScopes()->where('school_id', $school->id)->count());
        $this->assertSame('SS1 Diamond', $this->className($emiola->fresh(), '2026/2027'));
        $this->assertSame('JS1 Gold', $this->className($amabebe->fresh(), '2025/2026'));
    }

    private function className(Student $student, string $session, string $status = 'ACTIVE'): string
    {
        $enrolment = $student->enrolments()
            ->where('status', $status)
            ->whereHas('session', fn ($query) => $query->where('name', $session))
            ->with('class.level', 'class.arm')
            ->firstOrFail();

        return trim(($enrolment->class->level->name ?? '').' '.($enrolment->class->arm->name ?? ''));
    }
}
