<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
use App\Models\Guardian;
use App\Models\Level;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PeopleTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://secondary.localhost';

    private School $school;

    private SchoolClass $class;

    private ClassSubject $compulsory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Secondary', 'slug' => 'secondary', 'subdomain' => 'secondary']);
        $this->withTenant(function () {
            $session = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $this->class = SchoolClass::create([
                'session_id' => $session->id,
                'level_id' => Level::create(['name' => 'JSS1'])->id,
                'arm_id' => Arm::create(['name' => 'A'])->id,
            ]);
            $this->compulsory = ClassSubject::create(['class_id' => $this->class->id, 'subject_id' => Subject::create(['name' => 'Maths'])->id, 'kind' => 'COMPULSORY']);
            ClassSubject::create(['class_id' => $this->class->id, 'subject_id' => Subject::create(['name' => 'Art'])->id, 'kind' => 'ELECTIVE']);
        });
    }

    private function withTenant(callable $fn)
    {
        return Tenant::run($this->school, $fn);
    }

    private function as(Role $role): static
    {
        return $this->actingAs(User::factory()->create(['school_id' => $this->school->id, 'role' => $role]));
    }

    private function studentPayload(array $over = []): array
    {
        return array_merge([
            'index_number' => 'S001',
            'full_name' => 'Ada Obi',
            'gender' => 'Female',
            'email' => 'ada@example.com',
            'password' => 'secret-pass-1',
            'address' => '1 Main St',
            'phone' => '0800',
            'birth_date' => '2012-05-01',
        ], $over);
    }

    public function test_people_page_renders_for_admin_and_nav_is_enabled(): void
    {
        $this->as(Role::ADMIN)->get(self::HOST.'/people')->assertOk()->assertSee('Find a guardian')->assertSee('href="'.self::HOST.'/people"', false);
        $this->as(Role::STUDENT)->get(self::HOST.'/people')->assertForbidden();
        $this->as(Role::TEACHER)->post(self::HOST.'/people/students', $this->studentPayload())->assertForbidden();
        $this->as(Role::REGISTRAR)->post(self::HOST.'/people/staff', [])->assertForbidden();
    }

    public function test_a_five_character_password_is_accepted_for_a_new_student(): void
    {
        $this->as(Role::REGISTRAR)
            ->post(self::HOST.'/people/students', $this->studentPayload(['password' => '12345', 'email' => 'short@example.com', 'index_number' => 'S045']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->withTenant(function () {
            $student = Student::where('index_number', 'S045')->firstOrFail();
            $user = User::where('student_id', $student->id)->firstOrFail();
            $this->assertTrue(\Illuminate\Support\Facades\Hash::check('12345', $user->password));
        });
    }

    public function test_create_student_with_class_creates_user_enrolment_and_compulsory_subjects(): void
    {
        $this->as(Role::REGISTRAR)
            ->post(self::HOST.'/people/students', $this->studentPayload(['class_id' => $this->class->id]))
            ->assertRedirect();

        $this->withTenant(function () {
            $student = Student::where('index_number', 'S001')->firstOrFail();
            $this->assertSame('ACTIVE', $student->status);

            $user = User::where('student_id', $student->id)->firstOrFail();
            $this->assertSame(Role::STUDENT, $user->role);
            $this->assertTrue($user->must_change_password);

            $enrolment = Enrolment::where('student_id', $student->id)->firstOrFail();
            $this->assertSame('ACTIVE', $enrolment->status);
            $this->assertSame($this->class->id, $enrolment->class_id);
            $this->assertSame([$this->compulsory->id], EnrolmentSubject::where('enrolment_id', $enrolment->id)->pluck('class_subject_id')->all());
        });
    }

    public function test_create_student_without_class_and_duplicate_index_rejected(): void
    {
        $this->as(Role::ADMIN)->post(self::HOST.'/people/students', $this->studentPayload())->assertRedirect();
        $this->withTenant(fn () => $this->assertSame(0, Enrolment::count()));

        $this->post(self::HOST.'/people/students', $this->studentPayload(['email' => 'other@example.com']))
            ->assertSessionHasErrors('index_number');
    }

    public function test_class_from_other_session_is_rejected(): void
    {
        $this->as(Role::ADMIN)
            ->post(self::HOST.'/people/students', $this->studentPayload(['class_id' => 'nope']))
            ->assertSessionHasErrors('class_id');
        $this->withTenant(fn () => $this->assertSame(0, Student::count()));
    }

    public function test_student_and_staff_profiles_can_be_edited(): void
    {
        $student = $this->withTenant(fn () => Student::create([
            'index_number' => 'S9',
            'full_name' => 'Kay Ade',
            'email' => 'kay@example.com',
            'status' => 'ACTIVE',
        ]));
        $this->withTenant(fn () => User::factory()->create([
            'school_id' => $this->school->id,
            'email' => 'kay@example.com',
            'role' => Role::STUDENT,
            'student_id' => $student->id,
        ]));
        $enrolment = $this->withTenant(fn () => Enrolment::create([
            'student_id' => $student->id,
            'class_id' => $this->class->id,
            'session_id' => $this->class->session_id,
            'status' => 'ACTIVE',
        ]));
        $staff = $this->withTenant(fn () => Staff::create([
            'index_number' => 'T9',
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'email' => 'ada.staff@example.com',
            'is_teacher' => true,
            'status' => 'ACTIVE',
        ]));
        $this->withTenant(fn () => User::factory()->create([
            'school_id' => $this->school->id,
            'email' => 'ada.staff@example.com',
            'role' => Role::TEACHER,
            'staff_id' => $staff->id,
        ]));

        $this->as(Role::REGISTRAR)
            ->get(self::HOST.'/people?student='.$student->id)
            ->assertOk()
            ->assertSee('aria-label="Edit student"', false);

        $this->put(self::HOST.'/people/students/'.$student->id, [
            'index_number' => 'S10',
            'full_name' => 'Kay Updated',
            'email' => 'kay.new@example.com',
            'phone' => '0801',
            'status' => 'GRADUATED',
        ])->assertRedirect()->assertSessionHas('success');

        $this->withTenant(function () use ($student, $enrolment) {
            $fresh = $student->fresh();
            $this->assertSame('Kay Updated', $fresh->full_name);
            $this->assertSame('S10', $fresh->index_number);
            $this->assertSame('LEFT', $fresh->status);
            $this->assertSame('GRADUATED', $fresh->departure);
            $this->assertSame('kay.new@example.com', $fresh->user->email);
            $this->assertSame('LEFT', $enrolment->fresh()->status);
            $this->assertSame('GRADUATED', $enrolment->fresh()->outcome);
        });

        $this->as(Role::TEACHER)->put(self::HOST.'/people/students/'.$student->id, [])->assertForbidden();

        $this->as(Role::ADMIN)
            ->get(self::HOST.'/people?tab=staff&staff='.$staff->id)
            ->assertOk()
            ->assertSee('aria-label="Edit staff"', false);

        $this->put(self::HOST.'/people/staff/'.$staff->id, [
            'index_number' => 'T10',
            'first_name' => 'Ada',
            'last_name' => 'Nwosu',
            'email' => 'ada.new@example.com',
            'phone' => '0902',
        ])->assertRedirect()->assertSessionHas('success');

        $this->withTenant(function () use ($staff) {
            $fresh = $staff->fresh();
            $this->assertSame('Nwosu', $fresh->last_name);
            $this->assertSame('ada.new@example.com', $fresh->user->email);
        });

        $this->as(Role::REGISTRAR)->put(self::HOST.'/people/staff/'.$staff->id, [
            'index_number' => 'T10',
            'first_name' => 'Ada',
            'last_name' => 'Nwosu',
            'email' => 'ada.new@example.com',
        ])->assertForbidden();
    }

    public function test_student_list_filters_status_class_and_search_and_panel_shows(): void
    {
        $this->withTenant(function () {
            $a = Student::create(['index_number' => 'A1', 'full_name' => 'Alice Active', 'status' => 'ACTIVE', 'phone' => '0701']);
            Enrolment::create(['student_id' => $a->id, 'class_id' => $this->class->id, 'session_id' => $this->class->session_id, 'status' => 'ACTIVE']);
            Student::create(['index_number' => 'B2', 'full_name' => 'Bob Active', 'status' => 'ACTIVE']);
            Student::create(['index_number' => 'C3', 'full_name' => 'Carl Left', 'status' => 'LEFT']);
            Student::create(['index_number' => 'D4', 'full_name' => 'Dora Graduated', 'status' => 'LEFT', 'departure' => 'GRADUATED']);
            $g = Guardian::create(['full_name' => 'Mrs Active', 'phone' => '0999']);
            StudentGuardian::create(['student_id' => $a->id, 'guardian_id' => $g->id, 'relationship' => 'mother']);
            $this->aid = $a->id;
        });

        $this->as(Role::REGISTRAR);
        $this->get(self::HOST.'/people')->assertSee('Alice Active')->assertSee('Bob Active')->assertDontSee('Carl Left');
        $this->get(self::HOST.'/people?status=LEFT')->assertSee('Carl Left')->assertDontSee('Alice Active')->assertDontSee('Dora Graduated');
        $this->get(self::HOST.'/people?status=GRADUATED')->assertSee('Dora Graduated')->assertSee('Graduated')->assertDontSee('Carl Left')->assertDontSee('Alice Active');
        $this->get(self::HOST.'/people?class='.$this->class->id)->assertSee('Alice Active')->assertDontSee('Bob Active');
        $this->get(self::HOST.'/people?q=B2')->assertSee('Bob Active')->assertDontSee('Alice Active');
        $this->get(self::HOST.'/people?student='.$this->aid)->assertSee('Mrs Active')->assertSee('JSS1 A')->assertSee('0701');
    }

    public function test_add_guardian_with_and_without_login(): void
    {
        $student = $this->withTenant(fn () => Student::create(['index_number' => 'A1', 'full_name' => 'Alice']));

        $this->as(Role::ADMIN)->post(self::HOST."/people/students/{$student->id}/guardians", ['full_name' => 'No Login', 'phone' => '1'])->assertRedirect();
        $this->post(self::HOST."/people/students/{$student->id}/guardians", ['full_name' => 'With Login', 'email' => 'p@example.com', 'password' => 'parent-pass-1'])->assertRedirect();
        $this->post(self::HOST."/people/students/{$student->id}/guardians", ['full_name' => 'Bad', 'password' => 'parent-pass-1'])->assertSessionHasErrorsIn('guardian', 'email');

        $this->withTenant(function () use ($student) {
            $this->assertSame(2, StudentGuardian::where('student_id', $student->id)->count());
            $this->assertNull(Guardian::where('full_name', 'No Login')->first()->user);
            $user = Guardian::where('full_name', 'With Login')->first()->user;
            $this->assertSame(Role::PARENT, $user->role);
        });
    }

    public function test_admin_creates_staff_and_list_shows_them(): void
    {
        $this->as(Role::ADMIN)->post(self::HOST.'/people/staff', [
            'role' => 'TEACHER', 'index_number' => 'T1', 'first_name' => 'Tom', 'last_name' => 'Teach',
            'email' => 'tom@example.com', 'password' => 'teacher-pass-1', 'is_teacher' => '0',
        ])->assertRedirect();

        $this->withTenant(function () {
            $staff = Staff::where('index_number', 'T1')->firstOrFail();
            $this->assertTrue($staff->is_teacher);
            $user = User::where('staff_id', $staff->id)->firstOrFail();
            $this->assertSame(Role::TEACHER, $user->role);
            $this->assertTrue($user->must_change_password);
        });

        $this->get(self::HOST.'/people?tab=staff')->assertSee('Tom Teach')->assertSee('tom@example.com');
        $this->post(self::HOST.'/people/staff', ['role' => 'STUDENT'])->assertSessionHasErrorsIn('staff', 'role');
    }

    public function test_guardian_search_is_search_only_and_shows_linked_students(): void
    {
        $this->withTenant(function () {
            $s = Student::create(['index_number' => 'A1', 'full_name' => 'Alice Child']);
            $g = Guardian::create(['full_name' => 'Zed Parent', 'phone' => '0555', 'email' => 'zed@example.com']);
            StudentGuardian::create(['student_id' => $s->id, 'guardian_id' => $g->id, 'relationship' => 'father']);
            Guardian::create(['full_name' => 'Other Person']);
        });

        $this->as(Role::REGISTRAR);
        $this->get(self::HOST.'/people?tab=guardians')->assertDontSee('Zed Parent');
        $this->get(self::HOST.'/people?tab=guardians&q=0555')->assertSee('Zed Parent')->assertSee('Alice Child')->assertDontSee('Other Person');
    }

    public function test_other_school_data_is_not_visible(): void
    {
        $other = School::create(['name' => 'Primary', 'slug' => 'primary', 'subdomain' => 'primary']);
        Tenant::run($other, fn () => Student::create(['index_number' => 'X1', 'full_name' => 'Foreign Student']));

        $this->as(Role::ADMIN)->get(self::HOST.'/people')->assertDontSee('Foreign Student');
    }

    public function test_teacher_sees_only_their_class_subject_students_and_guardians(): void
    {
        [$ada, $outsider] = $this->withTenant(function () {
            $ada = Staff::create([
                'index_number' => 'T9',
                'first_name' => 'Ada',
                'last_name' => 'Obi',
                'is_teacher' => true,
                'status' => 'ACTIVE',
                'gender' => 'Female',
            ]);
            $this->class->update(['form_teacher_id' => $ada->id]);

            $other = SchoolClass::create([
                'session_id' => $this->class->session_id,
                'level_id' => Level::create(['name' => 'JSS2'])->id,
                'arm_id' => Arm::create(['name' => 'B'])->id,
            ]);
            $english = ClassSubject::create([
                'class_id' => $other->id,
                'subject_id' => Subject::create(['name' => 'English'])->id,
                'teacher_id' => $ada->id,
                'kind' => 'COMPULSORY',
            ]);

            $kay = Student::create(['index_number' => 'K1', 'full_name' => 'Kay Form', 'status' => 'ACTIVE']);
            Student::create(['index_number' => 'L1', 'full_name' => 'Left Kid', 'status' => 'LEFT']);
            $left = Student::where('index_number', 'L1')->first();
            $ife = Student::create(['index_number' => 'I1', 'full_name' => 'Ife Subject', 'status' => 'ACTIVE']);
            $outsider = Student::create(['index_number' => 'O1', 'full_name' => 'Outsider Kid', 'status' => 'ACTIVE']);

            Enrolment::create(['student_id' => $kay->id, 'class_id' => $this->class->id, 'session_id' => $this->class->session_id, 'status' => 'ACTIVE']);
            Enrolment::create(['student_id' => $left->id, 'class_id' => $this->class->id, 'session_id' => $this->class->session_id, 'status' => 'ACTIVE']);
            $ifeEnrolment = Enrolment::create(['student_id' => $ife->id, 'class_id' => $other->id, 'session_id' => $this->class->session_id, 'status' => 'ACTIVE']);
            Enrolment::create(['student_id' => $outsider->id, 'class_id' => $other->id, 'session_id' => $this->class->session_id, 'status' => 'ACTIVE']);
            EnrolmentSubject::create(['enrolment_id' => $ifeEnrolment->id, 'class_subject_id' => $english->id]);

            $parent = Guardian::create(['full_name' => 'Kay Parent', 'phone' => '0700']);
            StudentGuardian::create(['student_id' => $kay->id, 'guardian_id' => $parent->id, 'relationship' => 'mother']);
            $otherParent = Guardian::create(['full_name' => 'Outsider Parent', 'phone' => '0800']);
            StudentGuardian::create(['student_id' => $outsider->id, 'guardian_id' => $otherParent->id, 'relationship' => 'father']);
            Staff::create(['index_number' => 'T8', 'first_name' => 'Secret', 'last_name' => 'Staffer', 'is_teacher' => false, 'status' => 'ACTIVE']);

            return [$ada, $outsider];
        });

        $this->actingAs(User::factory()->create([
            'school_id' => $this->school->id,
            'role' => Role::TEACHER,
            'staff_id' => $ada->id,
        ]));

        $this->get(self::HOST.'/people')
            ->assertOk()
            ->assertSee('My class students')
            ->assertSee('All my students')
            ->assertSee('Guardians')
            ->assertSee('Kay Form')
            ->assertDontSee('Ife Subject')
            ->assertDontSee('Left Kid')
            ->assertDontSee('Outsider Kid')
            ->assertDontSee('>Left<', false)
            ->assertDontSee('>Staff<', false);

        $this->get(self::HOST.'/people?tab=staff&status=LEFT')
            ->assertOk()
            ->assertSee('Kay Form')
            ->assertDontSee('Left Kid')
            ->assertDontSee('Secret Staffer');

        $this->get(self::HOST.'/people?scope=all')
            ->assertOk()
            ->assertSee('Kay Form')
            ->assertSee('Ife Subject')
            ->assertDontSee('Outsider Kid')
            ->assertDontSee('Left Kid');

        $this->get(self::HOST.'/people?student='.$outsider->id)
            ->assertOk()
            ->assertDontSee('Outsider Kid');

        $this->get(self::HOST.'/people?tab=guardians&q=Parent')
            ->assertOk()
            ->assertSee('Kay Parent')
            ->assertDontSee('Outsider Parent');
    }

    public function test_only_admin_and_platform_owner_can_set_passwords(): void
    {
        $student = $this->withTenant(fn () => Student::create([
            'index_number' => 'S20',
            'full_name' => 'Password Kid',
            'email' => 'kid@example.com',
            'status' => 'ACTIVE',
        ]));
        $login = $this->withTenant(fn () => User::factory()->create([
            'school_id' => $this->school->id,
            'email' => 'kid@example.com',
            'role' => Role::STUDENT,
            'student_id' => $student->id,
            'must_change_password' => true,
        ]));
        $staff = $this->withTenant(fn () => Staff::create([
            'index_number' => 'T20',
            'first_name' => 'Password',
            'last_name' => 'Teacher',
            'email' => 'teacher@example.com',
            'is_teacher' => true,
            'status' => 'ACTIVE',
        ]));
        $staffLogin = $this->withTenant(fn () => User::factory()->create([
            'school_id' => $this->school->id,
            'email' => 'teacher@example.com',
            'role' => Role::TEACHER,
            'staff_id' => $staff->id,
        ]));
        $guardian = $this->withTenant(fn () => Guardian::create([
            'full_name' => 'Password Parent',
            'email' => 'parent@example.com',
            'phone' => '0803',
        ]));

        $fields = [
            'index_number' => 'S20',
            'full_name' => 'Password Kid',
            'email' => 'kid@example.com',
            'status' => 'ACTIVE',
        ];

        $this->as(Role::REGISTRAR)
            ->get(self::HOST.'/people?student='.$student->id)
            ->assertOk()
            ->assertDontSee('id="e_password"', false);

        $this->put(self::HOST.'/people/students/'.$student->id, $fields + ['password' => 'registrar-try'])
            ->assertRedirect();

        $this->withTenant(fn () => $this->assertTrue(Hash::check('password', $login->fresh()->password)));

        $this->as(Role::ADMIN)
            ->get(self::HOST.'/people?student='.$student->id)
            ->assertOk()
            ->assertSee('id="e_password"', false);

        $this->put(self::HOST.'/people/students/'.$student->id, $fields + ['password' => 'no'])
            ->assertSessionHasErrors('password', null, 'student');

        $this->put(self::HOST.'/people/students/'.$student->id, $fields + ['password' => 'new-pass'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->withTenant(function () use ($login) {
            $fresh = $login->fresh();
            $this->assertTrue(Hash::check('new-pass', $fresh->password));
            $this->assertFalse($fresh->must_change_password);
        });

        $this->as(Role::ADMIN)
            ->get(self::HOST.'/people?tab=staff&staff='.$staff->id)
            ->assertOk()
            ->assertSee('id="se_password"', false);

        $this->put(self::HOST.'/people/staff/'.$staff->id, [
            'index_number' => 'T20',
            'first_name' => 'Password',
            'last_name' => 'Teacher',
            'email' => 'teacher@example.com',
            'password' => 'staff-pass',
        ])->assertRedirect();

        $this->withTenant(fn () => $this->assertTrue(Hash::check('staff-pass', $staffLogin->fresh()->password)));

        $this->as(Role::REGISTRAR)
            ->get(self::HOST.'/people?tab=guardians&q=Password')
            ->assertOk()
            ->assertDontSee('aria-label="Set password"', false);

        $this->put(self::HOST.'/people/guardians/'.$guardian->id.'/password', ['password' => 'parent-pass'])
            ->assertForbidden();

        $this->as(Role::PLATFORM_OWNER)
            ->put(self::HOST.'/people/guardians/'.$guardian->id.'/password', [
                'password' => 'parent-pass',
                'q' => 'Password',
            ])
            ->assertRedirect();

        $this->withTenant(function () use ($guardian) {
            $login = $guardian->fresh()->user;
            $this->assertSame(Role::PARENT, $login->role);
            $this->assertTrue(Hash::check('parent-pass', $login->password));
            $this->assertFalse($login->must_change_password);
        });
    }
}
