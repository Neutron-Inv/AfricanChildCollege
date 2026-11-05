<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Notice;
use App\Models\SchoolEvent;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Business rules for notices (announcements by audience) and the school calendar (events).
 *
 * ADMIN / REGISTRAR post notices and events. Everyone else only sees notices that are already
 * published and addressed to them. All models are tenant-scoped; rule violations throw DomainException.
 */
class NoticesService
{
    public const ALL = 'ALL';

    public const STAFF = 'STAFF';

    public const STUDENTS = 'STUDENT';

    public const PARENTS = 'PARENT';

    /** Audience value => label. */
    public const AUDIENCES = [
        self::ALL => 'Everyone',
        self::STAFF => 'Staff',
        self::STUDENTS => 'Students',
        self::PARENTS => 'Parents',
    ];

    // ----------------------------------------------------------------- Access

    /** ADMIN / REGISTRAR (and a platform owner inside a school) manage notices and events. */
    public function canManage(User $user): bool
    {
        return $user->isPlatformOwner() || $user->hasRole(Role::ADMIN, Role::REGISTRAR);
    }

    /** Audiences a user belongs to (besides ALL). */
    private function audiencesOf(User $user): array
    {
        return match (true) {
            $user->hasRole(Role::STUDENT) => [self::STUDENTS],
            $user->hasRole(Role::PARENT) => [self::PARENTS],
            default => [self::STAFF],
        };
    }

    // ---------------------------------------------------------------- Notices

