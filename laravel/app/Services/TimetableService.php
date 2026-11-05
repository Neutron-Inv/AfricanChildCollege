<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\Period;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\StudentGuardian;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Business rules for the weekly timetable (periods).
 *
 * All models are tenant-scoped. Rule violations throw DomainException with a user-facing message.
 * Period times are stored as zero-padded "HH:MM" strings, the weekday as MONDAY..SUNDAY.
 */
class TimetableService
{
    /** Weekdays that can be scheduled, in display order. */
    public const DAYS = [
        'MONDAY' => 'Mon',
        'TUESDAY' => 'Tue',
        'WEDNESDAY' => 'Wed',
        'THURSDAY' => 'Thu',
        'FRIDAY' => 'Fri',
    ];

    /** Weekend days: never offered when scheduling, but displayed if legacy data has them. */
    public const WEEKEND = ['SATURDAY' => 'Sat', 'SUNDAY' => 'Sun'];

    // ----------------------------------------------------------------- Access

    /** ADMIN / REGISTRAR (and a platform owner inside a school) manage every period. */
    public function canManage(User $user): bool
    {
        return $user->isPlatformOwner() || $user->hasRole(Role::ADMIN, Role::REGISTRAR);
    }

    // ---------------------------------------------------------------- Lookups

    public function sessions(): Collection
    {
        return AcademicSession::query()->orderByDesc('start_year')->get();
    }

    /**
     * Classes of a session the user may look at, sorted by level then arm.
     * Students and parents only see the classes their (children's) active enrolments are in.
     */
    public function classesFor(User $user, ?string $sessionId): Collection
    {
        if (! $sessionId) {
            return new Collection;
        }

        $query = SchoolClass::query()->where('session_id', $sessionId)->with(['level', 'arm', 'formTeacher']);

        if ($user->hasRole(Role::STUDENT, Role::PARENT)) {
            $query->whereIn('id', $this->ownClassIds($user, $sessionId));
        }

        return $query->get()
            ->sortBy([
                fn ($a, $b) => ($a->level?->sort_order ?? 0) <=> ($b->level?->sort_order ?? 0),
                fn ($a, $b) => strcmp((string) $a->level?->name, (string) $b->level?->name),
                fn ($a, $b) => strcmp((string) $a->arm?->name, (string) $b->arm?->name),
            ])
            ->values();
    }

    /** @return array<int, string> */
    private function ownClassIds(User $user, string $sessionId): array
    {
        $studentIds = [];
        if ($user->role === Role::STUDENT && $user->student_id) {
            $studentIds = [$user->student_id];
        } elseif ($user->role === Role::PARENT && $user->guardian_id) {
            $studentIds = StudentGuardian::query()->where('guardian_id', $user->guardian_id)->pluck('student_id')->all();
        }

        if ($studentIds === []) {
            return [];
        }

        return Enrolment::query()
            ->whereIn('student_id', $studentIds)
            ->where('session_id', $sessionId)
            ->where('status', 'ACTIVE')
            ->pluck('class_id')
            ->all();
    }

    /** Subject offerings of a class, for the period form. */
    public function classSubjects(string $classId): Collection
    {
        return ClassSubject::query()
            ->where('class_id', $classId)
            ->with(['subject', 'teacher'])
            ->get()
            ->sortBy(fn ($cs) => strtolower($cs->subject?->name ?? ''))
            ->values();
    }

    public function rooms(): Collection
    {
        return Room::query()->orderBy('name')->get();
    }

    // ---------------------------------------------------------------- Periods

    /** Periods of one class, earliest first. */
    public function periodsForClass(string $classId): Collection
    {
        return $this->withRelations(Period::query()
            ->whereHas('classSubject', fn ($q) => $q->where('class_id', $classId)))
            ->get()
            ->sortBy(fn (Period $p) => [$this->dayIndex($p->day), $p->start_time])
            ->values();
    }

    /** Periods a staff member teaches or whose class they are form teacher of, in a session. */
    public function periodsForTeacher(string $staffId, string $sessionId): Collection
    {
        return $this->withRelations(Period::query()
            ->whereHas('classSubject', fn ($q) => $q
                ->whereHas('class', fn ($c) => $c->where('session_id', $sessionId))
                ->where(fn ($w) => $w
                    ->where('teacher_id', $staffId)
                    ->orWhereHas('class', fn ($c) => $c->where('form_teacher_id', $staffId)))))
            ->get()
            ->sortBy(fn (Period $p) => [$this->dayIndex($p->day), $p->start_time])
            ->values();
    }

    public function find(string $id): ?Period
    {
        return $this->withRelations(Period::query())->find($id);
    }

