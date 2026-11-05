<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\Attendance;
use App\Models\Enrolment;
use App\Models\Level;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://att.localhost';

    private School $school;

    private AcademicSession $session;

    private SchoolClass $class;

    private SchoolClass $otherClass;

    private Staff $formTeacher;

    private Staff $otherTeacher;

    private Enrolment $kay;

    private Enrolment $zed;

    private Enrolment $left;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->school = School::create(['name' => 'Att School', 'slug' => 'att', 'subdomain' => 'att']);

        Tenant::run($this->school, function () {
            $this->session = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $level = Level::create(['name' => 'JSS1']);
            $this->formTeacher = $this->teacher('T1', 'Ada');
            $this->otherTeacher = $this->teacher('T2', 'Bola');
            $this->class = SchoolClass::create(['session_id' => $this->session->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'A'])->id, 'form_teacher_id' => $this->formTeacher->id]);
            $this->otherClass = SchoolClass::create(['session_id' => $this->session->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'B'])->id, 'form_teacher_id' => $this->otherTeacher->id]);

            $this->kay = $this->enrol($this->class, 'S1', 'Kay Ade');
            $this->zed = $this->enrol($this->class, 'S2', 'Zed Bello');
            $this->left = $this->enrol($this->class, 'S3', 'Lee Gone', 'WITHDRAWN');
        });
    }

    private function teacher(string $index, string $first): Staff
    {
        return Staff::create(['index_number' => $index, 'first_name' => $first, 'last_name' => 'Obi', 'is_teacher' => true, 'status' => 'ACTIVE', 'gender' => 'Female']);
    }

    private function enrol(SchoolClass $class, string $index, string $name, string $status = 'ACTIVE'): Enrolment
    {
        $student = Student::create(['index_number' => $index, 'full_name' => $name, 'gender' => 'Male', 'status' => 'ACTIVE']);

        return Enrolment::create(['student_id' => $student->id, 'class_id' => $class->id, 'session_id' => $this->session->id, 'status' => $status]);
    }

    private function as(Role $role, array $attrs = []): static
    {
        return $this->actingAs(User::factory()->create(['school_id' => $this->school->id, 'role' => $role] + $attrs));
    }

    private function rows()
    {
        return Tenant::run($this->school, fn () => Attendance::query()->get());
    }

    private function save(array $marks, ?string $date = '2026-10-28', ?string $classId = null)
    {
        return $this->post(self::HOST.'/attendance', [
            'class_id' => $classId ?? $this->class->id,
            'date' => $date,
            'marks' => $marks,
        ]);
    }

    public function test_register_lists_only_active_enrolments_and_defaults_to_today(): void
    {
        $this->as(Role::ADMIN)->get(self::HOST.'/attendance?class='.$this->class->id)
            ->assertOk()
            ->assertSee('Kay Ade')
            ->assertSee('Zed Bello')
            ->assertDontSee('Lee Gone')
            ->assertSee('value="'.now()->toDateString().'"', false)
            ->assertSee('Save register');
    }

    public function test_office_can_save_and_resaving_upserts_without_duplicates(): void
    {
        $this->as(Role::REGISTRAR);

        $this->save([$this->kay->id => 'PRESENT', $this->zed->id => 'ABSENT'])
            ->assertRedirect(self::HOST.'/attendance?class='.$this->class->id.'&date=2026-10-28')
            ->assertSessionHas('success');

        $this->assertCount(2, $this->rows());

        // Re-save the same day with changes.
        $this->save([$this->kay->id => 'LATE', $this->zed->id => 'EXCUSED'])->assertSessionHas('success');

        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $this->assertSame('LATE', $rows->firstWhere('student_id', $this->kay->student_id)->status);
        $this->assertSame('EXCUSED', $rows->firstWhere('student_id', $this->zed->student_id)->status);

        // A different date makes new rows; blank marks are skipped.
        $this->save([$this->kay->id => 'PRESENT', $this->zed->id => ''], '2026-10-29');
        $this->assertCount(3, $this->rows());

        // The saved state is shown back on the register.
        $this->get(self::HOST.'/attendance?class='.$this->class->id.'&date=2026-10-28')
            ->assertOk()
            ->assertSeeInOrder(['Kay Ade', 'value="LATE"', 'checked', 'Zed Bello', 'value="EXCUSED"', 'checked'], false);
    }

    public function test_accountant_counts_as_office_and_admin_can_save(): void
    {
        $this->as(Role::ACCOUNTANT);
        $this->save([$this->kay->id => 'PRESENT'])->assertSessionHas('success');
        $this->as(Role::ADMIN);
        $this->save([$this->zed->id => 'PRESENT'])->assertSessionHas('success');
        $this->assertCount(2, $this->rows());
    }

    public function test_only_the_form_teacher_of_the_class_can_save(): void
    {
        $this->as(Role::TEACHER, ['staff_id' => $this->formTeacher->id]);
        $this->save([$this->kay->id => 'PRESENT'])->assertSessionHas('success');
        $this->assertCount(1, $this->rows());

        // Not the form teacher of the other class.
        $this->save([], '2026-10-28', $this->otherClass->id)->assertForbidden();
        $this->get(self::HOST.'/attendance?class='.$this->otherClass->id)->assertForbidden();

        // Teacher register only lists their own classes.
        $this->get(self::HOST.'/attendance')->assertOk()->assertSee('JSS1 A')->assertDontSee('JSS1 B');

        // Another teacher cannot save this class.
        $this->as(Role::TEACHER, ['staff_id' => $this->otherTeacher->id]);
        $this->save([$this->zed->id => 'ABSENT'])->assertForbidden();
        $this->assertCount(1, $this->rows());
    }

    public function test_rejects_bad_statuses_and_students_outside_the_class(): void
    {
        $this->as(Role::ADMIN);

        $this->save([$this->kay->id => 'HOLIDAY'])->assertSessionHas('error');
        $this->save([$this->left->id => 'PRESENT'])->assertSessionHas('error'); // not ACTIVE
        $other = Tenant::run($this->school, fn () => $this->enrol($this->otherClass, 'S9', 'Other Kid'));
        $this->save([$other->id => 'PRESENT'])->assertSessionHas('error');       // other class
        $this->save([$this->kay->id => 'PRESENT'], 'not-a-date')->assertSessionHasErrors('date');

        $this->assertCount(0, $this->rows());
    }

    public function test_students_and_parents_get_read_only_history(): void
    {
        $this->as(Role::ADMIN);
        $this->save([$this->kay->id => 'LATE', $this->zed->id => 'ABSENT']);

        $this->as(Role::STUDENT, ['student_id' => $this->kay->student_id])
            ->get(self::HOST.'/attendance')
            ->assertOk()
            ->assertSee('Kay Ade')
            ->assertSee('Late')
            ->assertDontSee('Zed Bello')
            ->assertDontSee('Save register');

        $this->save([$this->kay->id => 'PRESENT'])->assertForbidden();
    }
}
