<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\Level;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PeopleEmailCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_emails_use_the_first_name_and_index(): void
    {
        $school = School::create(['name' => 'Primary', 'slug' => 'primary', 'subdomain' => 'primary']);
        $other = School::create(['name' => 'Secondary', 'slug' => 'secondary', 'subdomain' => 'secondary']);

        Tenant::run($school, function () {
            Student::create(['index_number' => '3689', 'full_name' => 'Alah MaryJane', 'email' => '', 'status' => 'ACTIVE']);
            Student::create(['index_number' => '100', 'full_name' => 'Kept Ada', 'email' => 'kept@africanchildcollege.org', 'status' => 'ACTIVE']);
            Staff::create(['index_number' => 'T9', 'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => '', 'is_teacher' => true, 'status' => 'ACTIVE']);
        });
        Tenant::run($other, fn () => Student::create(['index_number' => '3689', 'full_name' => 'Alah Other', 'email' => '', 'status' => 'ACTIVE']));

        $this->artisan('people:assign-missing-emails', ['school' => 'primary'])->assertSuccessful();

        $student = Student::withoutGlobalScopes()->where('school_id', $school->id)->where('index_number', '3689')->firstOrFail();
        $this->assertSame('alah.3689@africanchildcollege.org', $student->email);
        $login = User::query()->where('student_id', $student->id)->firstOrFail();
        $this->assertSame(Role::STUDENT, $login->role);
        $this->assertTrue(Hash::check('12345', $login->password));

        $teacher = Staff::withoutGlobalScopes()->where('school_id', $school->id)->where('index_number', 'T9')->firstOrFail();
        $this->assertSame('ada.t9@africanchildcollege.org', $teacher->email);
        $this->assertSame(Role::TEACHER, User::query()->where('staff_id', $teacher->id)->firstOrFail()->role);

        $this->assertSame('kept@africanchildcollege.org', Student::withoutGlobalScopes()->where('full_name', 'Kept Ada')->value('email'));
        $this->assertSame('', Student::withoutGlobalScopes()->where('school_id', $other->id)->value('email'));
    }

    public function test_emails_export_groups_students_and_teachers_by_class(): void
    {
        $school = School::create(['name' => 'Primary', 'slug' => 'primary', 'subdomain' => 'primary']);

        Tenant::run($school, function () {
            $session = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $joy = Arm::create(['name' => 'Joy']);
            $peace = Arm::create(['name' => 'Peace']);
            $reception = Level::create(['name' => 'Reception', 'sort_order' => 1]);
            $primary = Level::create(['name' => 'Primary 1', 'sort_order' => 2]);
            $receptionJoy = SchoolClass::create(['session_id' => $session->id, 'level_id' => $reception->id, 'arm_id' => $joy->id]);
            $primaryPeace = SchoolClass::create(['session_id' => $session->id, 'level_id' => $primary->id, 'arm_id' => $peace->id]);

            $zoe = Student::create(['index_number' => '2', 'full_name' => 'Zoe Ade', 'email' => 'zoe.2@africanchildcollege.org', 'status' => 'ACTIVE']);
            $ada = Student::create(['index_number' => '1', 'full_name' => 'Ada Obi', 'email' => 'ada.1@africanchildcollege.org', 'status' => 'ACTIVE']);
            Student::create(['index_number' => '9', 'full_name' => 'No Class', 'email' => 'noclass.9@africanchildcollege.org', 'status' => 'ACTIVE']);
            Enrolment::create(['student_id' => $zoe->id, 'class_id' => $primaryPeace->id, 'session_id' => $session->id, 'status' => 'ACTIVE']);
            Enrolment::create(['student_id' => $ada->id, 'class_id' => $receptionJoy->id, 'session_id' => $session->id, 'status' => 'ACTIVE']);

            $form = Staff::create(['index_number' => 'T1', 'first_name' => 'Form', 'last_name' => 'Teacher', 'email' => 'form.t1@africanchildcollege.org', 'is_teacher' => true, 'status' => 'ACTIVE']);
            $subjectTeacher = Staff::create(['index_number' => 'T2', 'first_name' => 'Subject', 'last_name' => 'Teacher', 'email' => 'subject.t2@africanchildcollege.org', 'is_teacher' => true, 'status' => 'ACTIVE']);
            Staff::create(['index_number' => 'T3', 'first_name' => 'Spare', 'last_name' => 'Teacher', 'email' => 'spare.t3@africanchildcollege.org', 'is_teacher' => true, 'status' => 'ACTIVE']);
            $receptionJoy->update(['form_teacher_id' => $form->id]);
            $maths = Subject::create(['name' => 'Maths']);
            ClassSubject::create(['class_id' => $receptionJoy->id, 'subject_id' => $maths->id, 'teacher_id' => $subjectTeacher->id, 'kind' => 'COMPULSORY']);
        });

        $this->artisan('people:export-emails', ['school' => 'primary', 'who' => 'students'])->assertSuccessful();
        $students = array_map('str_getcsv', file(storage_path('app/emails-primary-students.csv')));
        $this->assertSame(['Class', 'Arm', 'Index', 'Name', 'Email'], $students[0]);
        $this->assertSame(['Reception', 'Joy', '1', 'Ada Obi', 'ada.1@africanchildcollege.org'], $students[1]);
        $this->assertSame(['Primary 1', 'Peace', '2', 'Zoe Ade', 'zoe.2@africanchildcollege.org'], $students[2]);
        $this->assertSame(['Unplaced', '', '9', 'No Class', 'noclass.9@africanchildcollege.org'], $students[3]);

        $this->artisan('people:export-emails', ['school' => 'primary', 'who' => 'teachers'])->assertSuccessful();
        $teachers = array_map('str_getcsv', file(storage_path('app/emails-primary-teachers.csv')));
        $this->assertSame(['Reception', 'Joy', 'T1', 'Form Teacher', 'form.t1@africanchildcollege.org'], $teachers[1]);
        $this->assertSame(['Reception', 'Joy', 'T2', 'Subject Teacher', 'subject.t2@africanchildcollege.org'], $teachers[2]);
        $this->assertSame(['Unassigned', '', 'T3', 'Spare Teacher', 'spare.t3@africanchildcollege.org'], $teachers[3]);
    }
}
