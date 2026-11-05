<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\SetsUpSchoolLife;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase, SetsUpSchoolLife;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchoolLife();
    }

    public function test_student_dashboard_shows_their_class_instead_of_school_totals(): void
    {
        \App\Support\Tenant::run($this->school, fn () => $this->class->update(['form_teacher_id' => $this->ada->id]));

        $this->as(Role::STUDENT, ['student_id' => $this->student->id])
            ->get(self::HOST.'/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Index number', 'S1', 'Current class', 'JSS1 A', 'Ada Obi', 'Current session', '2026/2027', 'Students in class', '1'])
            ->assertDontSee('Staff');

        $this->as(Role::TEACHER, ['staff_id' => $this->ada->id])
            ->get(self::HOST.'/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Form class', 'JSS1 A', 'Students in class', '1', 'Subjects', '1', 'Current session', '2026/2027'])
            ->assertSee('Maths')
            ->assertSee('JSS1 B')
            ->assertDontSee('English')
            ->assertDontSee('Staff');

        $this->as(Role::ADMIN)
            ->get(self::HOST.'/dashboard')
            ->assertOk()
            ->assertSee('Staff')
            ->assertSee('Classes')
            ->assertDontSee('Form teacher');
    }
}
