<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\ClassSubject;
use App\Models\Department;
use App\Models\LessonPlan;
use App\Models\Term;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Business rules for lesson notes (lesson plans).
 *
 * Teachers write plans for the class subjects they teach (DRAFT), submit them (SUBMITTED), and the
 * subject's head of department - or an ADMIN - signs them off (APPROVED) or returns them to draft.
 * All models are tenant-scoped. Rule violations throw DomainException with a user-facing message.
 */
class LessonsService
{
    public const DRAFT = 'DRAFT';

    public const SUBMITTED = 'SUBMITTED';

    public const APPROVED = 'APPROVED';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::APPROVED];

    /** Form fields stored on the lesson plan. */
    private const TEXT_FIELDS = ['sub_topic', 'specific_objective', 'content', 'materials', 'previous_knowledge', 'evaluation', 'assignment', 'reference'];

    // ----------------------------------------------------------------- Access

    /** ADMIN (and a platform owner inside a school) see and approve every plan. */
    public function isAdmin(User $user): bool
    {
        return $user->isPlatformOwner() || $user->hasRole(Role::ADMIN);
    }

    /** @return array<int, string> departments the user heads. */
    public function hodDepartmentIds(User $user): array
    {
        if (! $user->staff_id) {
            return [];
        }

        return Department::query()->where('hod_id', $user->staff_id)->pluck('id')->all();
    }

    public function isHod(User $user): bool
    {
        return $this->hodDepartmentIds($user) !== [];
    }

    /** Plans a user may open: admins all; teachers their own plus their departments'. */
    public function visibleQuery(User $user): Builder
    {
        $query = LessonPlan::query();

        if ($this->isAdmin($user)) {
            return $query;
        }

        $departments = $this->hodDepartmentIds($user);

        return $query->where(function (Builder $q) use ($user, $departments) {
            $q->where('author_id', $user->staff_id ?: '-');
            if ($departments !== []) {
                $q->orWhereHas('classSubject.subject', fn ($s) => $s->whereIn('department_id', $departments));
            }
        });
    }

    public function canApprove(User $user, LessonPlan $plan): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        // A HOD signs off their department's plans, but never their own.
        if (! $user->staff_id || $plan->author_id === $user->staff_id) {
            return false;
        }
        $departmentId = $plan->classSubject?->subject?->department_id;

        return $departmentId !== null && in_array($departmentId, $this->hodDepartmentIds($user), true);
    }

    public function canEdit(User $user, LessonPlan $plan): bool
    {
        return $plan->status === self::DRAFT && $user->staff_id !== null && $plan->author_id === $user->staff_id;
    }

    public function canAuthor(User $user): bool
    {
        return $user->hasRole(Role::TEACHER) && $user->staff_id !== null;
    }

    // ---------------------------------------------------------------- Lookups

    public function list(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $status = strtoupper((string) ($filters['status'] ?? ''));

        return $this->visibleQuery($user)
            ->with(['classSubject.subject', 'classSubject.class.level', 'classSubject.class.arm', 'author', 'term'])
            ->when(in_array($status, self::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when(! empty($filters['class_subject_id']), fn ($q) => $q->where('class_subject_id', $filters['class_subject_id']))
            ->orderByDesc('lesson_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(User $user, string $id): ?LessonPlan
    {
        return $this->visibleQuery($user)
            ->with(['classSubject.subject.department', 'classSubject.class.level', 'classSubject.class.arm', 'author', 'hod', 'term'])
            ->find($id);
    }

    /** Subject offerings the user can write plans for (their own, current session). */
    public function offeringsFor(User $user): Collection
    {
        if (! $user->staff_id) {
            return new Collection;
        }

        $sessionId = AcademicSession::query()->where('is_current', true)->value('id');

        return ClassSubject::query()
            ->where('teacher_id', $user->staff_id)
            ->when($sessionId, fn ($q) => $q->whereHas('class', fn ($c) => $c->where('session_id', $sessionId)))
            ->with(['subject', 'class.level', 'class.arm'])
            ->get()
            ->sortBy(fn ($cs) => strtolower(($cs->class?->display_name ?? '').' '.($cs->subject?->name ?? '')))
            ->values();
    }

    /** Offerings the user may filter the list by (those visible plans belong to, plus own). */
    public function filterOfferings(User $user): Collection
    {
        $ids = $this->visibleQuery($user)->pluck('class_subject_id')->unique();

        return ClassSubject::query()
            ->whereIn('id', $ids)
            ->with(['subject', 'class.level', 'class.arm'])
            ->get()
            ->sortBy(fn ($cs) => strtolower(($cs->class?->display_name ?? '').' '.($cs->subject?->name ?? '')))
            ->values();
    }

    public function terms(): Collection
    {
        $sessionId = AcademicSession::query()->where('is_current', true)->value('id');

        return Term::query()
            ->when($sessionId, fn ($q) => $q->where('session_id', $sessionId))
            ->orderBy('sequence')
            ->get();
    }

    // --------------------------------------------------------------- Commands

    /**
     * @param  array<string, mixed>  $data  class_subject_id, term_id, topic, week, lesson_date, + text fields
     */
    public function create(User $user, array $data): LessonPlan
    {
        $values = $this->validated($user, $data);

        $plan = LessonPlan::create($values + [
            'status' => self::DRAFT,
            'author_id' => $user->staff_id,
        ]);

        AuditService::record('create', 'LessonPlan', $plan->getKey());

        return $plan;
    }

    public function update(User $user, string $id, array $data): LessonPlan
    {
        $plan = $this->findOwn($user, $id);
        $this->assertDraft($plan, 'Only draft lesson notes can be edited.');

        $plan->update($this->validated($user, $data));

        AuditService::record('update', 'LessonPlan', $plan->getKey());

        return $plan->refresh();
    }

    public function submit(User $user, string $id): LessonPlan
    {
        $plan = $this->findOwn($user, $id);
        $this->assertDraft($plan, 'Only draft lesson notes can be submitted.');

        $plan->update(['status' => self::SUBMITTED, 'submitted_at' => now()]);

        AuditService::record('submit', 'LessonPlan', $plan->getKey());

        return $plan->refresh();
    }

    /** HOD / ADMIN sign-off. */
    public function approve(User $user, string $id): LessonPlan
    {
        $plan = $this->findReviewable($user, $id);

        $plan->update([
            'status' => self::APPROVED,
            'hod_id' => $user->staff_id,
            'hod_signed_at' => now(),
        ]);

        AuditService::record('approve', 'LessonPlan', $plan->getKey());

        return $plan->refresh();
    }

    /** HOD / ADMIN sends a submitted plan back to the teacher. */
    public function returnToDraft(User $user, string $id): LessonPlan
    {
        $plan = $this->findReviewable($user, $id);

        $plan->update(['status' => self::DRAFT, 'submitted_at' => null]);

        AuditService::record('return', 'LessonPlan', $plan->getKey());

        return $plan->refresh();
    }

    // ---------------------------------------------------------------- Helpers

    private function findOwn(User $user, string $id): LessonPlan
    {
        $plan = LessonPlan::query()->find($id) ?? throw new DomainException('Lesson note not found.');

        if (! $user->staff_id || $plan->author_id !== $user->staff_id) {
            throw new DomainException('Only the author can change this lesson note.');
        }

        return $plan;
    }

    private function findReviewable(User $user, string $id): LessonPlan
    {
        $plan = LessonPlan::query()->with('classSubject.subject')->find($id) ?? throw new DomainException('Lesson note not found.');

        if (! $this->canApprove($user, $plan)) {
            throw new DomainException('Only the head of department or an administrator can review this lesson note.');
        }
        if ($plan->status !== self::SUBMITTED) {
            throw new DomainException('Only submitted lesson notes can be reviewed.');
        }

        return $plan;
    }

    private function assertDraft(LessonPlan $plan, string $message): void
    {
        if ($plan->status !== self::DRAFT) {
            throw new DomainException($message);
        }
    }

    /** @return array<string, mixed> */
    private function validated(User $user, array $data): array
    {
        if (! $this->canAuthor($user)) {
            throw new DomainException('Only teachers can write lesson notes.');
        }

        $topic = trim((string) ($data['topic'] ?? ''));
        if ($topic === '') {
            throw new DomainException('Enter the lesson topic.');
        }

        $offer = ClassSubject::query()->with('class')->find($data['class_subject_id'] ?? null)
            ?? throw new DomainException('Subject offering not found.');
        if ($offer->teacher_id !== $user->staff_id) {
            throw new DomainException('You can only write lesson notes for subjects you teach.');
        }

        $term = Term::query()->find($data['term_id'] ?? null) ?? throw new DomainException('Choose a term.');
        if ($term->session_id !== $offer->class?->session_id) {
            throw new DomainException('That term does not belong to the class\'s session.');
        }

        $date = ($data['lesson_date'] ?? null) ?: null;
        if ($date !== null && strtotime((string) $date) === false) {
            throw new DomainException('Enter a valid lesson date.');
        }

        $values = [
            'class_subject_id' => $offer->getKey(),
            'term_id' => $term->getKey(),
            'topic' => $topic,
            'week' => trim((string) ($data['week'] ?? '')),
            'lesson_date' => $date,
        ];
        foreach (self::TEXT_FIELDS as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $values[$field] = $field === 'sub_topic' ? $value : ($value === '' ? null : $value);
        }

        return $values;
    }
}
