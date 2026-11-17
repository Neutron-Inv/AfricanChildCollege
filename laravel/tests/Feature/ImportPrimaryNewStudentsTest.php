<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\Enrolment;
use App\Models\Guardian;
use App\Models\Level;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportPrimaryNewStudentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_green_register_rows_are_placed_and_clashing_indexes_are_replaced(): void
    {
        Carbon::setTestNow('2026-11-16');

        $school = School::create(['name' => 'Primary', 'slug' => 'primary', 'subdomain' => 'primary']);

        Tenant::run($school, function () {
            $current = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $previous = AcademicSession::create(['name' => '2025/2026', 'start_year' => 2025, 'end_year' => 2026, 'is_current' => false]);
            $arms = [];
            foreach (['', 'Joy', 'Peace', 'Success'] as $name) {
                $arms[$name] = Arm::create(['name' => $name]);
            }
            $levels = [
                'Reception' => '',
                'Pre-Nursery' => 'Joy',
                'Nursery 1' => ['Joy', 'Peace'],
                'Nursery 2' => ['Joy', 'Peace'],
                'Primary 1' => ['Joy', 'Peace'],
                'Primary 2' => ['Joy', 'Peace'],
                'Primary 3' => ['Joy', 'Peace'],
                'Primary 4' => ['Joy', 'Peace', 'Success'],
                'Primary 5' => ['Joy', 'Peace'],
            ];
            $order = 1;
            foreach ($levels as $levelName => $armNames) {
                $level = Level::create(['name' => $levelName, 'sort_order' => $order++]);
                foreach ((array) $armNames as $armName) {
                    SchoolClass::create(['session_id' => $current->id, 'level_id' => $level->id, 'arm_id' => $arms[$armName]->id]);
                    SchoolClass::create(['session_id' => $previous->id, 'level_id' => $level->id, 'arm_id' => $arms[$armName]->id]);
                }
            }

            Student::create(['index_number' => '2217', 'full_name' => 'Already There', 'status' => 'ACTIVE']);

            $primary1Joy = SchoolClass::query()
                ->where('session_id', $current->id)
                ->whereHas('level', fn ($query) => $query->where('name', 'Primary 1'))
                ->whereHas('arm', fn ($query) => $query->where('name', 'Joy'))
                ->firstOrFail();
            $butter = Student::create(['index_number' => '2212', 'full_name' => 'Butter Daniel', 'status' => 'ACTIVE']);
            Enrolment::create([
                'student_id' => $butter->id,
                'class_id' => $primary1Joy->id,
                'session_id' => $current->id,
                'status' => 'ACTIVE',
            ]);
            Student::create([
                'index_number' => '2164',
                'full_name' => 'Adeosun Bernice Eniobanke',
                'status' => 'LEFT',
                'departure' => 'LEFT',
            ]);
        });

        $this->artisan('students:import-primary-new', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(3, Student::withoutGlobalScopes()->where('school_id', $school->id)->count());
        $this->assertSame('Primary 1 Joy', $this->className(Student::withoutGlobalScopes()->where('full_name', 'Butter Daniel')->firstOrFail()));

        $this->artisan('students:import-primary-new')->assertSuccessful();

        $this->assertSame(73, Student::withoutGlobalScopes()->where('school_id', $school->id)->count());
        $this->assertSame('Already There', Student::withoutGlobalScopes()->where('index_number', '2217')->value('full_name'));

        $braimoh = Student::withoutGlobalScopes()->where('full_name', 'Braimoh Zion Ogo-Ose')->firstOrFail();
        $this->assertSame('2286', $braimoh->index_number);
        $this->assertSame('Reception', $this->className($braimoh));

        $nkanu = Student::withoutGlobalScopes()->where('full_name', 'Nkanu Ibiang Efayeobase')->firstOrFail();
        $this->assertSame('2283', $nkanu->index_number);

        $titus = Student::withoutGlobalScopes()->where('full_name', 'Titus Miracle Chioma')->firstOrFail();
        $this->assertSame('2287', $titus->index_number);
        $this->assertSame('Primary 2 Joy', $this->className($titus));

        $aaron = Student::withoutGlobalScopes()->where('full_name', 'Aaron Esther Chukwudallu')->firstOrFail();
        $this->assertSame('2249', $aaron->index_number);
        $this->assertSame('Pre-Nursery Joy', $this->className($aaron));
        $this->assertSame('Male', $aaron->gender);

        $kingsley = Student::withoutGlobalScopes()->where('full_name', 'Kingsley Imade Moses Ilerioluwa')->firstOrFail();
        $this->assertNull($kingsley->birth_date);

        $praise = Student::withoutGlobalScopes()->where('full_name', 'Nwobodo Praise')->firstOrFail();
        $this->assertSame('2026-10-25', $praise->birth_date->toDateString());

        $this->assertSame(1, Guardian::withoutGlobalScopes()->where('full_name', 'EGWU Philip')->count());
        $this->assertSame(2, Guardian::withoutGlobalScopes()->where('full_name', 'EGWU Philip')->firstOrFail()->students()->count());

        $this->artisan('students:import-primary-new')->assertSuccessful();
        $this->assertSame(73, Student::withoutGlobalScopes()->where('school_id', $school->id)->count());
        $this->assertSame('Primary 2 Joy', $this->className(Student::withoutGlobalScopes()->where('full_name', 'Butter Daniel')->firstOrFail()));
        $adeosun = Student::withoutGlobalScopes()->where('full_name', 'Adeosun Bernice Eniobanke')->firstOrFail();
        $this->assertSame('ACTIVE', $adeosun->status);
        $this->assertSame('Pre-Nursery Joy', $this->className($adeosun));

        $this->assertSame(72, Enrolment::withoutGlobalScopes()->where('school_id', $school->id)->where('status', 'ACTIVE')->count());
    }

    private function className(Student $student): string
    {
        $enrolment = $student->enrolments()->where('status', 'ACTIVE')->with('class.level', 'class.arm')->firstOrFail();

        return trim(($enrolment->class->level->name ?? '').' '.($enrolment->class->arm->name ?? ''));
    }
}
