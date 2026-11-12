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
use App\Models\Term;
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

    public function test_a_new_session_copies_classes_from_the_previous_one(): void
    {
        $this->tenant(function () {
            $this->class->update(['form_teacher_id' => $this->teacher->id, 'admission_fee' => 1500]);
            ClassSubject::create([
                'class_id' => $this->class->id,
                'subject_id' => $this->subject->id,
                'teacher_id' => $this->teacher->id,
                'kind' => 'COMPULSORY',
            ]);
        });

        $this->as(Role::REGISTRAR)->post(self::HOST.'/academics/sessions', [
            'start_year' => 2027,
            'end_year' => 2028,
        ])->assertRedirect()->assertSessionHas('success');

        $this->tenant(function () {
            $new = AcademicSession::where('name', '2027/2028')->firstOrFail();
            $copy = SchoolClass::where('session_id', $new->id)->firstOrFail();
            $this->assertSame($this->class->level_id, $copy->level_id);
            $this->assertSame($this->class->arm_id, $copy->arm_id);
            $this->assertSame($this->teacher->id, $copy->form_teacher_id);
            $this->assertEquals(1500, (float) $copy->admission_fee);
            $offering = ClassSubject::where('class_id', $copy->id)->firstOrFail();
            $this->assertSame($this->subject->id, $offering->subject_id);
            $this->assertSame($this->teacher->id, $offering->teacher_id);
            $this->assertSame('COMPULSORY', $offering->kind);
            $this->assertSame(0, Enrolment::where('session_id', $new->id)->count());
            $this->assertSame(1, Enrolment::where('class_id', $this->class->id)->count());
        });

        // A session dated before every existing one has no previous session to copy.
        $this->post(self::HOST.'/academics/sessions', ['start_year' => 2020, 'end_year' => 2021, 'name' => '2020/2021'])
            ->assertSessionHas('success');
        $this->tenant(function () {
            $older = AcademicSession::where('name', '2020/2021')->firstOrFail();
            $this->assertSame(0, SchoolClass::where('session_id', $older->id)->count());
        });
    }

    public function test_session_and_term_creation_and_mark_current(): void
    {
        $this->as(Role::REGISTRAR)->post(self::HOST.'/academics/sessions', ['start_year' => 2027, 'end_year' => 2028, 'is_current' => 1])->assertRedirect();

        $this->tenant(function () {
            $new = AcademicSession::where('name', '2027/2028')->firstOrFail();
            $this->assertTrue($new->is_current);
            $this->assertSame(1, AcademicSession::where('is_current', true)->count());

            $left = Student::create(['index_number' => 'L1', 'full_name' => 'Left Behind', 'status' => 'LEFT', 'departure' => 'LEFT']);
            $graduated = Student::create(['index_number' => 'G1', 'full_name' => 'Gone Out', 'status' => 'LEFT', 'departure' => 'GRADUATED']);
            $kept = Student::create(['index_number' => 'K1', 'full_name' => 'Kept Back', 'status' => 'LEFT', 'departure' => 'LEFT']);
            Enrolment::create(['student_id' => $left->id, 'class_id' => $this->class->id, 'session_id' => $this->session->id, 'status' => 'LEFT', 'outcome' => 'LEFT']);
            Enrolment::create(['student_id' => $graduated->id, 'class_id' => $this->class->id, 'session_id' => $this->session->id, 'status' => 'LEFT', 'outcome' => 'GRADUATED']);
            $otherClass = SchoolClass::create([
                'session_id' => $new->id,
                'level_id' => $this->class->level_id,
                'arm_id' => Arm::create(['name' => 'Kept'])->id,
            ]);
            Enrolment::create(['student_id' => $kept->id, 'class_id' => $otherClass->id, 'session_id' => $new->id, 'status' => 'LEFT', 'outcome' => 'LEFT']);
            $keptElsewhere = Enrolment::create(['student_id' => $kept->id, 'class_id' => $this->class->id, 'session_id' => $this->session->id, 'status' => 'ACTIVE']);

            $this->actingAs(User::factory()->create(['school_id' => $this->school->id, 'role' => Role::ADMIN]))
                ->post(self::HOST.'/academics/sessions/'.$this->session->id.'/current', ['activate' => '0'])->assertRedirect();
            $this->assertTrue($this->session->fresh()->is_current);
            $this->assertFalse($new->fresh()->is_current);
            $this->assertSame('LEFT', $left->fresh()->status);

            $this->post(self::HOST.'/academics/sessions/'.$new->id.'/current', ['activate' => '1'])
                ->assertRedirect()
                ->assertSessionHas('success');
            $this->assertTrue($new->fresh()->is_current);
            $this->assertSame('ACTIVE', Enrolment::where('student_id', $kept->id)->where('session_id', $new->id)->value('status'));
            $this->assertNull(Enrolment::where('student_id', $kept->id)->where('session_id', $new->id)->value('outcome'));
            $this->assertSame('ACTIVE', $kept->fresh()->status);
            $this->assertNull($kept->fresh()->departure);
            $this->assertSame('LEFT', $left->fresh()->status);
            $this->assertSame('GRADUATED', $graduated->fresh()->departure);
            $this->assertSame('LEFT', $keptElsewhere->fresh()->status);
            $this->assertNull($keptElsewhere->fresh()->outcome);

            $this->post(self::HOST.'/academics/terms', ['session_id' => $this->session->id, 'sequence' => 1, 'days_school_open' => 60])->assertRedirect();
            $this->post(self::HOST.'/academics/terms', ['session_id' => $this->session->id, 'sequence' => 1])->assertSessionHas('error');
            $this->assertSame('First Term', $this->session->terms()->first()->name);
        });
    }

    public function test_deleting_a_session_removes_its_classes_and_enrolments_only(): void
    {
        $other = $this->tenant(function () {
            Term::create(['session_id' => $this->session->id, 'sequence' => 1, 'name' => 'First Term', 'days_school_open' => 60]);
            $other = AcademicSession::create(['name' => '2025/2026', 'start_year' => 2025, 'end_year' => 2026, 'is_current' => false]);
            $kept = SchoolClass::create([
                'session_id' => $other->id,
                'level_id' => $this->class->level_id,
                'arm_id' => $this->class->arm_id,
            ]);

            return $kept;
        });

        $this->as(Role::REGISTRAR)
            ->get(self::HOST.'/academics?tab=sessions')
            ->assertOk()
            ->assertSee('is the current session', false)
            ->assertSee('cannot be undone', false)
            ->assertSee('aria-label="Edit session"', false)
            ->assertSee('aria-label="Mark current"', false)
            ->assertSee('aria-label="Delete session"', false);

        $this->delete(self::HOST.'/academics/sessions/'.$this->session->id)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->tenant(function () use ($other) {
            $this->assertNull(AcademicSession::find($this->session->id));
            $this->assertSame(0, SchoolClass::where('session_id', $this->session->id)->count());
            $this->assertSame(0, Enrolment::where('session_id', $this->session->id)->count());
            $this->assertSame(0, Term::where('session_id', $this->session->id)->count());
            $this->assertNotNull(Student::find($this->enrolment->student_id));
            $this->assertNotNull(SchoolClass::find($other->id));
        });

        $this->as(Role::TEACHER)
            ->get(self::HOST.'/academics?tab=sessions')
            ->assertOk()
            ->assertDontSee('Delete', false);

        $this->delete(self::HOST.'/academics/sessions/'.$other->session_id)->assertForbidden();
    }

    public function test_a_session_and_its_terms_can_be_edited(): void
    {
        $term = $this->tenant(fn () => Term::create([
            'session_id' => $this->session->id,
            'sequence' => 1,
            'name' => 'First Term',
            'days_school_open' => 10,
        ]));

        $this->as(Role::REGISTRAR)
            ->get(self::HOST.'/academics?tab=sessions')
            ->assertOk()
            ->assertSee('Edit');

        $this->put(self::HOST.'/academics/sessions/'.$this->session->id, [
            'editing_session' => $this->session->id,
            'name' => '2026/2027 session',
            'start_year' => 2026,
            'end_year' => 2027,
            'terms' => [[
                'id' => $term->id,
                'days_school_open' => 90,
                'result_date' => '2026-12-15',
            ]],
        ])->assertRedirect()->assertSessionHas('success');

        $this->tenant(function () use ($term) {
            $this->assertSame('2026/2027 session', $this->session->fresh()->name);
            $term->refresh();
            $this->assertSame(90, $term->days_school_open);
            $this->assertSame('2026-12-15', $term->result_date->toDateString());
        });

        $this->put(self::HOST.'/academics/sessions/'.$this->session->id, [
            'name' => 'Backwards',
            'start_year' => 2028,
            'end_year' => 2027,
        ])->assertSessionHas('error');

        $this->as(Role::TEACHER)->put(self::HOST.'/academics/sessions/'.$this->session->id, [
            'name' => 'Nope',
            'start_year' => 2026,
            'end_year' => 2027,
        ])->assertForbidden();
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

    public function test_a_class_level_arm_and_fees_can_be_edited(): void
    {
        [$levelId, $armId] = $this->tenant(fn () => [Level::create(['name' => 'JSS2'])->id, Arm::create(['name' => 'B'])->id]);

        $this->as(Role::REGISTRAR)
            ->get(self::HOST.'/academics?tab=classes&class='.$this->class->id)
            ->assertOk()
            ->assertSee('Edit class');

        $this->putJson(self::HOST.'/academics/classes/'.$this->class->id, [
            'level_id' => $levelId,
            'arm_id' => $armId,
            'admission_fee' => 2500,
            'hall_charge' => 400,
        ])
            ->assertOk()
            ->assertJsonPath('panel.name', 'JSS2 B')
            ->assertJsonPath('panel.admission_fee', 2500)
            ->assertJsonPath('panel.hall_charge', 400);

        $fresh = $this->class->fresh();
        $this->assertSame($levelId, $fresh->level_id);
        $this->assertSame($armId, $fresh->arm_id);
        $this->assertEquals(2500, (float) $fresh->admission_fee);
        $this->assertEquals(400, (float) $fresh->hall_charge);

        $this->tenant(fn () => SchoolClass::create([
            'session_id' => $this->session->id,
            'level_id' => $this->class->level_id,
            'arm_id' => $this->class->arm_id,
        ]));

        $this->putJson(self::HOST.'/academics/classes/'.$fresh->id, [
            'level_id' => $this->class->level_id,
            'arm_id' => $this->class->arm_id,
        ])->assertStatus(422);

        $this->as(Role::TEACHER)->putJson(self::HOST.'/academics/classes/'.$fresh->id, [
            'level_id' => $levelId,
            'arm_id' => $armId,
        ])->assertForbidden();
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