    /** Managers see everything (including scheduled); others only published notices for their audience. */
    public function visibleQuery(User $user): Builder
    {
        $query = Notice::query();

        if ($this->canManage($user)) {
            return $query;
        }

        $audiences = array_merge([self::ALL], $this->audiencesOf($user));

        return $query
            ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('audience')->orWhereIn('audience', $audiences));
    }

    public function list(User $user, ?string $audience = null, int $perPage = 15): LengthAwarePaginator
    {
        $audience = strtoupper((string) $audience);

        return $this->visibleQuery($user)
            ->with('createdBy.staff')
            ->when(array_key_exists($audience, self::AUDIENCES), fn ($q) => $q->where(
                fn ($w) => $audience === self::ALL
                    ? $w->whereNull('audience')->orWhere('audience', self::ALL)
                    : $w->where('audience', $audience)))
            ->orderByRaw('COALESCE(publish_at, created_at) DESC')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Latest notices for the dashboard. */
    public function latest(User $user, int $limit = 5): Collection
    {
        return $this->visibleQuery($user)
            ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->orderByRaw('COALESCE(publish_at, created_at) DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data  title, body, audience, publish_at (date; blank = now)
     */
    public function createNotice(User $user, array $data): Notice
    {
        $this->assertCanManage($user);

        $title = trim((string) ($data['title'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        if ($title === '' || $body === '') {
            throw new DomainException('Enter the notice title and message.');
        }

        $audience = strtoupper((string) ($data['audience'] ?? self::ALL)) ?: self::ALL;
        if (! array_key_exists($audience, self::AUDIENCES)) {
            throw new DomainException('Choose who the notice is for.');
        }

        $publishAt = now();
        if (! empty($data['publish_at'])) {
            $publishAt = $this->parse($data['publish_at'], 'Enter a valid publish date.')->startOfDay();
            // A date of today (or earlier) publishes straight away.
            if ($publishAt->lte(now())) {
                $publishAt = now();
            }
        }

        $notice = Notice::create([
            'title' => $title,
            'body' => $body,
            'audience' => $audience,
            'publish_at' => $publishAt,
            'created_by_id' => $user->getKey(),
        ]);

        AuditService::record('create', 'Notice', $notice->getKey());

        return $notice;
    }

    public function deleteNotice(User $user, string $id): Notice
    {
        $this->assertCanManage($user);

        $notice = Notice::query()->find($id) ?? throw new DomainException('Notice not found.');
        $notice->delete();

        AuditService::record('delete', 'Notice', $id);

        return $notice;
    }

    // ----------------------------------------------------------------- Events

    /** Upcoming and ongoing events, soonest first. */
    public function upcomingEvents(int $limit = 10): Collection
    {
        return SchoolEvent::query()
            ->where('ends_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data  title, description, start_date, end_date (blank = start), all_day, start_time, end_time
     */
    public function createEvent(User $user, array $data): SchoolEvent
    {
        $this->assertCanManage($user);

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new DomainException('Enter the event title.');
        }

        $startDate = $this->parse($data['start_date'] ?? null, 'Enter the event start date.')->startOfDay();
        $endDate = ! empty($data['end_date'])
            ? $this->parse($data['end_date'], 'Enter a valid end date.')->startOfDay()
            : $startDate->copy();

        $allDay = filter_var($data['all_day'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($allDay) {
            $starts = $startDate->copy();
            $ends = $endDate->copy()->endOfDay();
        } else {
            $startTime = (string) ($data['start_time'] ?? '');
            $endTime = (string) ($data['end_time'] ?? '');
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
                throw new DomainException('Enter the start time as HH:MM, or mark the event as all day.');
            }
            $starts = $startDate->copy()->setTimeFromTimeString($startTime);
            if ($endTime !== '' && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $endTime)) {
                throw new DomainException('Enter the end time as HH:MM.');
            }
            $ends = match (true) {
                $endTime !== '' => $endDate->copy()->setTimeFromTimeString($endTime),
                $endDate->gt($startDate) => $endDate->copy()->endOfDay(),
                default => $starts->copy(),
            };
        }

        if ($ends->lt($starts)) {
            throw new DomainException('The event cannot end before it starts.');
        }

        $event = SchoolEvent::create([
            'title' => $title,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'all_day' => $allDay,
        ]);

        AuditService::record('create', 'SchoolEvent', $event->getKey());

        return $event;
    }

    public function deleteEvent(User $user, string $id): SchoolEvent
    {
        $this->assertCanManage($user);

        $event = SchoolEvent::query()->find($id) ?? throw new DomainException('Event not found.');
        $event->delete();

        AuditService::record('delete', 'SchoolEvent', $id);

        return $event;
    }

    // --------------------------------------------------------------- Calendar

    /** Parse "YYYY-MM" (falls back to the current month). */
    public function parseMonth(?string $month): Carbon
    {
        if ($month && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return Carbon::createFromFormat('!Y-m', $month);
        }

        return now()->startOfMonth();
    }

    /**
     * Month grid (weeks start on Monday) with the events that touch each day.
     *
     * @return array{month: Carbon, prev: string, next: string, weeks: array<int, array<int, array{date: Carbon, in_month: bool, today: bool, events: Collection}>>}
     */
    public function calendar(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $gridStart = $month->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $events = SchoolEvent::query()
            ->where('starts_at', '<=', $gridEnd)
            ->where('ends_at', '>=', $gridStart)
            ->orderBy('starts_at')
            ->get();

        $weeks = [];
        for ($day = $gridStart->copy(); $day->lte($gridEnd); $day->addDay()) {
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();

            $weeks[intdiv((int) $day->diffInDays($gridStart, true), 7)][] = [
                'date' => $dayStart,
                'in_month' => $day->month === $month->month,
                'today' => $day->isToday(),
                'events' => $events
                    ->filter(fn (SchoolEvent $e) => $e->starts_at->lte($dayEnd) && $e->ends_at->gte($dayStart))
                    ->values(),
            ];
        }

        return [
            'month' => $month,
            'prev' => $month->copy()->subMonth()->format('Y-m'),
            'next' => $month->copy()->addMonth()->format('Y-m'),
            'weeks' => array_values($weeks),
        ];
    }

    // ---------------------------------------------------------------- Helpers

    private function assertCanManage(User $user): void
    {
        if (! $this->canManage($user)) {
            throw new DomainException('Only the administrator or registrar can do that.');
        }
    }

    private function parse(mixed $value, string $message): Carbon
    {
        try {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw new \InvalidArgumentException;
            }

            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            throw new DomainException($message);
        }
    }
}
