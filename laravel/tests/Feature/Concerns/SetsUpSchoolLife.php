<?php

namespace Tests\Feature\Concerns;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassSubject;
use App\Models\Department;
use App\Models\Enrolment;
use App\Models\Level;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Support\Tenant;

/** Shared fixture: one school, a current session/term, a JSS1 A class with Maths (teacher Ada, HOD Chi) and English (Bola). */
trait SetsUpSchoolLife
{
    private const HOST = 'http://life.localhost';

    private School $school;

    private Term $term;

    private SchoolClass $class;

    private SchoolClass $otherClass;

    private ClassSubject $maths;

    private ClassSubject $english;

    private ClassSubject $otherMaths;

    private Staff $ada;

    private Staff $bola;

    private Staff $hod;

    private Department $department;

    private Student $student;

    private Student $outsider;

    protected function setUpSchoolLife(): void
    {
        $this->withoutVite();
        $this->school = School::create(['name' => 'Life School', 'slug' => 'life', 'subdomain' => 'life']);

        Tenant::run($this->school, function () {
            $session = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $this->term = Term::create(['session_id' => $session->id, 'sequence' => 1, 'name' => 'First Term']);
            $level = Level::create(['name' => 'JSS1']);
            $this->class = SchoolClass::create(['session_id' => $session->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
            $this->otherClass = SchoolClass::create(['session_id' => $session->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);

            $this->ada = $this->makeTeacher('T1', 'Ada');
            $this->bola = $this->makeTeacher('T2', 'Bola');
            $this->hod = $this->makeTeacher('T3', 'Chi');
            $this->department = Department::create(['name' => 'Sciences', 'hod_id' => $this->hod->id]);

            $maths = Subject::create(['name' => 'Maths', 'department_id' => $this->department->id]);
            $english = Subject::create(['name' => 'English']);
            $this->maths = ClassSubject::create(['class_id' => $this->class->id, 'subject_id' => $maths->id, 'teacher_id' => $this->ada->id, 'kind' => 'COMPULSORY']);
            $this->english = ClassSubject::create(['class_id' => $this->class->id, 'subject_id' => $english->id, 'teacher_id' => $this->bola->id, 'kind' => 'COMPULSORY']);
            $this->otherMaths = ClassSubject::create(['class_id' => $this->otherClass->id, 'subject_id' => $maths->id, 'teacher_id' => $this->ada->id, 'kind' => 'COMPULSORY']);

            $this->student = Student::create(['index_number' => 'S1', 'full_name' => 'Kay Ade', 'gender' => 'Male', 'status' => 'ACTIVE']);
            Enrolment::create(['student_id' => $this->student->id, 'class_id' => $this->class->id, 'session_id' => $session->id, 'status' => 'ACTIVE']);
            $this->outsider = Student::create(['index_number' => 'S2', 'full_name' => 'Lee Obi', 'gender' => 'Male', 'status' => 'ACTIVE']);
            Enrolment::create(['student_id' => $this->outsider->id, 'class_id' => $this->otherClass->id, 'session_id' => $session->id, 'status' => 'ACTIVE']);
        });
    }

    private function makeTeacher(string $index, string $first): Staff
    {
        return Staff::create(['index_number' => $index, 'first_name' => $first, 'last_name' => 'Obi', 'is_teacher' => true, 'status' => 'ACTIVE', 'gender' => 'Female']);
    }

    private function as(Role $role, array $attrs = []): static
    {
        $link = array_intersect_key($attrs, array_flip(['staff_id', 'student_id', 'guardian_id']));
        $user = $link !== [] ? User::query()->where($link)->first() : null;

        return $this->actingAs($user ?? User::factory()->create(['school_id' => $this->school->id, 'role' => $role] + $attrs));
    }

    private function asTeacher(Staff $staff): static
    {
        return $this->as(Role::TEACHER, ['staff_id' => $staff->id]);
    }

    private function asStudent(Student $student): static
    {
        return $this->as(Role::STUDENT, ['student_id' => $student->id]);
    }
}
