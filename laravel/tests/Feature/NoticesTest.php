<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Notice;
use App\Models\SchoolEvent;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\SetsUpSchoolLife;
use Tests\TestCase;

class NoticesTest extends TestCase
{
    use RefreshDatabase, SetsUpSchoolLife;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchoolLife();
    }

    private function notice(array $over = []): Notice
    {
        $user = \App\Models\User::factory()->create(['school_id' => $this->school->id]);

        return Tenant::run($this->school, fn () => Notice::create($over + [
            'title' => 'Hello', 'body' => 'Body', 'audience' => 'ALL', 'publish_at' => now()->subMinute(), 'created_by_id' => $user->id,
        ]));
    }

    public function test_admin_and_registrar_post_notices(): void
    {
        $this->as(Role::ADMIN)->post(self::HOST.'/notices', ['title' => 'Exams', 'body' => 'Start Monday', 'audience' => 'PARENT'])
            ->assertRedirect(self::HOST.'/notices');
        $this->as(Role::REGISTRAR)->post(self::HOST.'/notices', ['title' => 'Fees', 'body' => 'Due soon', 'audience' => 'ALL'])->assertRedirect();

        $notices = Tenant::run($this->school, fn () => Notice::query()->orderBy('title')->get());
        $this->assertCount(2, $notices);
        $this->assertSame('PARENT', $notices[0]->audience);
        $this->assertNotNull($notices[0]->publish_at);
    }

    public function test_others_cannot_post_or_delete(): void
    {
        $notice = $this->notice();

        foreach ([Role::TEACHER, Role::STUDENT, Role::PARENT, Role::ACCOUNTANT] as $role) {
            $this->as($role)->post(self::HOST.'/notices', ['title' => 'x', 'body' => 'y', 'audience' => 'ALL'])->assertForbidden();
            $this->as($role)->delete(self::HOST.'/notices/'.$notice->id)->assertForbidden();
            $this->as($role)->post(self::HOST.'/notices/events', ['title' => 'x', 'start_date' => '2026-11-01', 'all_day' => 1])->assertForbidden();
        }
        $this->assertSame(1, Tenant::run($this->school, fn () => Notice::query()->count()));
    }

    public function test_validation(): void
    {
        $this->as(Role::ADMIN)->post(self::HOST.'/notices', ['title' => '', 'body' => 'y', 'audience' => 'ALL'])->assertSessionHasErrors('title');
        $this->as(Role::ADMIN)->post(self::HOST.'/notices', ['title' => 'x', 'body' => 'y', 'audience' => 'MARTIANS'])->assertSessionHasErrors('audience');
    }

    public function test_notices_are_listed_by_audience(): void
    {
        $this->notice(['title' => 'For everyone']);
        $this->notice(['title' => 'For parents', 'audience' => 'PARENT']);
        $this->notice(['title' => 'For students', 'audience' => 'STUDENT']);
        $this->notice(['title' => 'For staff', 'audience' => 'STAFF']);

        $this->as(Role::PARENT)->get(self::HOST.'/notices')
            ->assertSee('For everyone')->assertSee('For parents')->assertDontSee('For students')->assertDontSee('For staff');
        $this->as(Role::STUDENT)->get(self::HOST.'/notices')
            ->assertSee('For students')->assertDontSee('For parents')->assertDontSee('For staff');
        $this->as(Role::TEACHER)->get(self::HOST.'/notices')
            ->assertSee('For staff')->assertDontSee('For students')->assertDontSee('For parents');
        $this->as(Role::ADMIN)->get(self::HOST.'/notices')
            ->assertSee('For everyone')->assertSee('For parents')->assertSee('For students')->assertSee('For staff');
    }

    public function test_audience_filter(): void
    {
        $this->notice(['title' => 'For everyone']);
        $this->notice(['title' => 'For parents', 'audience' => 'PARENT']);

        $this->as(Role::ADMIN)->get(self::HOST.'/notices?audience=PARENT')->assertSee('For parents')->assertDontSee('For everyone');
    }

    public function test_scheduled_notice_is_hidden_until_publish_date(): void
    {
        $this->notice(['title' => 'Future news', 'publish_at' => now()->addDays(3)]);

        $this->as(Role::STUDENT)->get(self::HOST.'/notices')->assertDontSee('Future news');
        $this->as(Role::ADMIN)->get(self::HOST.'/notices')->assertSee('Future news')->assertSee('Scheduled');
        $this->as(Role::STUDENT)->get(self::HOST.'/dashboard')->assertDontSee('Future news');
    }

    public function test_future_publish_date_from_form_is_scheduled(): void
    {
        $date = now()->addDays(5)->toDateString();
        $this->as(Role::ADMIN)->post(self::HOST.'/notices', ['title' => 'Later', 'body' => 'b', 'audience' => 'ALL', 'publish_at' => $date]);

        $notice = Tenant::run($this->school, fn () => Notice::query()->first());
        $this->assertSame($date, $notice->publish_at->toDateString());
        $this->assertTrue($notice->publish_at->isFuture());
    }

    public function test_admin_deletes_notice(): void
    {
        $notice = $this->notice();

        $this->as(Role::ADMIN)->delete(self::HOST.'/notices/'.$notice->id)->assertSessionHas('success');
        $this->assertSame(0, Tenant::run($this->school, fn () => Notice::query()->count()));
    }

    public function test_create_all_day_and_timed_events(): void
    {
        $this->as(Role::ADMIN)->post(self::HOST.'/notices/events', [
            'title' => 'Inter-house sports', 'start_date' => '2026-11-10', 'end_date' => '2026-11-11', 'all_day' => 1,
        ])->assertRedirect(self::HOST.'/notices');
        $this->as(Role::REGISTRAR)->post(self::HOST.'/notices/events', [
            'title' => 'PTA meeting', 'start_date' => '2026-11-14', 'start_time' => '10:00', 'end_time' => '12:00',
        ])->assertSessionHasNoErrors();

        $events = Tenant::run($this->school, fn () => SchoolEvent::query()->orderBy('starts_at')->get());
        $this->assertCount(2, $events);
        $this->assertTrue($events[0]->all_day);
        $this->assertSame('2026-11-10 00:00', $events[0]->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-11 23:59', $events[0]->ends_at->format('Y-m-d H:i'));
        $this->assertFalse($events[1]->all_day);
        $this->assertSame('2026-11-14 10:00', $events[1]->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-14 12:00', $events[1]->ends_at->format('Y-m-d H:i'));
    }

    public function test_event_validation(): void
    {
        $post = fn (array $d) => $this->as(Role::ADMIN)->post(self::HOST.'/notices/events', $d + ['title' => 'E']);

        $post(['start_date' => '2026-11-10', 'end_date' => '2026-11-09', 'all_day' => 1])->assertSessionHas('error');
        $post(['start_date' => '2026-11-10'])->assertSessionHas('error'); // timed event needs a start time
        $post(['start_date' => '2026-11-10', 'start_time' => '12:00', 'end_time' => '09:00'])->assertSessionHas('error');
        $post(['start_date' => 'nope', 'all_day' => 1])->assertSessionHasErrors('start_date');

        $this->assertSame(0, Tenant::run($this->school, fn () => SchoolEvent::query()->count()));
    }

    public function test_dashboard_calendar_shows_events_and_navigates_months(): void
    {
        Tenant::run($this->school, function () {
            SchoolEvent::create(['title' => 'Founders Day', 'starts_at' => '2026-11-10 00:00:00', 'ends_at' => '2026-11-10 23:59:59', 'all_day' => true]);
            SchoolEvent::create(['title' => 'Harvest Fair', 'starts_at' => '2026-09-05 09:00:00', 'ends_at' => '2026-09-05 11:00:00', 'all_day' => false]);
        });

        $this->as(Role::STUDENT)->get(self::HOST.'/dashboard?month=2026-11')
            ->assertOk()->assertSee('November 2026')->assertSee('Founders Day')->assertDontSee('Harvest Fair')
            ->assertSee('month=2026-12', false)->assertSee('month=2026-10', false);

        $this->as(Role::TEACHER)->get(self::HOST.'/dashboard?month=2026-09')->assertSee('Harvest Fair');
        // A malformed month falls back to the current month instead of erroring.
        $this->as(Role::TEACHER)->get(self::HOST.'/dashboard?month=garbage')->assertOk();
    }

    public function test_multi_day_event_spans_calendar_days(): void
    {
        $service = app(\App\Services\NoticesService::class);
        Tenant::run($this->school, function () use ($service) {
            SchoolEvent::create(['title' => 'Mid-term break', 'starts_at' => '2026-11-02 00:00:00', 'ends_at' => '2026-11-04 23:59:59', 'all_day' => true]);

            $cal = $service->calendar(\Illuminate\Support\Carbon::create(2026, 11, 1));
            $days = collect($cal['weeks'])->flatten(1)->filter(fn ($d) => $d['events']->isNotEmpty())->map(fn ($d) => $d['date']->day)->values()->all();
            $this->assertSame([2, 3, 4], $days);
            $this->assertCount(7, $cal['weeks'][0]);
        });
    }

    public function test_notices_are_tenant_scoped(): void
    {
        $other = \App\Models\School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        Tenant::run($other, fn () => Notice::create(['title' => 'Secret other notice', 'body' => 'b', 'audience' => 'ALL', 'publish_at' => now()->subMinute(), 'created_by_id' => 'x']));

        $this->as(Role::ADMIN)->get(self::HOST.'/notices')->assertOk()->assertDontSee('Secret other notice');
    }
}
