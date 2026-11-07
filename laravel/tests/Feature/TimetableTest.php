<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\Level;
use App\Models\Period;
use App\Models\Room;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimetableTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://tt.localhost';

    private School $school;

    private AcademicSession $session;

    private SchoolClass $class;

    private SchoolClass $otherClass;

    private ClassSubject $maths;

    private ClassSubject $english;

    private ClassSubject $otherMaths;

    private Staff $teacher;

    private Staff $otherTeacher;

    private Room $room;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->school = School::create(['name' => 'TT School', 'slug' => 'tt', 'subdomain' => 'tt']);

        Tenant::run($this->school, function () {
            $this->session = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $level = Level::create(['name' => 'JSS1']);
            $this->class = SchoolClass::create(['session_id' => $this->session->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
            $this->otherClass = SchoolClass::create(['session_id' => $this->session->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);

            $this->teacher = $this->makeTeacher('T1', 'Ada');
            $this->otherTeacher = $this->makeTeacher('T2', 'Bola');

            $maths = Subject::create(['name' => 'Maths']);
            $english = Subject::create(['name' => 'English']);
            $this->maths = ClassSubject::create(['class_id' => $this->class->id, 'subject_id' => $maths->id, 'teacher_id' => $this->teacher->id, 'kind' => 'COMPULSORY']);
            $this->english = ClassSubject::create(['class_id' => $this->class->id, 'subject_id' => $english->id, 'teacher_id' => $this->otherTeacher->id, 'kind' => 'COMPULSORY']);
            $this->otherMaths = ClassSubject::create(['class_id' => $this->otherClass->id, 'subject_id' => $maths->id, 'teacher_id' => $this->teacher->id, 'kind' => 'COMPULSORY']);

            $this->room = Room::create(['name' => 'R1', 'capacity' => 30]);
            $this->student = Student::create(['index_number' => 'S1', 'full_name' => 'Kay Ade', 'gender' => 'Male', 'status' => 'ACTIVE']);
            Enrolment::create(['student_id' => $this->student->id, 'class_id' => $this->class->id, 'session_id' => $this->session->id, 'status' => 'ACTIVE']);
        });
    }

    private function makeTeacher(string $index, string $first): Staff
    {
        return Staff::create(['index_number' => $index, 'first_name' => $first, 'last_name' => 'Obi', 'is_teacher' => true, 'status' => 'ACTIVE', 'gender' => 'Female']);
    }

    private function as(Role $role, array $attrs = []): static
    {
        return $this->actingAs(User::factory()->create(['school_id' => $this->school->id, 'role' => $role] + $attrs));
    }

    private function periodData(array $over = []): array
    {
        return $over + [
            'class_subject_id' => $this->maths->id,
            'day' => 'MONDAY',
            'start_time' => '08:00',
            'end_time' => '08:40',
            'room_id' => '',
        ];
    }

    private function periods()
    {
        return Tenant::run($this->school, fn () => Period::query()->get());
    }

    public function test_admin_sees_weekly_grid_with_real_times(): void
    {
        Tenant::run($this->school, function () {
            Period::create(['class_subject_id' => $this->maths->id, 'room_id' => $this->room->id, 'day' => 'MONDAY', 'start_time' => '08:00', 'end_time' => '08:40']);
            Period::create(['class_subject_id' => $this->english->id, 'day' => 'WEDNESDAY', 'start_time' => '09:15', 'end_time' => '10:00']);
        });

        $this->as(Role::ADMIN)->get(self::HOST.'/timetable?class='.$this->class->id)
            ->assertOk()
            ->assertSeeInOrder(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'])
            ->assertSee('08:00 – 08:40', false)
            ->assertSee('09:15 – 10:00', false)
            ->assertSee('Maths')
            ->assertSee('English')
            ->assertSee('Room R1')
            ->assertSee('Add period');
    }

    public function test_create_edit_and_delete_period(): void
    {
        $this->as(Role::REGISTRAR)->post(self::HOST.'/timetable/periods', $this->periodData(['room_id' => $this->room->id]))
            ->assertRedirect(self::HOST.'/timetable?session='.$this->session->id.'&class='.$this->class->id);

        $period = $this->periods()->sole();
        $this->assertSame('MONDAY', $period->day);
        $this->assertSame('08:00', $period->start_time);
        $this->assertSame($this->room->id, $period->room_id);

        $this->as(Role::ADMIN)->put(self::HOST.'/timetable/periods/'.$period->id, $this->periodData([
            'class_subject_id' => $this->english->id, 'day' => 'TUESDAY', 'start_time' => '09:05', 'end_time' => '09:45', 'room_id' => '',
        ]))->assertRedirect()->assertSessionHas('success');

        $period->refresh();
        $this->assertSame('TUESDAY', $period->day);
        $this->assertSame('09:05', $period->start_time);
        $this->assertSame($this->english->id, $period->class_subject_id);
        $this->assertNull($period->room_id);

        $this->as(Role::ADMIN)->delete(self::HOST.'/timetable/periods/'.$period->id)->assertRedirect();
        $this->assertCount(0, $this->periods());
    }

    public function test_rejects_invalid_and_clashing_periods(): void
    {
        $admin = $this->as(Role::ADMIN);

        // End before start
        $admin->post(self::HOST.'/timetable/periods', $this->periodData(['start_time' => '09:00', 'end_time' => '08:00']))
            ->assertSessionHas('error');
        // Malformed time / weekend day
        $admin->post(self::HOST.'/timetable/periods', $this->periodData(['start_time' => '8am']))->assertSessionHasErrors('start_time');
        $admin->post(self::HOST.'/timetable/periods', $this->periodData(['day' => 'SATURDAY']))->assertSessionHasErrors('day');
        $this->assertCount(0, $this->periods());

        $admin->post(self::HOST.'/timetable/periods', $this->periodData(['room_id' => $this->room->id]))->assertSessionHas('success');

        // Same class overlapping
        $admin->post(self::HOST.'/timetable/periods', $this->periodData(['class_subject_id' => $this->english->id, 'start_time' => '08:30', 'end_time' => '09:00']))
            ->assertSessionHas('error', 'That class already has a period at this time.');
        // Same teacher in another class
        $admin->post(self::HOST.'/timetable/periods', $this->periodData(['class_subject_id' => $this->otherMaths->id, 'start_time' => '08:30', 'end_time' => '09:00']))
            ->assertSessionHas('error', 'That teacher already has a period at this time.');
        // Same room
        Tenant::run($this->school, fn () => $this->otherMaths->update(['teacher_id' => $this->otherTeacher->id]));
        $admin->post(self::HOST.'/timetable/periods', $this->periodData(['class_subject_id' => $this->otherMaths->id, 'room_id' => $this->room->id]))
            ->assertSessionHas('error', 'That room is already in use at this time.');
        // Back-to-back is fine
        $admin->post(self::HOST.'/timetable/periods', $this->periodData(['class_subject_id' => $this->english->id, 'start_time' => '08:40', 'end_time' => '09:20']))
            ->assertSessionHas('success');

        $this->assertCount(2, $this->periods());

        // Editing a period does not clash with itself.
        $first = $this->periods()->firstWhere('start_time', '08:00');
        $admin->put(self::HOST.'/timetable/periods/'.$first->id, $this->periodData(['end_time' => '08:35', 'room_id' => $this->room->id]))
            ->assertSessionHas('success');
    }

    public function test_teachers_see_their_periods_but_cannot_manage(): void
    {
        Tenant::run($this->school, function () {
            Period::create(['class_subject_id' => $this->maths->id, 'day' => 'MONDAY', 'start_time' => '08:00', 'end_time' => '08:40']);
            Period::create(['class_subject_id' => $this->otherMaths->id, 'day' => 'TUESDAY', 'start_time' => '10:00', 'end_time' => '10:40']);
            Period::create(['class_subject_id' => $this->english->id, 'day' => 'FRIDAY', 'start_time' => '11:00', 'end_time' => '11:40']);
        });

        $teacher = $this->as(Role::TEACHER, ['staff_id' => $this->teacher->id]);

        $teacher->get(self::HOST.'/timetable')
            ->assertOk()
            ->assertSee('My periods')
            ->assertSee('08:00 – 08:40', false)
            ->assertSee('10:00 – 10:40', false)
            ->assertDontSee('11:00 – 11:40', false)
            ->assertDontSee('Add period');

        // Can still browse a class timetable, read-only.
        $teacher->get(self::HOST.'/timetable?class='.$this->class->id)
            ->assertOk()->assertSee('11:00 – 11:40', false)->assertDontSee('Add period')->assertDontSee('Delete');

        $teacher->post(self::HOST.'/timetable/periods', $this->periodData())->assertForbidden();
        $period = $this->periods()->first();
        $teacher->put(self::HOST.'/timetable/periods/'.$period->id, $this->periodData())->assertForbidden();
        $teacher->delete(self::HOST.'/timetable/periods/'.$period->id)->assertForbidden();
    }

    public function test_form_teacher_sees_periods_of_their_class(): void
    {
        Tenant::run($this->school, function () {
            $this->class->update(['form_teacher_id' => $this->teacher->id]);
            Period::create(['class_subject_id' => $this->english->id, 'day' => 'FRIDAY', 'start_time' => '11:00', 'end_time' => '11:40']);
        });

        $this->as(Role::TEACHER, ['staff_id' => $this->teacher->id])->get(self::HOST.'/timetable?class=mine')
            ->assertOk()->assertSee('11:00 – 11:40', false);
    }

    public function test_students_only_see_their_own_class(): void
    {
        Tenant::run($this->school, function () {
            Period::create(['class_subject_id' => $this->maths->id, 'day' => 'MONDAY', 'start_time' => '08:00', 'end_time' => '08:40']);
            Period::create(['class_subject_id' => $this->otherMaths->id, 'day' => 'TUESDAY', 'start_time' => '10:00', 'end_time' => '10:40']);
        });

        $this->as(Role::STUDENT, ['student_id' => $this->student->id])
            ->get(self::HOST.'/timetable?class='.$this->otherClass->id)
            ->assertOk()
            ->assertSee('08:00 – 08:40', false)
            ->assertDontSee('10:00 – 10:40', false)
            ->assertDontSee('Add period');

        $this->as(Role::ACCOUNTANT)->get(self::HOST.'/timetable')->assertForbidden();
    }

    public function test_navigation_links_are_enabled(): void
    {
        $this->as(Role::ADMIN)->get(self::HOST.'/dashboard')
            ->assertOk()
            ->assertSee(route('timetable'), false)
            ->assertSee(route('attendance'), false);
    }

    public function test_school_day_fills_a_class_from_subject_lists_and_keeps_friday_separate(): void
    {
        $admin = $this->as(Role::ADMIN);

        $admin->get(self::HOST.'/timetable?class='.$this->class->id)
            ->assertOk()
            ->assertSee('Save school day')
            ->assertSee('Friday has different times');

        $admin->post(self::HOST.'/timetable/structure', [
            'session' => $this->session->id,
            'class' => $this->class->id,
            'start' => '08:00',
            'period_minutes' => 40,
            'periods' => 6,
            'breaks' => [
                ['after' => 3, 'minutes' => 20, 'label' => 'Short break'],
                ['after' => 5, 'minutes' => 30, 'label' => 'Long break'],
            ],
            'friday_different' => '1',
            'friday_start' => '08:00',
            'friday_period_minutes' => 40,
            'friday_periods' => 4,
            'friday_breaks' => [
                ['after' => 2, 'minutes' => 15, 'label' => 'Break'],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $slots = Tenant::run($this->school, fn () => TimetableSlot::query()->orderBy('sequence')->get());
        $week = $slots->where('day_group', 'WEEK')->values();
        $friday = $slots->where('day_group', 'FRIDAY')->values();
        $this->assertSame(['Period 1', 'Period 2', 'Period 3', 'Short break', 'Period 4', 'Period 5', 'Long break', 'Period 6'], $week->pluck('label')->all());
        $this->assertSame('08:00', $week[0]->start_time);
        $this->assertSame('08:40', $week[0]->end_time);
        $this->assertSame('10:00', $week[3]->start_time);
        $this->assertSame('12:10', $week[7]->start_time);
        $this->assertSame('12:50', $week[7]->end_time);
        $this->assertSame(['Period 1', 'Period 2', 'Break', 'Period 3', 'Period 4'], $friday->pluck('label')->all());

        $period = $week->firstWhere('label', 'Period 1');
        $admin->get(self::HOST.'/timetable?class='.$this->class->id)
            ->assertOk()
            ->assertSee('Monday – Thursday')
            ->assertSee('Edit timetable')
            ->assertSee('Maths — Ada Obi', false)
            ->assertSee('name="assignments['.$period->id.'][MONDAY]"', false)
            ->assertDontSee('name="assignments['.$period->id.'][FRIDAY]"', false);

        $admin->post(self::HOST.'/timetable/assignments', [
            'session' => $this->session->id,
            'class_id' => $this->class->id,
            'assignments' => [
                $period->id => ['MONDAY' => $this->maths->id, 'WEDNESDAY' => $this->english->id],
                $friday->firstWhere('label', 'Period 1')->id => ['FRIDAY' => $this->english->id],
            ],
        ])->assertSessionHas('success');

        $this->assertSame(3, $this->periods()->count());
        $monday = $this->periods()->firstWhere('day', 'MONDAY');
        $this->assertSame($this->maths->id, $monday->class_subject_id);
        $this->assertSame('08:00', $monday->start_time);
        $this->assertSame('08:40', $monday->end_time);

        // Same teacher cannot take the same period in another class.
        $admin->post(self::HOST.'/timetable/assignments', [
            'class_id' => $this->otherClass->id,
            'assignments' => [$period->id => ['MONDAY' => $this->otherMaths->id]],
        ])->assertSessionHas('error');
        $this->assertSame(0, $this->periods()->where('class_subject_id', $this->otherMaths->id)->count());

        // Clearing a cell removes the period. The student stays in this class.
        $admin->post(self::HOST.'/timetable/assignments', [
            'class_id' => $this->class->id,
            'assignments' => [$period->id => ['MONDAY' => '']],
        ])->assertSessionHas('success');
        $this->assertNull($this->periods()->firstWhere('day', 'MONDAY'));

        $this->as(Role::TEACHER, ['staff_id' => $this->teacher->id])
            ->post(self::HOST.'/timetable/structure', ['start' => '08:00', 'period_minutes' => 40, 'periods' => 6])
            ->assertForbidden();
    }

    public function test_other_schools_periods_are_not_reachable(): void
    {
        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        $foreign = Tenant::run($other, function () {
            $session = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $class = SchoolClass::create(['session_id' => $session->id, 'level_id' => Level::create(['name' => 'JSS1'])->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
            $cs = ClassSubject::create(['class_id' => $class->id, 'subject_id' => Subject::create(['name' => 'Maths'])->id, 'kind' => 'COMPULSORY']);

            return Period::create(['class_subject_id' => $cs->id, 'day' => 'MONDAY', 'start_time' => '08:00', 'end_time' => '08:40']);
        });

        $admin = $this->as(Role::ADMIN);
        $admin->delete(self::HOST.'/timetable/periods/'.$foreign->id)->assertSessionHas('error', 'Period not found.');
        $admin->post(self::HOST.'/timetable/periods', $this->periodData(['class_subject_id' => $foreign->class_subject_id]))
            ->assertSessionHas('error', 'Subject offering not found.');
        $this->assertSame(1, Period::withoutGlobalScopes()->count());
    }
}
