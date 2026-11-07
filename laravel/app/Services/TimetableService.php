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
use App\Models\TimetableSlot;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

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

    public const PERIOD = 'PERIOD';

    public const BREAK = 'BREAK';

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

    // --------------------------------------------------------------- School day

    /** Periods and breaks for this school, weekdays first, then Friday when it differs. */
    public function slots(): Collection
    {
        return TimetableSlot::query()->get()
            ->sortBy(fn (TimetableSlot $s) => [$s->day_group === 'FRIDAY' ? 1 : 0, $s->sequence])
            ->values();
    }

    /**
     * @return array{separate: bool, weekDays: array<string, string>, week: SupportCollection, friday: SupportCollection}
     */
    public function layout(Collection $slots): array
    {
        $friday = $slots->where('day_group', 'FRIDAY')->values();
        $week = $slots->where('day_group', 'WEEK')->values();

        return [
            'separate' => $friday->isNotEmpty(),
            'weekDays' => $this->daysFor('WEEK', $friday->isNotEmpty()),
            'week' => $week,
            'friday' => $friday,
        ];
    }

    /**
     * Turn a simple day description into ordered period and break rows.
     *
     * @param  array{start: string, period_minutes: int, periods: int, breaks?: array<int, array{after?: int, minutes?: int, label?: string}>}  $spec
     * @return array<int, array{sequence: int, kind: string, label: string, start_time: string, end_time: string}>
     */
    public function slotsFromSpec(array $spec): array
    {
        $start = $this->minutes($spec['start'] ?? null);
        $length = (int) ($spec['period_minutes'] ?? 0);
        $count = (int) ($spec['periods'] ?? 0);
        if ($start === null) {
            throw new DomainException('Enter the time the day starts.');
        }
        if ($count < 1 || $count > 12) {
            throw new DomainException('Choose between 1 and 12 periods.');
        }
        if ($length < 5 || $length > 180) {
            throw new DomainException('Each period must be between 5 and 180 minutes.');
        }

        $breaks = [];
        foreach ($spec['breaks'] ?? [] as $break) {
            $minutes = (int) ($break['minutes'] ?? 0);
            if ($minutes === 0) {
                continue;
            }
            $after = (int) ($break['after'] ?? 0);
            if ($after < 1 || $after > $count) {
                throw new DomainException('A break has to sit after one of the periods.');
            }
            if ($minutes < 5 || $minutes > 180) {
                throw new DomainException('A break must be between 5 and 180 minutes.');
            }
            $label = trim((string) ($break['label'] ?? ''));
            $breaks[$after][] = ['minutes' => $minutes, 'label' => $label !== '' ? $label : 'Break'];
        }

        $rows = [];
        $cursor = $start;
        $sequence = 1;
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = $this->slotRow($sequence++, self::PERIOD, 'Period '.$i, $cursor, $cursor + $length);
            $cursor += $length;
            foreach ($breaks[$i] ?? [] as $break) {
                $rows[] = $this->slotRow($sequence++, self::BREAK, $break['label'], $cursor, $cursor + $break['minutes']);
                $cursor += $break['minutes'];
            }
        }
        if ($cursor > 24 * 60) {
            throw new DomainException('That day runs past midnight. Shorten it or start earlier.');
        }

        return $rows;
    }

    /** Replace the school day. An empty Friday spec means Friday uses the weekday times. */
    public function saveStructure(array $weekSpec, ?array $fridaySpec): void
    {
        $week = $this->slotsFromSpec($weekSpec);
        $friday = $fridaySpec ? $this->slotsFromSpec($fridaySpec) : [];

        DB::transaction(function () use ($week, $friday) {
            TimetableSlot::query()->delete();
            foreach ($week as $row) {
                TimetableSlot::create($row + ['day_group' => 'WEEK']);
            }
            foreach ($friday as $row) {
                TimetableSlot::create($row + ['day_group' => 'FRIDAY']);
            }
        });

        AuditService::record('update', 'TimetableSlot', null, [
            'periods' => collect($week)->where('kind', self::PERIOD)->count(),
            'friday' => $friday !== [],
        ]);
    }

    /**
     * Subject chosen for each period cell of a class. Breaks are not included.
     *
     * @param  array{separate: bool, weekDays: array<string, string>, week: SupportCollection, friday: SupportCollection}  $layout
     * @return array<string, array<string, Period|null>>
     */
    public function selectionMap(Collection $periods, array $layout): array
    {
        $map = [];
        foreach ([[$layout['week'], array_keys($layout['weekDays'])], [$layout['friday'], ['FRIDAY']]] as [$rows, $days]) {
            foreach ($rows as $slot) {
                if ($slot->kind !== self::PERIOD) {
                    continue;
                }
                foreach ($days as $day) {
                    $map[$slot->id][$day] = $periods->first(fn (Period $p) => $p->day === $day
                        && $p->start_time === $slot->start_time
                        && $p->end_time === $slot->end_time);
                }
            }
        }

        return $map;
    }

    /**
     * Periods whose time is not one of the school-day periods.
     *
     * @param  array{separate: bool, weekDays: array<string, string>, week: SupportCollection, friday: SupportCollection}  $layout
     */
    public function unmatchedPeriods(Collection $periods, array $layout): Collection
    {
        $known = [];
        foreach ($layout['week'] as $slot) {
            if ($slot->kind !== self::PERIOD) {
                continue;
            }
            foreach (array_keys($layout['weekDays']) as $day) {
                $known[$day.'|'.$slot->start_time.'|'.$slot->end_time] = true;
            }
        }
        foreach ($layout['friday'] as $slot) {
            if ($slot->kind !== self::PERIOD) {
                continue;
            }
            $known['FRIDAY|'.$slot->start_time.'|'.$slot->end_time] = true;
        }

        return $periods
            ->filter(fn (Period $p) => ! isset($known[$p->day.'|'.$p->start_time.'|'.$p->end_time]))
            ->values();
    }

    /**
     * Save the subject chosen for each period of one class. An empty choice clears that cell.
     *
     * @param  array<string, array<string, ?string>>  $assignments  slot id => [DAY => class subject id]
     */
    public function saveAssignments(string $classId, array $assignments): void
    {
        $class = SchoolClass::query()->find($classId) ?? throw new DomainException('Class not found.');
        $slots = $this->slots()->keyBy('id');
        if ($slots->isEmpty()) {
            throw new DomainException('Set the school day before filling a class timetable.');
        }
        $layout = $this->layout($slots);
        $offers = ClassSubject::query()->where('class_id', $class->getKey())->with('teacher')->get()->keyBy('id');
        $existing = $this->periodsForClass($class->getKey());
        $planned = [];

        foreach ($assignments as $slotId => $byDay) {
            $slot = $slots->get($slotId);
            if (! $slot || $slot->kind !== self::PERIOD) {
                throw new DomainException('That period is not part of the school day.');
            }
            $allowed = $slot->day_group === 'FRIDAY' ? ['FRIDAY'] : array_keys($layout['weekDays']);
            foreach ($byDay as $day => $subjectId) {
                $day = strtoupper((string) $day);
                if (! in_array($day, $allowed, true)) {
                    throw new DomainException('That day is not part of this period.');
                }
                $offer = null;
                if (filled($subjectId)) {
                    $offer = $offers->get($subjectId) ?? throw new DomainException('Choose a subject taught in this class.');
                }
                $match = $existing->first(fn (Period $p) => $p->day === $day
                    && $p->start_time === $slot->start_time
                    && $p->end_time === $slot->end_time);
                $planned[] = ['slot' => $slot, 'day' => $day, 'offer' => $offer, 'match' => $match];
            }
        }

        foreach ($planned as $item) {
            if ($item['offer']) {
                $this->assertSlotFree($item['offer'], $item['day'], $item['slot']->start_time, $item['slot']->end_time, $item['match']);
            }
        }

        DB::transaction(function () use ($planned) {
            foreach ($planned as $item) {
                if (! $item['offer']) {
                    $item['match']?->delete();

                    continue;
                }
                if ($item['match']) {
                    $item['match']->update(['class_subject_id' => $item['offer']->getKey()]);
                } else {
                    Period::create([
                        'class_subject_id' => $item['offer']->getKey(),
                        'day' => $item['day'],
                        'start_time' => $item['slot']->start_time,
                        'end_time' => $item['slot']->end_time,
                    ]);
                }
            }
        });

        AuditService::record('update', 'Period', null, ['class_id' => $class->getKey(), 'cells' => count($planned)]);
    }

    /** @return array<string, string> */
    private function daysFor(string $group, bool $fridaySeparate): array
    {
        if ($group === 'FRIDAY') {
            return ['FRIDAY' => 'Fri'];
        }
        $days = self::DAYS;
        if ($fridaySeparate) {
            unset($days['FRIDAY']);
        }

        return $days;
    }

    /** @return array{sequence: int, kind: string, label: string, start_time: string, end_time: string} */
    private function slotRow(int $sequence, string $kind, string $label, int $start, int $end): array
    {
        if ($end > 24 * 60) {
            throw new DomainException('That day runs past midnight. Shorten it or start earlier.');
        }

        return [
            'sequence' => $sequence,
            'kind' => $kind,
            'label' => mb_substr($label, 0, 40),
            'start_time' => $this->normalise($start),
            'end_time' => $this->normalise($end === 24 * 60 ? (24 * 60) - 1 : $end),
        ];
    }

    private function assertSlotFree(ClassSubject $offer, string $day, string $start, string $end, ?Period $ignore): void
    {
        $startM = $this->minutes($start);
        $endM = $this->minutes($end);
        $sameDay = Period::query()
            ->where('day', $day)
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->getKey()))
            ->with(['classSubject.teacher', 'classSubject.class.level', 'classSubject.class.arm'])
            ->get();

        foreach ($sameDay as $other) {
            $otherStart = $this->minutes($other->start_time);
            $otherEnd = $this->minutes($other->end_time);
            if ($otherStart === null || $otherEnd === null || $startM >= $otherEnd || $otherStart >= $endM) {
                continue;
            }
            if ($other->classSubject?->class_id === $offer->class_id) {
                throw new DomainException('That class already has a period at this time.');
            }
            if ($ignore?->room_id && $other->room_id === $ignore->room_id) {
                throw new DomainException('That room is already in use at this time.');
            }
            if ($offer->teacher_id && $other->classSubject?->teacher_id === $offer->teacher_id) {
                $who = $offer->teacher?->full_name ?? 'That teacher';
                $where = $other->classSubject?->class?->display_name ?? 'another class';

                throw new DomainException($who.' is already teaching '.$where.' on '.ucfirst(strtolower($day)).' at '.$start.'.');
            }
        }
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
