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
use App\Models\Guardian;
use App\Models\Level;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\SubjectScore;
use App\Models\Term;
use App\Models\TermReport;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://exam.localhost';

    private School $school;

    private Term $term;

    private SchoolClass $class;

    private SchoolClass $otherClass;

    private Staff $maths;

    private Staff $english;

    private ClassSubject $mathsCs;

    private ClassSubject $englishCs;

    private ClassSubject $artCs;

    private ClassSubject $otherMathsCs;

    private Enrolment $kay;

    private Enrolment $zed;

    private Enrolment $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->school = School::create(['name' => 'Exam School', 'slug' => 'exam', 'subdomain' => 'exam']);

        Tenant::run($this->school, function () {
            $session = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $this->term = Term::create(['session_id' => $session->id, 'sequence' => 1, 'name' => 'First Term', 'days_school_open' => 60]);

            $scale = GradingScale::create(['name' => 'Default', 'is_default' => true]);
            foreach ([[70, 100, 'A', 'Excellent'], [50, 69, 'C', 'Good'], [0, 49, 'F', 'Failed']] as [$min, $max, $letter, $remark]) {
                GradingBand::create(['scale_id' => $scale->id, 'min_score' => $min, 'max_score' => $max, 'letter' => $letter, 'remark' => $remark]);
            }

            $level = Level::create(['name' => 'JSS1', 'sort_order' => 1]);
            $this->maths = $this->teacher('T1', 'Ada');
            $this->english = $this->teacher('T2', 'Bola');

            $this->class = SchoolClass::create(['session_id' => $session->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
            $this->otherClass = SchoolClass::create(['session_id' => $session->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);

            $this->mathsCs = $this->offer($this->class, 'Mathematics', $this->maths, 'COMPULSORY');
            $this->englishCs = $this->offer($this->class, 'English', $this->english, 'COMPULSORY');
            $this->artCs = $this->offer($this->class, 'Art', null, 'ELECTIVE');
            $this->otherMathsCs = $this->offer($this->otherClass, 'Physics', $this->maths, 'COMPULSORY');

            $this->kay = $this->enrol($this->class, $session, 'S1', 'Kay Ade');
            $this->zed = $this->enrol($this->class, $session, 'S2', 'Zed Bello');
            $this->other = $this->enrol($this->otherClass, $session, 'S3', 'Other Kid');
        });
    }

    private function teacher(string $index, string $first): Staff
    {
        return Staff::create(['index_number' => $index, 'first_name' => $first, 'last_name' => 'Obi', 'is_teacher' => true, 'status' => 'ACTIVE']);
    }

    private function offer(SchoolClass $class, string $subject, ?Staff $teacher, string $kind): ClassSubject
    {
        return ClassSubject::create([
            'class_id' => $class->id,
            'subject_id' => Subject::create(['name' => $subject])->id,
            'teacher_id' => $teacher?->id,
            'kind' => $kind,
        ]);
    }

    private function enrol(SchoolClass $class, AcademicSession $session, string $index, string $name): Enrolment
    {
        $student = Student::create(['index_number' => $index, 'full_name' => $name, 'status' => 'ACTIVE']);

        return Enrolment::create(['student_id' => $student->id, 'class_id' => $class->id, 'session_id' => $session->id, 'status' => 'ACTIVE']);
    }

    private function as(Role $role, array $attrs = []): static
    {
        return $this->actingAs(User::factory()->create(['school_id' => $this->school->id, 'role' => $role] + $attrs));
    }

    private function scores()
    {
        return Tenant::run($this->school, fn () => SubjectScore::query()->get());
    }

    private function save(array $scores)
    {
        return $this->post(self::HOST.'/exams/scores', ['term_id' => $this->term->id, 'scores' => $scores]);
    }

    private function row(Enrolment $e, ClassSubject $cs, array $marks): array
    {
        return [$e->id => [$cs->id => $marks]];
    }

    // ------------------------------------------------------------- Mark sheet

    public function test_office_marksheet_lists_students_and_saves_upserting_scores(): void
    {
        $this->as(Role::REGISTRAR);

        $this->get(self::HOST.'/exams?view=marksheet&class='.$this->class->id.'&subject='.$this->mathsCs->id)
            ->assertOk()
            ->assertSee('Kay Ade')
            ->assertSee('Zed Bello')
            ->assertDontSee('Other Kid')
            ->assertSee('Save scores');

        $this->save($this->row($this->kay, $this->mathsCs, ['ca1' => 8, 'ca2' => 9, 'ca3' => 10, 'exam' => 50]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $scores = $this->scores();
        $this->assertCount(1, $scores);
        $this->assertSame('77.00', $scores->first()->fresh()->total); // generated column

        // Re-saving the same cell updates instead of duplicating; blank rows are skipped.
        $this->save([
            $this->kay->id => [$this->mathsCs->id => ['ca1' => 5, 'ca2' => 5, 'ca3' => 5, 'exam' => 40]],
            $this->zed->id => [$this->mathsCs->id => ['ca1' => '', 'ca2' => '', 'ca3' => '', 'exam' => '']],
        ])->assertSessionHas('success');

        $scores = $this->scores();
        $this->assertCount(1, $scores);
        $this->assertSame('55.00', $scores->first()->fresh()->total);

        // Totals and grades are shown back (55 -> C).
        $this->get(self::HOST.'/exams?view=marksheet&class='.$this->class->id.'&subject='.$this->mathsCs->id)
            ->assertOk()
            ->assertSeeInOrder(['Kay Ade', '55', 'C'])
            ->assertSee('value="40"', false);
    }

    public function test_marksheet_can_pick_one_student_across_all_subjects(): void
    {
        $this->as(Role::ADMIN);

        $this->get(self::HOST.'/exams?view=marksheet&class='.$this->class->id.'&student='.$this->kay->id)
            ->assertOk()
            ->assertSee('Mathematics')
            ->assertSee('English')
            ->assertSee('Art');

        $this->save([$this->kay->id => [
            $this->mathsCs->id => ['ca1' => 10, 'ca2' => 10, 'ca3' => 10, 'exam' => 40],
            $this->englishCs->id => ['ca1' => 5, 'ca2' => 5, 'ca3' => 5, 'exam' => 30],
            $this->artCs->id => ['ca1' => '', 'ca2' => '', 'ca3' => '', 'exam' => ''],
        ]])->assertSessionHas('success');

        $this->assertCount(2, $this->scores());
    }

    public function test_marks_are_validated_and_nothing_is_saved_on_error(): void
    {
        $this->as(Role::ADMIN);

        // CA above its ceiling.
        $this->save($this->row($this->kay, $this->mathsCs, ['ca1' => 21]))->assertSessionHas('error');
        // Exam above its ceiling.
        $this->save($this->row($this->kay, $this->mathsCs, ['exam' => 71]))->assertSessionHas('error');
        // Negative, and not a number.
        $this->save($this->row($this->kay, $this->mathsCs, ['ca1' => -1]))->assertSessionHas('error');
        $this->save($this->row($this->kay, $this->mathsCs, ['ca1' => 'abc']))->assertSessionHas('error');
        // Decimals rejected — marks are whole numbers only.
        $this->save($this->row($this->kay, $this->mathsCs, ['ca1' => 7.5]))->assertSessionHas('error');
        $this->save($this->row($this->kay, $this->mathsCs, ['exam' => 40.25]))->assertSessionHas('error');
        // Total above 100.
        $this->save($this->row($this->kay, $this->mathsCs, ['ca1' => 20, 'ca2' => 20, 'ca3' => 20, 'exam' => 70]))->assertSessionHas('error');
        // Student not in the subject's class.
        $this->save($this->row($this->other, $this->mathsCs, ['exam' => 50]))->assertSessionHas('error');

        // One bad row rejects the whole batch.
        $this->save([
            $this->kay->id => [$this->mathsCs->id => ['exam' => 50]],
            $this->zed->id => [$this->mathsCs->id => ['exam' => 99]],
        ])->assertSessionHas('error');

        $this->assertCount(0, $this->scores());
    }

    public function test_absent_scores_are_stored_as_zero(): void
    {
        $this->as(Role::ADMIN);
        $this->save($this->row($this->kay, $this->mathsCs, ['ca1' => 9, 'exam' => 30, 'absent' => '1']))->assertSessionHas('success');

        $score = $this->scores()->first()->fresh();
        $this->assertTrue($score->absent);
        $this->assertSame('0.00', $score->total);
    }

    // ---------------------------------------------------------------- Teachers

    public function test_teacher_only_sees_and_saves_subjects_they_teach(): void
    {
        $this->as(Role::TEACHER, ['staff_id' => $this->maths->id]);

        // Class list is only classes they teach (JSS1 A + JSS1 B); English is not theirs.
        $this->get(self::HOST.'/exams')
            ->assertOk()
            ->assertSee('Mark sheet')
            ->assertSee('JSS1 A')
            ->assertSee('JSS1 B')
            ->assertSee('Mathematics')
            ->assertDontSee('English')
            ->assertSee('Kay Ade')
            ->assertSee('Zed Bello');

        // Switching class shows that class's taught subject (Physics), not the other class's students.
        $this->get(self::HOST.'/exams?view=subject&class='.$this->otherClass->id)
            ->assertOk()
            ->assertSee('Physics')
            ->assertSee('Other Kid')
            ->assertDontSee('Kay Ade');

        // Allowed: own subject.
        $this->save($this->row($this->kay, $this->mathsCs, ['ca1' => 7, 'ca2' => 7, 'ca3' => 7, 'exam' => 40]))
            ->assertSessionHas('success');
        $this->assertCount(1, $this->scores());

        // Forbidden: someone else's subject, even mixed with an allowed one; nothing is written.
        $this->save([$this->kay->id => [
            $this->mathsCs->id => ['exam' => 10],
            $this->englishCs->id => ['exam' => 10],
        ]])->assertForbidden();

        // Forbidden: unassigned subject.
        $this->save($this->row($this->kay, $this->artCs, ['exam' => 10]))->assertForbidden();

        $scores = $this->scores();
        $this->assertCount(1, $scores);
        $this->assertSame($this->mathsCs->id, $scores->first()->class_subject_id);
        $this->assertSame('40.00', (string) $scores->first()->exam_score);

        // Cannot open another teacher's subject tab either.
        $this->get(self::HOST.'/exams?view=subject&subject='.$this->englishCs->id)->assertForbidden();
    }

    public function test_teacher_elective_sheet_lists_only_registered_students(): void
    {
        Tenant::run($this->school, function () {
            $this->artCs->update(['teacher_id' => $this->maths->id]);
            EnrolmentSubject::create(['enrolment_id' => $this->kay->id, 'class_subject_id' => $this->artCs->id]);
        });

        $this->as(Role::TEACHER, ['staff_id' => $this->maths->id]);

        $this->get(self::HOST.'/exams?view=subject&class='.$this->class->id.'&subject='.$this->artCs->id)
            ->assertOk()
            ->assertSee('Art')
            ->assertSee('Kay Ade')
            ->assertDontSee('Zed Bello')
            ->assertSee('Elective');
    }

    public function test_teacher_cannot_use_office_views_or_edit_attendance_days(): void
    {
        $this->as(Role::TEACHER, ['staff_id' => $this->english->id]);

        // Subject teachers get Mark sheet + Broadsheet only — not Report card.
        $this->get(self::HOST.'/exams')
            ->assertOk()
            ->assertSee('Mark sheet')
            ->assertSee('Broadsheet')
            ->assertDontSee('Report card');

        // Asking for the office mark sheet falls back to the teacher Mark sheet (English only).
        $this->get(self::HOST.'/exams?view=marksheet&class='.$this->class->id)
            ->assertOk()
            ->assertSee('English')
            ->assertDontSee('Mathematics')
            ->assertDontSee('All students (one subject)');

        $this->followingRedirects()
            ->get(self::HOST.'/exams?view=report&class='.$this->class->id)
            ->assertOk()
            ->assertDontSee('Report card');
        $this->put(self::HOST.'/exams/report', [
            'term_id' => $this->term->id,
            'enrolment_id' => $this->kay->id,
            'days_present' => 10,
            'days_absent' => 1,
        ])->assertForbidden();

        $this->assertSame(0, Tenant::run($this->school, fn () => TermReport::query()->count()));
    }

    public function test_subject_teacher_broadsheet_shows_only_their_subjects(): void
    {
        $this->seedScores();
        $this->as(Role::TEACHER, ['staff_id' => $this->maths->id]);

        $this->get(self::HOST.'/exams?view=broadsheet&class='.$this->class->id)
            ->assertOk()
            ->assertSee('Mathematics')
            ->assertDontSee('English')
            ->assertSee('Showing only subjects you teach')
            ->assertSee('Kay Ade');
    }

    public function test_form_teacher_sees_full_broadsheet_and_report_card(): void
    {
        $this->seedScores();
        Tenant::run($this->school, fn () => $this->class->update(['form_teacher_id' => $this->english->id]));

        $this->as(Role::TEACHER, ['staff_id' => $this->english->id]);

        $this->get(self::HOST.'/exams')
            ->assertOk()
            ->assertSee('Mark sheet')
            ->assertSee('Broadsheet')
            ->assertSee('Report card');

        $this->get(self::HOST.'/exams?view=broadsheet&class='.$this->class->id)
            ->assertOk()
            ->assertSee('Mathematics')
            ->assertSee('English')
            ->assertDontSee('Showing only subjects you teach');

        $this->get(self::HOST.'/exams?view=report&class='.$this->class->id.'&enrolment='.$this->kay->id)
            ->assertOk()
            ->assertSee('Kay Ade')
            ->assertSee('Mathematics')
            ->assertSee('English');

        // Form teacher of JSS1 A cannot open report cards for another class they only teach in.
        $this->get(self::HOST.'/exams?view=report&class='.$this->otherClass->id)->assertForbidden();
    }

    public function test_report_tab_is_hidden_unless_the_teacher_form_teaches_the_selected_class(): void
    {
        Tenant::run($this->school, fn () => $this->class->update(['form_teacher_id' => $this->maths->id]));
        $this->as(Role::TEACHER, ['staff_id' => $this->maths->id]);

        $this->get(self::HOST.'/exams?class='.$this->class->id)
            ->assertOk()
            ->assertSee('Report card');

        $this->get(self::HOST.'/exams?class='.$this->otherClass->id)
            ->assertOk()
            ->assertDontSee('Report card');

        $this->followingRedirects()
            ->get(self::HOST.'/exams?view=report&class='.$this->otherClass->id)
            ->assertOk()
            ->assertDontSee('Report card')
            ->assertSee('Broadsheet');
    }

    public function test_teacher_without_a_staff_link_cannot_save(): void
    {
        $this->as(Role::TEACHER);
        $this->save($this->row($this->kay, $this->mathsCs, ['exam' => 10]))->assertForbidden();
        $this->assertCount(0, $this->scores());
    }

    public function test_third_term_report_shows_the_session_and_ranks_on_that_average(): void
    {
        $third = Tenant::run($this->school, function () {
            $sessionId = $this->term->session_id;
            Term::create(['session_id' => $sessionId, 'sequence' => 2, 'name' => 'Second Term', 'days_school_open' => 60]);
            $third = Term::create(['session_id' => $sessionId, 'sequence' => 3, 'name' => 'Third Term', 'days_school_open' => 60]);

            // Kay Mathematics: 60, then an absent second term, then 90. Session average 75.
            SubjectScore::create(['enrolment_id' => $this->kay->id, 'class_subject_id' => $this->mathsCs->id, 'term_id' => $this->term->id, 'ca1' => 10, 'ca2' => 10, 'ca3' => 10, 'exam_score' => 30]);
            SubjectScore::create(['enrolment_id' => $this->kay->id, 'class_subject_id' => $this->mathsCs->id, 'term_id' => Term::query()->where('sequence', 2)->value('id'), 'ca1' => 10, 'ca2' => 10, 'ca3' => 10, 'exam_score' => 50, 'absent' => true]);
            SubjectScore::create(['enrolment_id' => $this->kay->id, 'class_subject_id' => $this->mathsCs->id, 'term_id' => $third->id, 'ca1' => 10, 'ca2' => 10, 'ca3' => 10, 'exam_score' => 60]);
            // Zed scores only the third term, and higher, so Kay is second.
            SubjectScore::create(['enrolment_id' => $this->zed->id, 'class_subject_id' => $this->mathsCs->id, 'term_id' => $third->id, 'ca1' => 10, 'ca2' => 10, 'ca3' => 10, 'exam_score' => 70]);

            return $third;
        });

        $this->as(Role::ADMIN);

        $this->get(self::HOST.'/exams?view=report&class='.$this->class->id.'&term='.$this->term->id.'&enrolment='.$this->kay->id)
            ->assertOk()
            ->assertDontSee('Cumulative percentage')
            ->assertDontSee('Across the session')
            ->assertDontSee('>1st<', false);

        // Second term was absent, so the average is (60 + 90) / 2 = 75, grade A. Position 2 of 2.
        $this->get(self::HOST.'/exams?view=report&class='.$this->class->id.'&term='.$third->id.'&enrolment='.$this->kay->id)
            ->assertOk()
            ->assertSee('Cumulative percentage')
            ->assertSee('1st')
            ->assertSee('2nd')
            ->assertSee('75.00')
            ->assertSee('2 of 2')
            ->assertSee('Through the session')
            ->assertSee('Near the bottom of the class')
            ->assertSeeInOrder(['Mathematics', '90', '60', '75', 'A']);

        $this->get(self::HOST.'/exams/report.pdf?term='.$third->id.'&enrolment='.$this->kay->id.'&preview=html')
            ->assertOk()
            ->assertSee('Cumulative percentage')
            ->assertSee('75.00');
    }

    // --------------------------------------------------------- Broadsheet/report

    private function seedScores(): void
    {
        Tenant::run($this->school, function () {
            SubjectScore::create(['enrolment_id' => $this->kay->id, 'class_subject_id' => $this->mathsCs->id, 'term_id' => $this->term->id, 'ca1' => 10, 'ca2' => 10, 'ca3' => 10, 'exam_score' => 60]); // 90
            SubjectScore::create(['enrolment_id' => $this->kay->id, 'class_subject_id' => $this->englishCs->id, 'term_id' => $this->term->id, 'ca1' => 5, 'ca2' => 5, 'ca3' => 5, 'exam_score' => 35]);  // 50
            SubjectScore::create(['enrolment_id' => $this->zed->id, 'class_subject_id' => $this->mathsCs->id, 'term_id' => $this->term->id, 'ca1' => 5, 'ca2' => 5, 'ca3' => 5, 'exam_score' => 20]);   // 35
        });
    }

    public function test_broadsheet_shows_totals_average_and_position(): void
    {
        $this->seedScores();
        $this->as(Role::REGISTRAR);

        $response = $this->get(self::HOST.'/exams?view=broadsheet&class='.$this->class->id)->assertOk();
        $response->assertSeeInOrder(['★', 'Kay Ade', '50', '90', '140', '70', '1'])
            ->assertSeeInOrder(['Zed Bello', '-', '35', '35', '35', '2'])
            ->assertSee('Mathematics')
            ->assertSee('English')
            ->assertSee('Highest total')
            ->assertSee('Preview')
            ->assertSee('Download PDF')
            ->assertDontSee('Art'); // elective nobody has scores in

        // Sort by name (asc): Kay before Zed.
        $this->get(self::HOST.'/exams?view=broadsheet&class='.$this->class->id.'&sort=name&dir=asc')
            ->assertOk()
            ->assertSeeInOrder(['Kay Ade', 'Zed Bello']);

        // Sort by name (desc): Zed before Kay.
        $this->get(self::HOST.'/exams?view=broadsheet&class='.$this->class->id.'&sort=name&dir=desc')
            ->assertOk()
            ->assertSeeInOrder(['Zed Bello', 'Kay Ade']);

        // PDF export carries school branding and the sorted rows.
        $pdf = $this->get(self::HOST.'/exams/broadsheet.pdf?class='.$this->class->id.'&term='.$this->term->id.'&sort=position&dir=asc')
            ->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment', (string) $pdf->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $preview = $this->get(self::HOST.'/exams/broadsheet.pdf?class='.$this->class->id.'&term='.$this->term->id.'&preview=1')
            ->assertOk();
        $preview->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $preview->headers->get('content-disposition'));
    }

    public function test_report_card_shows_subjects_grades_and_office_can_edit_days(): void
    {
        $this->seedScores();
        $this->as(Role::ADMIN);

        $this->get(self::HOST.'/exams?view=report&class='.$this->class->id.'&enrolment='.$this->kay->id)
            ->assertOk()
            ->assertSeeInOrder(['Kay Ade', 'English', '50', 'C', 'Good', 'Mathematics', '90', 'A', 'Excellent'])
            ->assertSee('Days present')
            ->assertSee("Teacher's comment", false)
            ->assertSee('Save')
            ->assertSee('Preview')
            ->assertSee('Download PDF');

        $this->put(self::HOST.'/exams/report', [
            'term_id' => $this->term->id,
            'enrolment_id' => $this->kay->id,
            'days_present' => 52,
            'days_absent' => 8,
        ])->assertSessionHas('success');

        $report = Tenant::run($this->school, fn () => TermReport::query()->first());
        $this->assertSame(52, $report->days_present);
        $this->assertSame(8, $report->days_absent);

        // Upsert, and respects the days the school was open (60).
        $this->put(self::HOST.'/exams/report', ['term_id' => $this->term->id, 'enrolment_id' => $this->kay->id, 'days_present' => 50, 'days_absent' => 5])->assertSessionHas('success');
        $this->put(self::HOST.'/exams/report', ['term_id' => $this->term->id, 'enrolment_id' => $this->kay->id, 'days_present' => 55, 'days_absent' => 10])->assertSessionHas('error');
        $this->assertSame(1, Tenant::run($this->school, fn () => TermReport::query()->count()));
        $this->assertSame(50, Tenant::run($this->school, fn () => TermReport::query()->first())->days_present);
    }

    public function test_report_card_charts_compare_the_student_with_the_class(): void
    {
        $this->seedScores();
        $this->as(Role::ADMIN);

        $this->get(self::HOST.'/exams?view=report&class='.$this->class->id.'&enrolment='.$this->kay->id)
            ->assertOk()
            ->assertSeeInOrder(['Kay Ade', 'Days present', 'Near the top of the class', '1st of 2', 'Doing well', 'Mathematics', 'Needs attention', 'English'])
            ->assertSee('27 above the class')
            ->assertSee('Level with the class')
            ->assertDontSee('Through the session');

        $this->get(self::HOST.'/exams/report.pdf?term='.$this->term->id.'&enrolment='.$this->kay->id.'&preview=html')
            ->assertOk()
            ->assertDontSee('Near the top of the class')
            ->assertDontSee('Doing well');

        $this->as(Role::STUDENT, ['student_id' => $this->kay->student_id])
            ->get(self::HOST.'/exams?view=report&enrolment='.$this->kay->id)
            ->assertOk()
            ->assertSee('Near the top of the class')
            ->assertSee('Mathematics')
            ->assertSee('English')
            ->assertDontSee('Zed Bello');
    }

    public function test_form_teacher_can_save_behaviour_and_comment(): void
    {
        Tenant::run($this->school, fn () => $this->class->update(['form_teacher_id' => $this->english->id]));
        $this->as(Role::TEACHER, ['staff_id' => $this->english->id]);

        $this->get(self::HOST.'/exams?view=report&class='.$this->class->id.'&enrolment='.$this->kay->id)
            ->assertOk()
            ->assertSee("Teacher's comment", false)
            ->assertDontSee('name="days_present"', false);

        $this->put(self::HOST.'/exams/report', [
            'term_id' => $this->term->id,
            'enrolment_id' => $this->kay->id,
            'view' => 'report',
            'class' => $this->class->id,
            'teacher_comment' => 'Keeps improving.',
            'ratings' => ['honesty' => 4, 'dexterity' => 2, 'neatness' => null],
            'days_present' => 40,
        ])->assertSessionHas('success');

        $report = Tenant::run($this->school, fn () => TermReport::query()->with('ratings')->first());
        $this->assertSame('Keeps improving.', $report->teacher_comment);
        $this->assertSame(4, $report->score('honesty'));
        $this->assertSame(2, $report->score('dexterity'));
        $this->assertNull($report->score('neatness'));
        $this->assertSame(0, $report->days_present);

        $this->put(self::HOST.'/exams/report', [
            'term_id' => $this->term->id,
            'enrolment_id' => $this->kay->id,
            'teacher_comment' => 'Keeps improving.',
            'ratings' => ['honesty' => null, 'dexterity' => 2],
        ])->assertSessionHas('success');

        $report = Tenant::run($this->school, fn () => TermReport::query()->with('ratings')->first());
        $this->assertNull($report->score('honesty'));
        $this->assertSame(2, $report->score('dexterity'));

        $this->as(Role::TEACHER, ['staff_id' => $this->maths->id])
            ->put(self::HOST.'/exams/report', [
                'term_id' => $this->term->id,
                'enrolment_id' => $this->kay->id,
                'teacher_comment' => 'Not my class.',
                'ratings' => ['honesty' => 5],
            ])->assertForbidden();
    }

    public function test_individual_report_card_pdf_follows_the_same_access_rules(): void
    {
        $this->seedScores();
        $kayPdf = self::HOST.'/exams/report.pdf?term='.$this->term->id.'&enrolment='.$this->kay->id;
        $zedPdf = self::HOST.'/exams/report.pdf?term='.$this->term->id.'&enrolment='.$this->zed->id;

        $pdf = $this->as(Role::ADMIN)->get($kayPdf)->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment', (string) $pdf->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $preview = $this->get($kayPdf.'&preview=1')->assertOk();
        $this->assertStringContainsString('inline', (string) $preview->headers->get('content-disposition'));

        $this->get($kayPdf.'&preview=html')->assertOk()->assertSee('Kay Ade')->assertSee('Mathematics');

        $this->as(Role::STUDENT, ['student_id' => $this->kay->student_id])->get($kayPdf)->assertOk();
        $this->get($zedPdf)->assertForbidden();

        $this->as(Role::TEACHER, ['staff_id' => $this->maths->id])->get($kayPdf)->assertForbidden();

        Tenant::run($this->school, fn () => $this->class->update(['form_teacher_id' => $this->english->id]));
        $this->as(Role::TEACHER, ['staff_id' => $this->english->id])->get($kayPdf)->assertOk();
        $this->get(self::HOST.'/exams/report.pdf?term='.$this->term->id.'&enrolment='.$this->other->id)->assertForbidden();
    }

    public function test_student_name_carries_profile_details_on_the_mark_sheet_and_report(): void
    {
        Tenant::run($this->school, function () {
            $this->kay->student->update(['gender' => 'Male', 'phone' => '08031234567', 'address' => '12 School Road']);
            $guardian = Guardian::create(['full_name' => 'Mum Ade', 'phone' => '08039998888']);
            StudentGuardian::create([
                'student_id' => $this->kay->student_id,
                'guardian_id' => $guardian->id,
                'relationship' => 'Mother',
            ]);
        });

        $this->as(Role::ADMIN);

        $this->get(self::HOST.'/exams?view=marksheet&class='.$this->class->id)
            ->assertOk()
            ->assertSee('Mum Ade')
            ->assertSee('08031234567')
            ->assertSee('12 School Road')
            ->assertSee('All subjects')
            ->assertSee('Student details');

        $this->get(self::HOST.'/exams?view=report&class='.$this->class->id.'&enrolment='.$this->kay->id)
            ->assertOk()
            ->assertSee('Mum Ade')
            ->assertSee('08031234567');

        $this->as(Role::TEACHER, ['staff_id' => $this->english->id])
            ->get(self::HOST.'/exams?view=subject&class='.$this->class->id)
            ->assertOk()
            ->assertSee('Mum Ade')
            ->assertSee('08031234567');
    }

    public function test_electives_appear_on_report_cards_only_when_registered_or_scored(): void
    {
        $this->as(Role::ADMIN);
        $url = self::HOST.'/exams?view=report&class='.$this->class->id.'&enrolment='.$this->kay->id;

        $this->get($url)->assertOk()->assertDontSee('Art');

        Tenant::run($this->school, fn () => EnrolmentSubject::create(['enrolment_id' => $this->kay->id, 'class_subject_id' => $this->artCs->id]));
        $this->get($url)->assertOk()->assertSee('Art');
    }

    public function test_students_and_parents_only_open_their_own_report_cards(): void
    {
        $this->seedScores();

        $this->as(Role::STUDENT, ['student_id' => $this->kay->student_id])
            ->get(self::HOST.'/exams')
            ->assertOk()
            ->assertSee('Kay Ade')
            ->assertSee('Mathematics')
            ->assertDontSee('Save days')
            ->assertDontSee('Zed Bello');

        $this->get(self::HOST.'/exams?enrolment='.$this->zed->id)->assertForbidden();
        $this->save($this->row($this->kay, $this->mathsCs, ['exam' => 10]))->assertForbidden();

        $guardian = Tenant::run($this->school, function () {
            $g = Guardian::create(['full_name' => 'Mum Ade']);
            StudentGuardian::create(['student_id' => $this->zed->student_id, 'guardian_id' => $g->id]);

            return $g;
        });

        $this->as(Role::PARENT, ['guardian_id' => $guardian->id])
            ->get(self::HOST.'/exams')
            ->assertOk()
            ->assertSee('Zed Bello')
            ->assertDontSee('Kay Ade');
        $this->get(self::HOST.'/exams?enrolment='.$this->kay->id)->assertForbidden();
    }

    public function test_student_only_sees_sessions_they_were_enrolled_in(): void
    {
        [$earlier, $empty] = Tenant::run($this->school, function () {
            $earlierSession = AcademicSession::create(['name' => '2024/2025', 'start_year' => 2024, 'end_year' => 2025, 'is_current' => false]);
            $earlier = Term::create(['session_id' => $earlierSession->id, 'sequence' => 1, 'name' => 'First Term', 'days_school_open' => 60]);
            $level = Level::query()->first();
            $class = SchoolClass::create(['session_id' => $earlierSession->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'Gold'])->id]);
            Enrolment::create(['student_id' => $this->kay->student_id, 'class_id' => $class->id, 'session_id' => $earlierSession->id, 'status' => 'ACTIVE']);

            $emptySession = AcademicSession::create(['name' => '2019/2020', 'start_year' => 2019, 'end_year' => 2020, 'is_current' => false]);
            Term::create(['session_id' => $emptySession->id, 'sequence' => 1, 'name' => 'First Term', 'days_school_open' => 60]);

            return [$earlier, $emptySession];
        });

        $this->as(Role::STUDENT, ['student_id' => $this->kay->student_id])
            ->get(self::HOST.'/exams')
            ->assertOk()
            ->assertSee('2026/2027')
            ->assertSee('2024/2025')
            ->assertDontSee('2019/2020');

        // Changing the term submits the current enrolment id. That is still this student.
        $this->get(self::HOST.'/exams?term='.$earlier->id.'&enrolment='.$this->kay->id)
            ->assertOk()
            ->assertSee('2024/2025')
            ->assertSee('Gold')
            ->assertSee('Kay Ade');
    }

    public function test_exams_nav_is_enabled_and_other_schools_data_is_unreachable(): void
    {
        $this->as(Role::ADMIN)->get(self::HOST.'/dashboard')->assertOk()->assertSee(route('exams'), false);

        $other = School::create(['name' => 'Other', 'slug' => 'oth', 'subdomain' => 'oth']);
        $this->actingAs(User::factory()->create(['school_id' => $other->id, 'role' => Role::ADMIN]));
        $this->post('http://oth.localhost/exams/scores', [
            'term_id' => $this->term->id,
            'scores' => $this->row($this->kay, $this->mathsCs, ['exam' => 10]),
        ])->assertNotFound();
        $this->assertCount(0, $this->scores());
    }
}
