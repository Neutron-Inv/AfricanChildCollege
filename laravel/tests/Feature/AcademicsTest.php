<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
use App\Models\GradingBand;
use App\Models\GradingScale;
use App\Models\Level;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://acad.localhost';

    private School $school;

    private AcademicSession $session;

    private SchoolClass $class;

    private Subject $subject;

    private Staff $teacher;

    private Enrolment $enrolment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->school = School::create(['name' => 'Acad', 'slug' => 'acad', 'subdomain' => 'acad']);

        Tenant::run($this->school, function () {
            $this->session = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $this->class = SchoolClass::create([
                'session_id' => $this->session->id,
                'level_id' => Level::create(['name' => 'JSS1'])->id,
                'arm_id' => Arm::create(['name' => 'A'])->id,
            ]);
            $this->subject = Subject::create(['name' => 'Maths']);
            $this->teacher = Staff::create(['index_number' => 'T1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'is_teacher' => true, 'status' => 'ACTIVE', 'gender' => 'Female']);
            $student = Student::create(['index_number' => 'S1', 'full_name' => 'Kay Ade', 'gender' => 'Male', 'status' => 'ACTIVE']);
            $this->enrolment = Enrolment::create(['student_id' => $student->id, 'class_id' => $this->class->id, 'session_id' => $this->session->id, 'status' => 'ACTIVE']);

            $scale = GradingScale::create(['name' => 'Default', 'is_default' => true]);
            GradingBand::create(['scale_id' => $scale->id, 'min_score' => 0, 'max_score' => 100, 'letter' => 'A', 'remark' => 'Everything']);
        });
    }

    private function as(Role $role, array $attrs = []): static
    {
        return $this->actingAs(User::factory()->create(['school_id' => $this->school->id, 'role' => $role] + $attrs));
    }

    private function tenant(callable $fn)
    {
        return Tenant::run($this->school, $fn);
    }

    public function test_every_tab_renders_and_teachers_get_a_read_only_view(): void
    {
        foreach (['classes', 'subjects', 'sessions', 'departments', 'rooms', 'grading'] as $tab) {
            $this->as(Role::ADMIN)->get(self::HOST.'/academics?tab='.$tab)->assertOk();
        }

        $this->as(Role::ADMIN)->get(self::HOST.'/academics?class='.$this->class->id)
            ->assertOk()->assertSee('JSS1 A')->assertSee('Assign subject');

        // Teacher must be assigned before the class appears on their Academics list.
        $this->tenant(fn () => ClassSubject::create([
            'class_id' => $this->class->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'kind' => 'COMPULSORY',
        ]));

        $teacherUser = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => Role::TEACHER,
            'staff_id' => $this->teacher->id,
        ]);

        $this->actingAs($teacherUser)
            ->get(self::HOST.'/academics?class='.$this->class->id)
            ->assertOk()
            ->assertSee('JSS1 A')
            ->assertDontSee('Assign subject')
            ->assertSee('view-only');

        $this->as(Role::STUDENT)->get(self::HOST.'/academics')->assertForbidden();
        $this->actingAs($teacherUser)->post(self::HOST.'/academics/rooms', ['name' => 'R1'])->assertForbidden();
        $this->as(Role::REGISTRAR)->put(self::HOST.'/academics/grading', [])->assertForbidden();
    }

    public function test_teachers_only_see_classes_they_teach(): void
    {
        [$otherClass, $otherTeacher] = $this->tenant(function () {
            $other = SchoolClass::create([
                'session_id' => $this->session->id,
                'level_id' => Level::create(['name' => 'JSS2'])->id,
                'arm_id' => Arm::create(['name' => 'B'])->id,
            ]);
            $otherTeacher = Staff::create([
                'index_number' => 'T2',
                'first_name' => 'Bola',
                'last_name' => 'Oka',
                'is_teacher' => true,
                'status' => 'ACTIVE',
                'gender' => 'Male',
            ]);
            ClassSubject::create([
                'class_id' => $this->class->id,
                'subject_id' => $this->subject->id,
                'teacher_id' => $this->teacher->id,
                'kind' => 'COMPULSORY',
            ]);
            ClassSubject::create([
                'class_id' => $other->id,
                'subject_id' => Subject::create(['name' => 'English'])->id,
                'teacher_id' => $otherTeacher->id,
                'kind' => 'COMPULSORY',
            ]);

            return [$other, $otherTeacher];
        });

        $ada = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => Role::TEACHER,
            'staff_id' => $this->teacher->id,
        ]);
        $bola = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => Role::TEACHER,
            'staff_id' => $otherTeacher->id,
        ]);

        $this->actingAs($ada)
            ->get(self::HOST.'/academics?tab=classes')
            ->assertOk()
            ->assertSee('JSS1 A')
            ->assertDontSee('JSS2 B')
            ->assertDontSee('Ask the office to put you on a class subject');

        // Deep-linking another teacher's class is ignored (panel stays closed).
        $this->actingAs($ada)
            ->get(self::HOST.'/academics?tab=classes&class='.$otherClass->id)
            ->assertOk()
            ->assertDontSee('JSS2 B');

        $this->actingAs($ada)
            ->getJson(self::HOST.'/academics/classes/'.$otherClass->id.'/panel')
            ->assertForbidden();

        $this->tenant(function () {
            $civicTeacher = Staff::create([
                'index_number' => 'T3',
                'first_name' => 'Tunde',
                'last_name' => 'Ade',
                'is_teacher' => true,
                'status' => 'ACTIVE',
                'gender' => 'Male',
            ]);
            ClassSubject::create([
                'class_id' => $this->class->id,
                'subject_id' => Subject::create(['name' => 'Civic'])->id,
                'teacher_id' => $civicTeacher->id,
                'kind' => 'COMPULSORY',
            ]);
        });

        // Ada teaches Maths here, so Civic (Tunde) stays off her panel.
        $this->actingAs($ada)
            ->getJson(self::HOST.'/academics/classes/'.$this->class->id.'/panel')
            ->assertOk()
            ->assertJsonPath('name', 'JSS1 A')
            ->assertJsonPath('full_subjects', false)
            ->assertJsonCount(1, 'subjects')
            ->assertJsonPath('subjects.0.subject_name', 'Maths');

        $this->actingAs($ada)
            ->get(self::HOST.'/academics?tab=classes&class='.$this->class->id)
            ->assertOk()
            ->assertSee('Maths')
            ->assertDontSee('Civic');

        // As form teacher she sees every subject and who teaches it.
        $this->tenant(fn () => $this->class->update(['form_teacher_id' => $this->teacher->id]));
        $this->actingAs($ada)
            ->getJson(self::HOST.'/academics/classes/'.$this->class->id.'/panel')
            ->assertOk()
            ->assertJsonPath('full_subjects', true)
            ->assertJsonCount(2, 'subjects')
            ->assertSee('Civic')
            ->assertSee('Tunde');

        // Bola teaches JSS2 B only.
        $this->actingAs($bola)
            ->get(self::HOST.'/academics?tab=classes')
            ->assertOk()
            ->assertSee('JSS2 B')
            ->assertDontSee('JSS1 A');
    }

    public function test_session_and_term_creation_and_mark_current(): void
    {
        $this->as(Role::REGISTRAR)->post(self::HOST.'/academics/sessions', ['start_year' => 2027, 'end_year' => 2028, 'is_current' => 1])->assertRedirect();

        $this->tenant(function () {
            $new = AcademicSession::where('name', '2027/2028')->firstOrFail();
            $this->assertTrue($new->is_current);
            $this->assertSame(1, AcademicSession::where('is_current', true)->count());

            $this->actingAs(User::factory()->create(['school_id' => $this->school->id, 'role' => Role::ADMIN]))
                ->post(self::HOST.'/academics/sessions/'.$this->session->id.'/current')->assertRedirect();
            $this->assertTrue($this->session->fresh()->is_current);
            $this->assertFalse($new->fresh()->is_current);

            $this->post(self::HOST.'/academics/terms', ['session_id' => $this->session->id, 'sequence' => 1, 'days_school_open' => 60])->assertRedirect();
            $this->post(self::HOST.'/academics/terms', ['session_id' => $this->session->id, 'sequence' => 1])->assertSessionHas('error');
            $this->assertSame('First Term', $this->session->terms()->first()->name);
        });
    }

    public function test_class_subjects_attach_compulsory_to_active_enrolments_and_remove_cleans_up(): void
    {
        $this->as(Role::ADMIN);

        $this->post(self::HOST.'/academics/classes/'.$this->class->id.'/subjects', [
            'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id, 'kind' => 'ELECTIVE',
        ])->assertRedirect()->assertSessionHas('success');

        $cs = $this->tenant(fn () => ClassSubject::firstOrFail());
        $this->assertSame($this->teacher->id, $cs->teacher_id);
        $this->assertSame(0, $this->tenant(fn () => EnrolmentSubject::count()));

        // Elective -> compulsory attaches; repeating is a no-op (skip duplicates).
        $this->put(self::HOST.'/academics/class-subjects/'.$cs->id.'/kind', ['kind' => 'COMPULSORY'])->assertRedirect();
        $this->put(self::HOST.'/academics/class-subjects/'.$cs->id.'/kind', ['kind' => 'COMPULSORY'])->assertRedirect();
        $this->assertSame(1, $this->tenant(fn () => EnrolmentSubject::where('enrolment_id', $this->enrolment->id)->count()));

        $this->put(self::HOST.'/academics/class-subjects/'.$cs->id.'/teacher', ['teacher_id' => ''])->assertRedirect();
        $this->assertNull($cs->fresh()->teacher_id);

        $this->post(self::HOST.'/academics/classes/'.$this->class->id.'/subjects', ['subject_id' => $this->subject->id, 'kind' => 'ELECTIVE'])
            ->assertSessionHas('error');

        $this->delete(self::HOST.'/academics/class-subjects/'.$cs->id)->assertRedirect();
        $this->assertSame(0, $this->tenant(fn () => EnrolmentSubject::count() + ClassSubject::count()));
    }

    public function test_add_class_form_teacher_and_duplicate_guard(): void
    {
        $this->as(Role::ADMIN);
        [$levelId, $armId] = $this->tenant(fn () => [Level::create(['name' => 'JSS2'])->id, Arm::first()->id]);

        $this->post(self::HOST.'/academics/classes', [
            'session_id' => $this->session->id, 'level_id' => $levelId, 'arm_id' => $armId, 'form_teacher_id' => $this->teacher->id,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame($this->teacher->id, $this->tenant(fn () => SchoolClass::where('level_id', $levelId)->first()->form_teacher_id));

        $this->post(self::HOST.'/academics/classes', ['session_id' => $this->session->id, 'level_id' => $levelId, 'arm_id' => $armId])
            ->assertSessionHas('error');

        $this->put(self::HOST.'/academics/classes/'.$this->class->id.'/form-teacher', ['form_teacher_id' => $this->teacher->id])->assertRedirect();
        $this->put(self::HOST.'/academics/classes/'.$this->class->id.'/form-teacher', ['form_teacher_id' => ''])->assertRedirect();
        $this->assertNull($this->class->fresh()->form_teacher_id);
    }

    public function test_class_panel_sync_saves_kind_and_teacher_over_ajax(): void
    {
        $this->as(Role::ADMIN);

        $this->post(self::HOST.'/academics/classes/'.$this->class->id.'/subjects', [
            'subject_id' => $this->subject->id, 'teacher_id' => '', 'kind' => 'ELECTIVE',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Subject added to class.')
            ->assertJsonPath('panel.subjects.0.kind', 'ELECTIVE');

        $cs = $this->tenant(fn () => ClassSubject::firstOrFail());

        $this->putJson(self::HOST.'/academics/classes/'.$this->class->id.'/subjects', [
            'form_teacher_id' => $this->teacher->id,
            'subjects' => [
                ['id' => $cs->id, 'kind' => 'COMPULSORY', 'teacher_id' => $this->teacher->id],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Class subjects saved.')
            ->assertJsonPath('panel.form_teacher_id', $this->teacher->id)
            ->assertJsonPath('panel.subjects.0.kind', 'COMPULSORY')
            ->assertJsonPath('panel.subjects.0.teacher_id', $this->teacher->id);

        $this->assertSame(1, $this->tenant(fn () => EnrolmentSubject::where('enrolment_id', $this->enrolment->id)->count()));
        $this->assertSame($this->teacher->id, $this->class->fresh()->form_teacher_id);

        $this->getJson(self::HOST.'/academics/classes/'.$this->class->id.'/panel')
            ->assertOk()
            ->assertJsonPath('name', $this->class->fresh()->display_name)
            ->assertJsonPath('subjects.0.subject_name', $this->subject->name);

        $this->deleteJson(self::HOST.'/academics/class-subjects/'.$cs->id)
            ->assertOk()
            ->assertJsonPath('panel.subjects', []);
    }

    public function test_catalogues_and_grading(): void
    {
        $this->as(Role::REGISTRAR);
        $this->post(self::HOST.'/academics/departments', ['name' => 'Science', 'hod_id' => $this->teacher->id])->assertSessionHas('success');
        $this->post(self::HOST.'/academics/subjects', ['name' => 'Physics', 'department_id' => $this->tenant(fn () => \App\Models\Department::first()->id)])->assertSessionHas('success');
        $this->post(self::HOST.'/academics/subjects', ['name' => 'Physics'])->assertSessionHas('error');

        $physics = $this->tenant(fn () => Subject::where('name', 'Physics')->firstOrFail());
        $this->putJson(self::HOST.'/academics/subjects/'.$physics->id, [
            'name' => 'Physics',
            'department_id' => '',
        ])->assertOk()->assertJsonPath('subject.department_id', '');
        $this->assertNull($physics->fresh()->department_id);

        [$oldSession] = $this->tenant(function () {
            $old = AcademicSession::create(['name' => '2019/2020', 'start_year' => 2019, 'end_year' => 2020, 'is_current' => false]);
            $oldClass = SchoolClass::create([
                'session_id' => $old->id,
                'level_id' => Level::create(['name' => 'OldLevel'])->id,
                'arm_id' => Arm::create(['name' => 'Z'])->id,
            ]);
            ClassSubject::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'kind' => 'COMPULSORY']);
            ClassSubject::create(['class_id' => $oldClass->id, 'subject_id' => $this->subject->id, 'kind' => 'ELECTIVE']);

            return [$old];
        });

        $this->get(self::HOST.'/academics?tab=subjects&subject='.$this->subject->id)
            ->assertOk()
            ->assertSee('2026/2027')
            ->assertSee('current')
            ->assertSee('JSS1 A')
            ->assertDontSee('OldLevel Z')
            ->assertSee('Offered in');

        $this->get(self::HOST.'/academics?tab=subjects&subject='.$this->subject->id.'&session='.$oldSession->id)
            ->assertOk()
            ->assertSee('OldLevel Z')
            ->assertDontSee('JSS1 A');
        $this->post(self::HOST.'/academics/rooms', ['name' => 'Lab 1', 'capacity' => 30])->assertSessionHas('success');

        $bands = [
            ['min_score' => 0, 'max_score' => 49, 'letter' => 'F', 'remark' => 'Fail'],
            ['min_score' => 50, 'max_score' => 100, 'letter' => 'P', 'remark' => 'Pass'],
        ];
        $this->as(Role::ADMIN)->put(self::HOST.'/academics/grading', ['bands' => $bands])->assertSessionHas('success');
        $this->assertSame(['F', 'P'], $this->tenant(fn () => GradingBand::orderBy('min_score')->pluck('letter')->all()));

        $overlap = [['min_score' => 0, 'max_score' => 60, 'letter' => 'F', 'remark' => 'x'], ['min_score' => 50, 'max_score' => 100, 'letter' => 'P', 'remark' => 'y']];
        $this->put(self::HOST.'/academics/grading', ['bands' => $overlap])->assertSessionHas('error');
    }
}
