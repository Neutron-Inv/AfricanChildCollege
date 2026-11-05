<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\AuditLog;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
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

class EnrolmentTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://enrol.localhost';

    private School $school;

    private AcademicSession $s1;

    private AcademicSession $s2;

    private Level $jss1;

    private Level $jss2;

    private Arm $armA;

    private Arm $armB;

    private Subject $maths;

    private Subject $art;

    private Subject $music;

    /** @var array<string, SchoolClass> */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->school = School::create(['name' => 'Enrol', 'slug' => 'enrol', 'subdomain' => 'enrol']);

        $this->tenant(function () {
            $this->s1 = AcademicSession::create(['name' => '2025/2026', 'start_year' => 2025, 'end_year' => 2026, 'is_current' => false]);
            $this->s2 = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $this->jss1 = Level::create(['name' => 'JSS1', 'sort_order' => 1]);
            $this->jss2 = Level::create(['name' => 'JSS2', 'sort_order' => 2]);
            $this->armA = Arm::create(['name' => 'A']);
            $this->armB = Arm::create(['name' => 'B']);
            $this->maths = Subject::create(['name' => 'Maths']);
            $this->art = Subject::create(['name' => 'Art']);
            $this->music = Subject::create(['name' => 'Music']);

            // Session 1: JSS1 A (maths compulsory, art + music elective). Session 2: JSS1 A, JSS1 B, JSS2 A.
            $this->c['1-jss1a'] = $this->makeClass($this->s1, $this->jss1, $this->armA);
            $this->c['2-jss1a'] = $this->makeClass($this->s2, $this->jss1, $this->armA);
            $this->c['2-jss1b'] = $this->makeClass($this->s2, $this->jss1, $this->armB);
            $this->c['2-jss2a'] = $this->makeClass($this->s2, $this->jss2, $this->armA);

            $this->offer($this->c['1-jss1a'], $this->maths, 'COMPULSORY');
            $this->offer($this->c['1-jss1a'], $this->art, 'ELECTIVE');
            $this->offer($this->c['1-jss1a'], $this->music, 'ELECTIVE');

            $this->offer($this->c['2-jss1a'], $this->maths, 'COMPULSORY');
            $this->offer($this->c['2-jss1a'], $this->art, 'ELECTIVE');
            $this->offer($this->c['2-jss1b'], $this->maths, 'COMPULSORY');
            $this->offer($this->c['2-jss1b'], $this->art, 'ELECTIVE');
            $this->offer($this->c['2-jss2a'], $this->maths, 'COMPULSORY');
            $this->offer($this->c['2-jss2a'], $this->music, 'COMPULSORY'); // music turns compulsory
        });
    }

    private function makeClass(AcademicSession $session, Level $level, Arm $arm): SchoolClass
    {
        return SchoolClass::create(['session_id' => $session->id, 'level_id' => $level->id, 'arm_id' => $arm->id]);
    }

    private function offer(SchoolClass $class, Subject $subject, string $kind): ClassSubject
    {
        return ClassSubject::create(['class_id' => $class->id, 'subject_id' => $subject->id, 'kind' => $kind]);
    }

    private function student(string $index, string $name, string $status = 'ACTIVE'): Student
    {
        return Student::create(['index_number' => $index, 'full_name' => $name, 'gender' => 'Male', 'status' => $status]);
    }

    private function enrol(Student $student, SchoolClass $class, string $status = 'ACTIVE'): Enrolment
    {
        $enrolment = Enrolment::create(['student_id' => $student->id, 'class_id' => $class->id, 'session_id' => $class->session_id, 'status' => $status]);
        foreach (ClassSubject::where('class_id', $class->id)->where('kind', 'COMPULSORY')->get() as $cs) {
            EnrolmentSubject::create(['enrolment_id' => $enrolment->id, 'class_subject_id' => $cs->id]);
        }

        return $enrolment;
    }

    private function cs(SchoolClass $class, Subject $subject): ClassSubject
    {
        return ClassSubject::where('class_id', $class->id)->where('subject_id', $subject->id)->firstOrFail();
    }

    /** @return array<int, string> subject names of an enrolment */
    private function subjectsOf(Enrolment $e): array
    {
        return $this->tenant(fn () => EnrolmentSubject::where('enrolment_id', $e->id)->with('classSubject.subject')->get()
            ->map(fn ($es) => $es->classSubject->subject->name)->sort()->values()->all());
    }

    private function as(Role $role): static
    {
        return $this->actingAs(User::factory()->create(['school_id' => $this->school->id, 'role' => $role]));
    }

    private function tenant(callable $fn)
    {
        return Tenant::run($this->school, $fn);
    }

    public function test_access_is_limited_to_admin_and_registrar(): void
    {
        $this->as(Role::ADMIN)->get(self::HOST.'/enrolment')->assertOk()->assertSee('Place student');
        $this->as(Role::REGISTRAR)->get(self::HOST.'/enrolment')->assertOk();
        $this->as(Role::REGISTRAR)->get(self::HOST.'/enrolment/promote')->assertOk();
        $this->as(Role::TEACHER)->get(self::HOST.'/enrolment')->assertOk()->assertSee('You are not a form teacher');
        $this->as(Role::STUDENT)->post(self::HOST.'/enrolment/place', [])->assertForbidden();
        $this->as(Role::TEACHER)->post(self::HOST.'/enrolment/promote', [])->assertForbidden();
    }

    public function test_list_defaults_to_current_session_and_filters_and_paginates(): void
    {
        $this->tenant(function () {
            $this->enrol($this->student('S1', 'Ada Lovelace'), $this->c['2-jss1a']);
            $this->enrol($this->student('S2', 'Bola Tinubu'), $this->c['2-jss1b']);
            $this->enrol($this->student('S3', 'Chi Left'), $this->c['2-jss1a'], 'LEFT');
            $this->enrol($this->student('S4', 'Old Session Kid'), $this->c['1-jss1a']);
            for ($i = 0; $i < 30; $i++) {
                $this->enrol($this->student('P'.$i, sprintf('Pupil %02d', $i)), $this->c['2-jss1b']);
            }
        });

        $this->as(Role::ADMIN);

        $this->get(self::HOST.'/enrolment')->assertOk()
            ->assertSee('>Ada Lovelace</a>', false)->assertDontSee('>Old Session Kid</a>', false)
            ->assertSee('33 enrolment(s) in 2026/2027'); // 2 + 1 left + 30

        // page size selector: 25 default, 50 shows all, junk falls back to 25
        $this->get(self::HOST.'/enrolment')->assertDontSee('Pupil 29');
        $this->get(self::HOST.'/enrolment?per_page=50')->assertSee('Pupil 29');
        $this->get(self::HOST.'/enrolment?per_page=7')->assertDontSee('Pupil 29');
        $this->get(self::HOST.'/enrolment?per_page=100')->assertSee('Pupil 29');

        $this->get(self::HOST.'/enrolment?class='.$this->c['2-jss1a']->id)
            ->assertSee('Ada Lovelace')->assertSee('Chi Left')->assertDontSee('Bola Tinubu');
        $this->get(self::HOST.'/enrolment?status=LEFT')->assertSee('Chi Left')->assertDontSee('Ada Lovelace');
        $this->get(self::HOST.'/enrolment?status=ACTIVE&q=bola')->assertSee('Bola Tinubu')->assertDontSee('Ada Lovelace');
        $this->get(self::HOST.'/enrolment?q=S3')->assertSee('Chi Left')->assertDontSee('Bola Tinubu');

        // other session
        $this->get(self::HOST.'/enrolment?session='.$this->s1->id)->assertSee('>Old Session Kid</a>', false)->assertDontSee('>Ada Lovelace</a>', false);
    }

    public function test_place_creates_active_enrolment_with_compulsory_subjects_only(): void
    {
        $student = $this->tenant(fn () => $this->student('S1', 'Kay Ade'));

        $this->as(Role::REGISTRAR)->get(self::HOST.'/enrolment')->assertSee('Kay Ade');

        $this->post(self::HOST.'/enrolment/place', ['student_id' => $student->id, 'class_id' => $this->c['2-jss1a']->id])
            ->assertRedirect()->assertSessionHas('success');

        $e = $this->tenant(fn () => Enrolment::where('student_id', $student->id)->firstOrFail());
        $this->assertSame('ACTIVE', $e->status);
        $this->assertSame($this->s2->id, $e->session_id);
        $this->assertSame(['Maths'], $this->subjectsOf($e));
        $this->assertSame(1, $this->tenant(fn () => AuditLog::where('entity', 'Enrolment')->where('entity_id', $e->id)->count()));

        // Placed already: no longer offered, and a second placement is refused.
        $this->get(self::HOST.'/enrolment')->assertDontSee('Kay Ade (S1)');
        $this->post(self::HOST.'/enrolment/place', ['student_id' => $student->id, 'class_id' => $this->c['2-jss1b']->id])
            ->assertSessionHas('error');
        $this->assertSame(1, $this->tenant(fn () => Enrolment::count()));
    }

    public function test_place_rejects_left_students_unknown_classes_and_other_schools(): void
    {
        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        $foreignClass = Tenant::run($other, function () {
            $session = AcademicSession::create(['name' => 'X', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);

            return SchoolClass::create(['session_id' => $session->id, 'level_id' => Level::create(['name' => 'L'])->id, 'arm_id' => Arm::create(['name' => 'Z'])->id]);
        });
        [$left, $active] = $this->tenant(fn () => [$this->student('L1', 'Gone Guy', 'LEFT'), $this->student('A1', 'Here Guy')]);

        $this->as(Role::ADMIN);
        $this->post(self::HOST.'/enrolment/place', ['student_id' => $left->id, 'class_id' => $this->c['2-jss1a']->id])->assertSessionHas('error');
        $this->post(self::HOST.'/enrolment/place', ['student_id' => $active->id, 'class_id' => $foreignClass->id])->assertSessionHas('error');
        $this->post(self::HOST.'/enrolment/place', ['student_id' => $active->id])->assertSessionHasErrors('class_id');
        $this->assertSame(0, $this->tenant(fn () => Enrolment::count()));
    }

    public function test_placing_a_student_who_left_reactivates_their_enrolment(): void
    {
        [$student, $e] = $this->tenant(function () {
            $student = $this->student('S1', 'Back Again');
            $e = $this->enrol($student, $this->c['2-jss1a'], 'LEFT');
            EnrolmentSubject::create(['enrolment_id' => $e->id, 'class_subject_id' => $this->cs($this->c['2-jss1a'], $this->art)->id]);

            return [$student, $e];
        });

        $this->as(Role::ADMIN)->post(self::HOST.'/enrolment/place', ['student_id' => $student->id, 'class_id' => $this->c['2-jss1b']->id])
            ->assertSessionHas('success');

        $this->assertSame(1, $this->tenant(fn () => Enrolment::count()));
        $e->refresh();
        $this->assertSame('ACTIVE', $e->status);
        $this->assertSame($this->c['2-jss1b']->id, $e->class_id);
        $this->assertSame(['Maths'], $this->subjectsOf($e));
    }

    public function test_move_rebuilds_subjects_keeping_electives_only_when_still_elective_in_new_class(): void
    {
        $e = $this->tenant(function () {
            $e = $this->enrol($this->student('S1', 'Mover'), $this->c['2-jss1a']);
            EnrolmentSubject::create(['enrolment_id' => $e->id, 'class_subject_id' => $this->cs($this->c['2-jss1a'], $this->art)->id]);

            return $e;
        });
        $this->assertSame(['Art', 'Maths'], $this->subjectsOf($e));

        $this->as(Role::ADMIN);

        // Same class and other session are refused.
        $this->put(self::HOST.'/enrolment/'.$e->id.'/class', ['class_id' => $this->c['2-jss1a']->id])->assertSessionHas('error');
        $this->put(self::HOST.'/enrolment/'.$e->id.'/class', ['class_id' => $this->c['1-jss1a']->id])->assertSessionHas('error');
        $this->assertSame($this->c['2-jss1a']->id, $e->fresh()->class_id);

        // JSS1 B also offers Art as elective -> kept; old class subject rows are gone.
        $this->put(self::HOST.'/enrolment/'.$e->id.'/class', ['class_id' => $this->c['2-jss1b']->id])->assertSessionHas('success');
        $e->refresh();
        $this->assertSame($this->c['2-jss1b']->id, $e->class_id);
        $this->assertSame(['Art', 'Maths'], $this->subjectsOf($e));
        $this->assertSame(0, $this->tenant(fn () => EnrolmentSubject::where('enrolment_id', $e->id)
            ->whereIn('class_subject_id', ClassSubject::where('class_id', $this->c['2-jss1a']->id)->pluck('id'))->count()));

        // JSS2 A has no Art -> dropped; Music is compulsory there -> attached.
        $this->put(self::HOST.'/enrolment/'.$e->id.'/class', ['class_id' => $this->c['2-jss2a']->id])->assertSessionHas('success');
        $this->assertSame(['Maths', 'Music'], $this->subjectsOf($e));

        $this->tenant(fn () => $e->update(['status' => 'LEFT']));
        $this->put(self::HOST.'/enrolment/'.$e->id.'/class', ['class_id' => $this->c['2-jss1a']->id])->assertSessionHas('error');
    }

    public function test_electives_can_be_added_and_removed_without_touching_compulsory(): void
    {
        $e = $this->tenant(fn () => $this->enrol($this->student('S1', 'Elect'), $this->c['2-jss1a']));
        $art = $this->tenant(fn () => $this->cs($this->c['2-jss1a'], $this->art));
        $maths = $this->tenant(fn () => $this->cs($this->c['2-jss1a'], $this->maths));
        $foreign = $this->tenant(fn () => $this->cs($this->c['2-jss1b'], $this->art));

        $this->as(Role::ADMIN)->get(self::HOST.'/enrolment?enrolment='.$e->id)->assertOk()->assertSee('Electives')->assertSee('Art');

        $this->put(self::HOST.'/enrolment/'.$e->id.'/electives', ['class_subject_ids' => [$art->id]])->assertSessionHas('success');
        $this->assertSame(['Art', 'Maths'], $this->subjectsOf($e));

        // Idempotent.
        $this->put(self::HOST.'/enrolment/'.$e->id.'/electives', ['class_subject_ids' => [$art->id]])->assertSessionHas('success');
        $this->assertSame(['Art', 'Maths'], $this->subjectsOf($e));

        // Compulsory / other-class subjects are refused.
        $this->put(self::HOST.'/enrolment/'.$e->id.'/electives', ['class_subject_ids' => [$maths->id]])->assertSessionHas('error');
        $this->put(self::HOST.'/enrolment/'.$e->id.'/electives', ['class_subject_ids' => [$foreign->id]])->assertSessionHas('error');
        $this->assertSame(['Art', 'Maths'], $this->subjectsOf($e));

        // Unchecking everything removes electives only.
        $this->put(self::HOST.'/enrolment/'.$e->id.'/electives')->assertSessionHas('success');
        $this->assertSame(['Maths'], $this->subjectsOf($e));
    }

    public function test_form_teacher_sets_electives_only_for_their_class(): void
    {
        [$ada, $mine, $other, $art] = $this->tenant(function () {
            $ada = Staff::create([
                'index_number' => 'T1',
                'first_name' => 'Ada',
                'last_name' => 'Obi',
                'is_teacher' => true,
                'status' => 'ACTIVE',
                'gender' => 'Female',
            ]);
            $this->c['2-jss1a']->update(['form_teacher_id' => $ada->id]);
            $mine = $this->enrol($this->student('S9', 'Kay Form'), $this->c['2-jss1a']);
            $other = $this->enrol($this->student('S8', 'Other Kid'), $this->c['2-jss1b']);

            return [$ada, $mine, $other, $this->cs($this->c['2-jss1a'], $this->art)];
        });

        $this->actingAs(User::factory()->create([
            'school_id' => $this->school->id,
            'role' => Role::TEACHER,
            'staff_id' => $ada->id,
        ]));

        $this->get(self::HOST.'/enrolment')
            ->assertOk()
            ->assertSee('Kay Form')
            ->assertSee('Choose elective subjects for students in your form class')
            ->assertDontSee('Other Kid')
            ->assertDontSee('Place student')
            ->assertDontSee('Promote session');

        $this->get(self::HOST.'/enrolment?enrolment='.$mine->id)
            ->assertOk()
            ->assertSee('Electives')
            ->assertSee('Art')
            ->assertDontSee('Move to another class')
            ->assertDontSee('Mark as left');

        $this->get(self::HOST.'/enrolment?enrolment='.$other->id)
            ->assertOk()
            ->assertDontSee('Other Kid');

        $this->put(self::HOST.'/enrolment/'.$mine->id.'/electives', ['class_subject_ids' => [$art->id]])
            ->assertSessionHas('success');
        $this->assertSame(['Art', 'Maths'], $this->subjectsOf($mine));

        $this->put(self::HOST.'/enrolment/'.$other->id.'/electives', ['class_subject_ids' => [$art->id]])
            ->assertForbidden();
        $this->post(self::HOST.'/enrolment/place', [])->assertForbidden();
    }

    public function test_promote_creates_active_enrolments_marks_sources_left_and_maps_classes(): void
    {
        [$a, $b, $c, $eLeft] = $this->tenant(function () {
            $a = $this->enrol($this->student('S1', 'Auto Match'), $this->c['1-jss1a']);
            EnrolmentSubject::create(['enrolment_id' => $a->id, 'class_subject_id' => $this->cs($this->c['1-jss1a'], $this->art)->id]);
            EnrolmentSubject::create(['enrolment_id' => $a->id, 'class_subject_id' => $this->cs($this->c['1-jss1a'], $this->music)->id]);

            $b = $this->enrol($this->student('S2', 'Second Kid'), $this->c['1-jss1a']);
            $c = $this->enrol($this->student('S3', 'Placed Already'), $this->c['1-jss1a']);
            $eLeft = $this->enrol($this->student('S5', 'Already Left'), $this->c['1-jss1a'], 'LEFT');
            $this->enrol($c->student, $this->c['2-jss1b']);

            return [$a, $b, $c, $eLeft];
        });

        $this->as(Role::REGISTRAR);

        // Mapping screen lists the source class.
        $this->get(self::HOST.'/enrolment/promote?from='.$this->s1->id.'&to='.$this->s2->id)
            ->assertOk()->assertSee('JSS1 A')->assertSee('Promote students');

        // Auto mapping (no mapping posted): JSS1 A -> JSS1 A.
        $this->post(self::HOST.'/enrolment/promote', ['from_session_id' => $this->s1->id, 'to_session_id' => $this->s2->id])
            ->assertRedirect(route('enrolment', ['session' => $this->s2->id], false))
            ->assertSessionHas('success')->assertSessionHas('promotion_skipped');

        $this->tenant(function () use ($a, $b, $c, $eLeft) {
            $next = Enrolment::where('student_id', $a->student_id)->where('session_id', $this->s2->id)->firstOrFail();
            $this->assertSame('ACTIVE', $next->status);
            $this->assertSame($this->c['2-jss1a']->id, $next->class_id);
            $this->assertSame('LEFT', $a->fresh()->status);
            $this->assertSame('LEFT', $b->fresh()->status);

            // Art stays elective -> kept; Music not offered in JSS1 A -> dropped.
            $this->assertSame(['Art', 'Maths'], $this->subjectsOf($next));

            // Already placed in target: left alone and still ACTIVE in the source.
            $this->assertSame('ACTIVE', $c->fresh()->status);
            $this->assertSame(1, Enrolment::where('student_id', $c->student_id)->where('session_id', $this->s2->id)->count());
            $this->assertSame('LEFT', $eLeft->fresh()->status);
            $this->assertNull(Enrolment::where('student_id', $eLeft->student_id)->where('session_id', $this->s2->id)->first());
            $this->assertSame(1, AuditLog::where('action', 'promote')->count());
        });
    }

    public function test_promote_honours_explicit_mapping_and_skip(): void
    {
        $e = $this->tenant(function () {
            $e = $this->enrol($this->student('S1', 'Jumper'), $this->c['1-jss1a']);
            EnrolmentSubject::create(['enrolment_id' => $e->id, 'class_subject_id' => $this->cs($this->c['1-jss1a'], $this->music)->id]);

            return $e;
        });
        $src = $this->c['1-jss1a']->id;

        $this->as(Role::ADMIN);

        // Mapped to '' -> skipped, source stays ACTIVE.
        $this->post(self::HOST.'/enrolment/promote', ['from_session_id' => $this->s1->id, 'to_session_id' => $this->s2->id, 'mapping' => [$src => '']])
            ->assertSessionHas('promotion_skipped');
        $this->assertSame('ACTIVE', $e->fresh()->status);
        $this->assertSame(0, $this->tenant(fn () => Enrolment::where('session_id', $this->s2->id)->count()));

        // Mapped to JSS2 A: Music was an elective, now compulsory there -> attached once.
        $this->post(self::HOST.'/enrolment/promote', ['from_session_id' => $this->s1->id, 'to_session_id' => $this->s2->id, 'mapping' => [$src => $this->c['2-jss2a']->id]])
            ->assertSessionMissing('promotion_skipped')->assertSessionHas('success');
        $next = $this->tenant(fn () => Enrolment::where('session_id', $this->s2->id)->firstOrFail());
        $this->assertSame($this->c['2-jss2a']->id, $next->class_id);
        $this->assertSame(['Maths', 'Music'], $this->subjectsOf($next));
        $this->assertSame('LEFT', $e->fresh()->status);
    }

    public function test_promote_validates_sessions_and_target_classes(): void
    {
        $this->tenant(fn () => $this->enrol($this->student('S1', 'Valid'), $this->c['1-jss1a']));
        $this->as(Role::ADMIN);

        $this->post(self::HOST.'/enrolment/promote', ['from_session_id' => $this->s1->id, 'to_session_id' => $this->s1->id])
            ->assertSessionHasErrors('to_session_id');

        // Target class from the wrong session.
        $this->post(self::HOST.'/enrolment/promote', [
            'from_session_id' => $this->s1->id, 'to_session_id' => $this->s2->id,
            'mapping' => [$this->c['1-jss1a']->id => $this->c['1-jss1a']->id],
        ])->assertSessionHas('error');
        $this->assertSame(0, $this->tenant(fn () => Enrolment::where('session_id', $this->s2->id)->count()));

        $this->post(self::HOST.'/enrolment/promote', ['from_session_id' => 'nope', 'to_session_id' => $this->s2->id])->assertSessionHas('error');
    }

    public function test_mark_left(): void
    {
        $e = $this->tenant(fn () => $this->enrol($this->student('S1', 'Leaver'), $this->c['2-jss1a']));

        $this->as(Role::ADMIN)->post(self::HOST.'/enrolment/'.$e->id.'/leave')->assertSessionHas('success');
        $this->assertSame('LEFT', $e->fresh()->status);
        $this->post(self::HOST.'/enrolment/'.$e->id.'/leave')->assertSessionHas('error');
        $this->post(self::HOST.'/enrolment/missing/leave')->assertSessionHas('error');
    }
}