    /**
     * @param  array{class_subject_id: string, day: string, start_time: string, end_time: string, room_id?: ?string}  $data
     */
    public function createPeriod(array $data): Period
    {
        $values = $this->validated($data);

        $period = Period::create($values);

        AuditService::record('create', 'Period', $period->getKey());

        return $period;
    }

    public function updatePeriod(string $id, array $data): Period
    {
        $period = Period::query()->find($id) ?? throw new DomainException('Period not found.');
        $values = $this->validated($data, $period);

        $period->update($values);

        AuditService::record('update', 'Period', $period->getKey());

        return $period->refresh();
    }

    public function deletePeriod(string $id): Period
    {
        $period = Period::query()->with('classSubject')->find($id) ?? throw new DomainException('Period not found.');
        $period->delete();

        AuditService::record('delete', 'Period', $id);

        return $period;
    }

    // ------------------------------------------------------------------- Grid

    /**
     * Arrange periods into a Mon-Fri grid (weekend columns only if periods exist on them).
     *
     * @return array{days: array<string, string>, slots: array<int, array{start: string, end: string}>, cells: array<string, array<string, SupportCollection>>}
     */
    public function grid(iterable $periods): array
    {
        $periods = collect($periods);

        $days = self::DAYS;
        foreach (self::WEEKEND as $day => $label) {
            if ($periods->contains(fn (Period $p) => $p->day === $day)) {
                $days[$day] = $label;
            }
        }

        $slots = $periods
            ->map(fn (Period $p) => ['start' => $p->start_time, 'end' => $p->end_time])
            ->unique(fn ($s) => $s['start'].'-'.$s['end'])
            ->sortBy(fn ($s) => [$this->minutes($s['start']) ?? 0, $this->minutes($s['end']) ?? 0])
            ->values()
            ->all();

        $cells = [];
        foreach ($slots as $slot) {
            $key = $slot['start'].'-'.$slot['end'];
            foreach (array_keys($days) as $day) {
                $cells[$key][$day] = $periods
                    ->filter(fn (Period $p) => $p->day === $day && $p->start_time === $slot['start'] && $p->end_time === $slot['end'])
                    ->values();
            }
        }

        return ['days' => $days, 'slots' => $slots, 'cells' => $cells];
    }

    // ---------------------------------------------------------------- Helpers

    private function withRelations($query)
    {
        return $query->with(['room', 'classSubject.subject', 'classSubject.teacher', 'classSubject.class.level', 'classSubject.class.arm']);
    }

    private function dayIndex(string $day): int
    {
        $i = array_search($day, array_keys(self::DAYS + self::WEEKEND), true);

        return $i === false ? 99 : $i;
    }

    /** Minutes since midnight for "HH:MM", or null when malformed. */
    public function minutes(?string $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($time), $m)) {
            return null;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    private function normalise(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Validate and normalise the input; checks class / room / teacher clashes.
     *
     * @return array{class_subject_id: string, room_id: ?string, day: string, start_time: string, end_time: string}
     */
    private function validated(array $data, ?Period $ignore = null): array
    {
        $day = strtoupper((string) ($data['day'] ?? ''));
        if (! array_key_exists($day, self::DAYS)) {
            throw new DomainException('Choose a weekday from Monday to Friday.');
        }

        $start = $this->minutes($data['start_time'] ?? null);
        $end = $this->minutes($data['end_time'] ?? null);
        if ($start === null || $end === null) {
            throw new DomainException('Enter the start and end times as HH:MM.');
        }
        if ($end <= $start) {
            throw new DomainException('End time must be after the start time.');
        }

        $offer = ClassSubject::query()->find($data['class_subject_id'] ?? null)
            ?? throw new DomainException('Subject offering not found.');

        $roomId = ($data['room_id'] ?? null) ?: null;
        if ($roomId && ! Room::query()->whereKey($roomId)->exists()) {
            throw new DomainException('Room not found.');
        }

        $sameDay = Period::query()
            ->where('day', $day)
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->getKey()))
            ->with('classSubject')
            ->get();

        foreach ($sameDay as $other) {
            $otherStart = $this->minutes($other->start_time);
            $otherEnd = $this->minutes($other->end_time);
            if ($otherStart === null || $otherEnd === null || $start >= $otherEnd || $otherStart >= $end) {
                continue;
            }
            if ($other->classSubject?->class_id === $offer->class_id) {
                throw new DomainException('That class already has a period at this time.');
            }
            if ($roomId && $other->room_id === $roomId) {
                throw new DomainException('That room is already in use at this time.');
            }
            if ($offer->teacher_id && $other->classSubject?->teacher_id === $offer->teacher_id) {
                throw new DomainException('That teacher already has a period at this time.');
            }
        }

        return [
            'class_subject_id' => $offer->getKey(),
            'room_id' => $roomId,
            'day' => $day,
            'start_time' => $this->normalise($start),
            'end_time' => $this->normalise($end),
        ];
    }
}
