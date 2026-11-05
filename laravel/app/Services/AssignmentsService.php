<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Assignment;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
use App\Models\StoredFile;
use App\Models\StudentGuardian;
use App\Models\Term;
use App\Models\User;
use App\Support\Tenant;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Business rules for assignments.
 *
 * Teachers set assignments for the class subjects they teach (optionally with one attached file kept in
 * StoredFile); students and parents see assignments for the subjects of the classes they are enrolled in.
 * Student submissions are not built yet. All models are tenant-scoped; rule violations throw DomainException.
 */
class AssignmentsService
{
    public const DISK = 'local';

    public const FILE_PURPOSE = 'ASSIGNMENT';

    /** Max upload size in kilobytes (validated in the controller). */
    public const MAX_FILE_KB = 10240;

    public const FILE_TYPES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png,zip';

    // ----------------------------------------------------------------- Access

    public function isAdmin(User $user): bool
    {
        return $user->isPlatformOwner() || $user->hasRole(Role::ADMIN);
    }

    /** Teachers (with a staff record) create assignments for their own subjects. */
    public function canCreate(User $user): bool
    {
        return $user->hasRole(Role::TEACHER) && $user->staff_id !== null;
    }

    public function canManage(User $user, Assignment $assignment): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $user->staff_id !== null
            && ($assignment->teacher_id === $user->staff_id || $assignment->classSubject?->teacher_id === $user->staff_id);
    }

    /** Assignments the user may see (and download the file of). */
    public function visibleQuery(User $user): Builder
    {
        $query = Assignment::query();

        if ($this->isAdmin($user)) {
            return $query;
        }

        if ($user->hasRole(Role::TEACHER)) {
            $staffId = $user->staff_id ?: '-';

            return $query->where(fn (Builder $q) => $q
                ->where('teacher_id', $staffId)
                ->orWhereHas('classSubject', fn ($c) => $c->where('teacher_id', $staffId)));
        }

        if ($user->hasRole(Role::STUDENT, Role::PARENT)) {
            return $query->whereIn('class_subject_id', $this->ownClassSubjectIds($user));
        }

        return $query->whereRaw('1 = 0');
    }

    public function canView(User $user, Assignment $assignment): bool
    {
        return $this->visibleQuery($user)->whereKey($assignment->getKey())->exists();
    }

    /**
     * Subject offerings a student (or a parent's children) are taking: compulsory subjects of the
     * class plus the electives they were enrolled in.
     *
     * @return array<int, string>
     */
    private function ownClassSubjectIds(User $user): array
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

        $enrolments = Enrolment::query()->whereIn('student_id', $studentIds)->where('status', 'ACTIVE')->get(['id', 'class_id']);
        if ($enrolments->isEmpty()) {
            return [];
        }

        $compulsory = ClassSubject::query()
            ->whereIn('class_id', $enrolments->pluck('class_id'))
            ->where('kind', AcademicsService::KIND_COMPULSORY)
            ->pluck('id');

        $electives = EnrolmentSubject::query()->whereIn('enrolment_id', $enrolments->pluck('id'))->pluck('class_subject_id');

        return $compulsory->merge($electives)->unique()->values()->all();
    }

    // ---------------------------------------------------------------- Lookups

    public function list(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $this->visibleQuery($user)
            ->with(['classSubject.subject', 'classSubject.class.level', 'classSubject.class.arm', 'teacher', 'term', 'file'])
            ->orderByRaw('due_date IS NULL')
            ->orderByDesc('due_date')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(User $user, string $id): ?Assignment
    {
        return $this->visibleQuery($user)->with(['classSubject', 'file'])->find($id);
    }

    /** Subject offerings the user can set assignments for (own, current session). */
    public function offeringsFor(User $user): Collection
    {
        if (! $this->canCreate($user)) {
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
     * @param  array<string, mixed>  $data  class_subject_id, term_id, title, note, due_date
     */
    public function create(User $user, array $data, ?UploadedFile $file = null): Assignment
    {
        if (! $this->canCreate($user)) {
            throw new DomainException('Only teachers can create assignments.');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new DomainException('Enter the assignment title.');
        }

        $offer = ClassSubject::query()->with('class')->find($data['class_subject_id'] ?? null)
            ?? throw new DomainException('Subject offering not found.');
        if ($offer->teacher_id !== $user->staff_id) {
            throw new DomainException('You can only set assignments for subjects you teach.');
        }

        $term = Term::query()->find($data['term_id'] ?? null) ?? throw new DomainException('Choose a term.');
        if ($term->session_id !== $offer->class?->session_id) {
            throw new DomainException('That term does not belong to the class\'s session.');
        }

        $due = ($data['due_date'] ?? null) ?: null;
        if ($due !== null && strtotime((string) $due) === false) {
            throw new DomainException('Enter a valid due date.');
        }

        $stored = $file ? $this->storeFile($file) : null;

        $assignment = Assignment::create([
            'class_subject_id' => $offer->getKey(),
            'term_id' => $term->getKey(),
            'title' => $title,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'due_date' => $due,
            'file_id' => $stored?->getKey(),
            'teacher_id' => $user->staff_id,
        ]);

        AuditService::record('create', 'Assignment', $assignment->getKey());

        return $assignment;
    }

    public function delete(User $user, string $id): Assignment
    {
        $assignment = Assignment::query()->with(['classSubject', 'file'])->find($id)
            ?? throw new DomainException('Assignment not found.');

        if (! $this->canManage($user, $assignment)) {
            throw new DomainException('You can only delete your own assignments.');
        }

        $file = $assignment->file;
        $assignment->delete();

        if ($file) {
            Storage::disk(self::DISK)->delete($file->storage_key);
            $file->delete();
        }

        AuditService::record('delete', 'Assignment', $id);

        return $assignment;
    }

    /** Stream the attached file; the caller has already confirmed the user may view the assignment. */
    public function download(Assignment $assignment): StreamedResponse
    {
        $file = $assignment->file ?? throw new DomainException('This assignment has no file.');
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($file->storage_key)) {
            throw new DomainException('The file is no longer available.');
        }

        return $disk->download($file->storage_key, $file->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    // ---------------------------------------------------------------- Helpers

    private function storeFile(UploadedFile $file): StoredFile
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $key = 'schools/'.Tenant::id().'/assignments/'.Str::ulid().'.'.$extension;

        // Read details before moving the temp file.
        $meta = [
            'purpose' => self::FILE_PURPOSE,
            'storage_key' => $key,
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
        ];

        Storage::disk(self::DISK)->putFileAs(dirname($key), $file, basename($key));

        return StoredFile::create($meta);
    }
}
