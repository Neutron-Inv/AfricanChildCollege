<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LessonPlan;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\SetsUpSchoolLife;
use Tests\TestCase;

class LessonsTest extends TestCase
{
    use RefreshDatabase, SetsUpSchoolLife;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchoolLife();
    }

    private function data(array $over = []): array
    {
        return $over + [
            'class_subject_id' => $this->maths->id,
            'term_id' => $this->term->id,
            'topic' => 'Fractions',
            'week' => 'Week 2',
            'lesson_date' => '2026-10-05',
            'specific_objective' => 'Add simple fractions',
            'content' => 'Common denominators explained.',
        ];
    }

    private function plan(array $over = []): LessonPlan
    {
        return Tenant::run($this->school, fn () => LessonPlan::create($over + [
            'class_subject_id' => $this->maths->id,
            'term_id' => $this->term->id,
            'topic' => 'Decimals',
            'status' => 'DRAFT',
            'author_id' => $this->ada->id,
        ]));
    }

    private function fresh(LessonPlan $plan): LessonPlan
    {
        return Tenant::run($this->school, fn () => LessonPlan::query()->find($plan->id));
    }

    public function test_teacher_creates_draft_for_own_subject(): void
    {
        $this->asTeacher($this->ada)->post(self::HOST.'/lessons', $this->data())->assertRedirect();

        $plan = Tenant::run($this->school, fn () => LessonPlan::query()->first());
        $this->assertSame('Fractions', $plan->topic);
        $this->assertSame('DRAFT', $plan->status);
        $this->assertSame($this->ada->id, $plan->author_id);
        $this->assertSame('Common denominators explained.', $plan->content);
        $this->assertSame('2026-10-05', $plan->lesson_date->toDateString());
    }

    public function test_teacher_cannot_write_for_someone_elses_subject(): void
    {
        $this->asTeacher($this->ada)->post(self::HOST.'/lessons', $this->data(['class_subject_id' => $this->english->id]))
            ->assertSessionHas('error');

        $this->assertSame(0, Tenant::run($this->school, fn () => LessonPlan::query()->count()));
    }

    public function test_topic_is_required(): void
    {
        $this->asTeacher($this->ada)->post(self::HOST.'/lessons', $this->data(['topic' => '']))->assertSessionHasErrors('topic');
    }

    public function test_admin_cannot_author_and_students_are_blocked(): void
    {
        $this->as(Role::ADMIN)->post(self::HOST.'/lessons', $this->data())->assertForbidden();
        $this->as(Role::STUDENT)->get(self::HOST.'/lessons')->assertForbidden();
    }

    public function test_author_edits_draft_but_not_after_submission(): void
    {
        $plan = $this->plan();

        $this->asTeacher($this->ada)->put(self::HOST.'/lessons/'.$plan->id, $this->data(['topic' => 'Fractions II']))->assertRedirect();
        $this->assertSame('Fractions II', $this->fresh($plan)->topic);

        $this->asTeacher($this->ada)->post(self::HOST.'/lessons/'.$plan->id.'/submit')->assertRedirect();
        $this->assertSame('SUBMITTED', $this->fresh($plan)->status);

        $this->asTeacher($this->ada)->put(self::HOST.'/lessons/'.$plan->id, $this->data(['topic' => 'Changed']))->assertSessionHas('error');
        $this->assertSame('Fractions II', $this->fresh($plan)->topic);
    }

    public function test_other_teachers_cannot_edit_or_submit(): void
    {
        $plan = $this->plan();

        $this->asTeacher($this->bola)->post(self::HOST.'/lessons/'.$plan->id.'/submit')->assertSessionHas('error');
        $this->asTeacher($this->bola)->put(self::HOST.'/lessons/'.$plan->id, $this->data(['class_subject_id' => $this->english->id]))->assertSessionHas('error');
        $this->assertSame('DRAFT', $this->fresh($plan)->status);
    }

    public function test_hod_approves_submitted_plan_in_their_department(): void
    {
        $plan = $this->plan(['status' => 'SUBMITTED']);

        $this->asTeacher($this->hod)->get(self::HOST.'/lessons/'.$plan->id)->assertOk()->assertSee('Approve');
        $this->asTeacher($this->hod)->post(self::HOST.'/lessons/'.$plan->id.'/approve')->assertSessionHas('success');

        $fresh = $this->fresh($plan);
        $this->assertSame('APPROVED', $fresh->status);
        $this->assertSame($this->hod->id, $fresh->hod_id);
        $this->assertNotNull($fresh->hod_signed_at);
    }

    public function test_admin_can_approve_and_return_to_draft(): void
    {
        $approved = $this->plan(['status' => 'SUBMITTED']);
        $returned = $this->plan(['status' => 'SUBMITTED', 'topic' => 'Percent']);

        $this->as(Role::ADMIN)->post(self::HOST.'/lessons/'.$approved->id.'/approve')->assertSessionHas('success');
        $this->as(Role::ADMIN)->post(self::HOST.'/lessons/'.$returned->id.'/return')->assertSessionHas('success');

        $this->assertSame('APPROVED', $this->fresh($approved)->status);
        $this->assertSame('DRAFT', $this->fresh($returned)->status);
    }

    public function test_unrelated_teacher_and_draft_plans_cannot_be_approved(): void
    {
        $submitted = $this->plan(['status' => 'SUBMITTED']);
        $draft = $this->plan();

        // Bola neither authored nor heads the department: the plan is invisible to her.
        $this->asTeacher($this->bola)->post(self::HOST.'/lessons/'.$submitted->id.'/approve')->assertSessionHas('error');
        // Drafts cannot be signed off, even by an admin.
        $this->as(Role::ADMIN)->post(self::HOST.'/lessons/'.$draft->id.'/approve')->assertSessionHas('error');

        $this->assertSame('SUBMITTED', $this->fresh($submitted)->status);
        $this->assertSame('DRAFT', $this->fresh($draft)->status);
    }

    public function test_hod_cannot_approve_their_own_plan(): void
    {
        $plan = $this->plan(['status' => 'SUBMITTED', 'author_id' => $this->hod->id]);

        $this->asTeacher($this->hod)->post(self::HOST.'/lessons/'.$plan->id.'/approve')->assertSessionHas('error');
        $this->assertSame('SUBMITTED', $this->fresh($plan)->status);
    }

    public function test_listing_is_scoped_to_author_and_department(): void
    {
        $this->plan(['topic' => 'Ada plan']);
        $this->plan(['topic' => 'Bola plan', 'author_id' => $this->bola->id, 'class_subject_id' => $this->english->id]);

        $this->asTeacher($this->ada)->get(self::HOST.'/lessons')->assertOk()->assertSee('Ada plan')->assertDontSee('Bola plan');
        // HOD of Sciences sees Maths plans but not English (no department).
        $this->asTeacher($this->hod)->get(self::HOST.'/lessons')->assertOk()->assertSee('Ada plan')->assertDontSee('Bola plan');
        $this->as(Role::ADMIN)->get(self::HOST.'/lessons')->assertOk()->assertSee('Ada plan')->assertSee('Bola plan');
        $this->asTeacher($this->bola)->get(self::HOST.'/lessons/'.$this->plan()->id)->assertNotFound();
    }

    public function test_status_filter(): void
    {
        $this->plan(['topic' => 'Draft topic']);
        $this->plan(['topic' => 'Submitted topic', 'status' => 'SUBMITTED']);

        $this->as(Role::ADMIN)->get(self::HOST.'/lessons?status=SUBMITTED')->assertSee('Submitted topic')->assertDontSee('Draft topic');
    }

    public function test_nav_links_to_lessons(): void
    {
        $this->as(Role::ADMIN)->get(self::HOST.'/dashboard')->assertOk()->assertSee(route('lessons'), false);
    }
}
