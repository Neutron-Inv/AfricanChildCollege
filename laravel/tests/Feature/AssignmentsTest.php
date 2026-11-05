<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Assignment;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
use App\Models\ClassSubject;
use App\Models\StoredFile;
use App\Models\Subject;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\SetsUpSchoolLife;
use Tests\TestCase;

class AssignmentsTest extends TestCase
{
    use RefreshDatabase, SetsUpSchoolLife;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpSchoolLife();
    }

    private function data(array $over = []): array
    {
        return $over + [
            'class_subject_id' => $this->maths->id,
            'term_id' => $this->term->id,
            'title' => 'Algebra worksheet',
            'note' => 'Do questions 1-10',
            'due_date' => '2026-11-06',
        ];
    }

    private function assignment(array $over = []): Assignment
    {
        return Tenant::run($this->school, fn () => Assignment::create($over + [
            'class_subject_id' => $this->maths->id,
            'term_id' => $this->term->id,
            'title' => 'Existing task',
            'teacher_id' => $this->ada->id,
        ]));
    }

    public function test_teacher_creates_assignment_with_file(): void
    {
        $file = UploadedFile::fake()->create('worksheet.pdf', 120, 'application/pdf');

        $this->asTeacher($this->ada)->post(self::HOST.'/assignments', $this->data(['file' => $file]))
            ->assertRedirect(self::HOST.'/assignments');

        $assignment = Tenant::run($this->school, fn () => Assignment::query()->with('file')->first());
        $this->assertSame('Algebra worksheet', $assignment->title);
        $this->assertSame('2026-11-06', $assignment->due_date->toDateString());
        $this->assertSame($this->ada->id, $assignment->teacher_id);
        $this->assertSame('worksheet.pdf', $assignment->file->original_name);
        $this->assertSame('ASSIGNMENT', $assignment->file->purpose);
        Storage::disk('local')->assertExists($assignment->file->storage_key);
    }

    public function test_assignment_without_file_is_allowed(): void
    {
        $this->asTeacher($this->ada)->post(self::HOST.'/assignments', $this->data())->assertSessionHasNoErrors();

        $this->assertNull(Tenant::run($this->school, fn () => Assignment::query()->first())->file_id);
    }

    public function test_rejects_bad_file_type_and_foreign_subject(): void
    {
        $this->asTeacher($this->ada)->post(self::HOST.'/assignments', $this->data(['file' => UploadedFile::fake()->create('run.php', 5)]))
            ->assertSessionHasErrors('file');
        $this->asTeacher($this->ada)->post(self::HOST.'/assignments', $this->data(['class_subject_id' => $this->english->id]))
            ->assertSessionHas('error');

        $this->assertSame(0, Tenant::run($this->school, fn () => Assignment::query()->count()));
    }

    public function test_students_and_admin_cannot_create(): void
    {
        $this->asStudent($this->student)->post(self::HOST.'/assignments', $this->data())->assertForbidden();
        $this->as(Role::ADMIN)->post(self::HOST.'/assignments', $this->data())->assertForbidden();
        $this->as(Role::REGISTRAR)->get(self::HOST.'/assignments')->assertForbidden();
    }

    public function test_student_sees_only_their_class_assignments_and_downloads_file(): void
    {
        $file = Tenant::run($this->school, fn () => StoredFile::create([
            'purpose' => 'ASSIGNMENT', 'storage_key' => 'schools/x/assignments/a.pdf', 'mime_type' => 'application/pdf', 'size' => 5, 'original_name' => 'sheet.pdf',
        ]));
        Storage::disk('local')->put('schools/x/assignments/a.pdf', 'hello');

        $mine = $this->assignment(['title' => 'Class A task', 'file_id' => $file->id]);
        $other = $this->assignment(['title' => 'Class B task', 'class_subject_id' => $this->otherMaths->id]);

        $this->asStudent($this->student)->get(self::HOST.'/assignments')
            ->assertOk()->assertSee('Class A task')->assertDontSee('Class B task')->assertSee('sheet.pdf');

        $this->asStudent($this->student)->get(self::HOST.'/assignments/'.$mine->id.'/file')
            ->assertOk()->assertDownload('sheet.pdf');

        // Another class's student cannot reach it; neither can they download the other class's file.
        $this->asStudent($this->outsider)->get(self::HOST.'/assignments/'.$mine->id.'/file')->assertNotFound();
        $this->asStudent($this->student)->get(self::HOST.'/assignments/'.$other->id.'/file')->assertNotFound();
    }

    public function test_student_does_not_see_unchosen_elective(): void
    {
        $elective = Tenant::run($this->school, function () {
            $art = Subject::create(['name' => 'Art']);

            return ClassSubject::create(['class_id' => $this->class->id, 'subject_id' => $art->id, 'teacher_id' => $this->bola->id, 'kind' => 'ELECTIVE']);
        });
        $this->assignment(['title' => 'Art project', 'class_subject_id' => $elective->id, 'teacher_id' => $this->bola->id]);

        $this->asStudent($this->student)->get(self::HOST.'/assignments')->assertDontSee('Art project');

        Tenant::run($this->school, function () use ($elective) {
            $enrolment = Enrolment::query()->where('student_id', $this->student->id)->first();
            EnrolmentSubject::create(['enrolment_id' => $enrolment->id, 'class_subject_id' => $elective->id]);
        });

        $this->asStudent($this->student)->get(self::HOST.'/assignments')->assertSee('Art project');
    }

    public function test_teacher_sees_own_assignments_only(): void
    {
        $this->assignment(['title' => 'Maths task']);
        $this->assignment(['title' => 'English task', 'class_subject_id' => $this->english->id, 'teacher_id' => $this->bola->id]);

        $this->asTeacher($this->ada)->get(self::HOST.'/assignments')->assertSee('Maths task')->assertDontSee('English task');
        $this->as(Role::ADMIN)->get(self::HOST.'/assignments')->assertSee('Maths task')->assertSee('English task');
    }

    public function test_delete_removes_file_and_is_restricted_to_owner(): void
    {
        $this->asTeacher($this->ada)->post(self::HOST.'/assignments', $this->data(['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]));
        $assignment = Tenant::run($this->school, fn () => Assignment::query()->with('file')->first());
        $key = $assignment->file->storage_key;

        $this->asTeacher($this->bola)->delete(self::HOST.'/assignments/'.$assignment->id)->assertSessionHas('error');
        $this->assertSame(1, Tenant::run($this->school, fn () => Assignment::query()->count()));

        $this->asTeacher($this->ada)->delete(self::HOST.'/assignments/'.$assignment->id)->assertSessionHas('success');
        $this->assertSame(0, Tenant::run($this->school, fn () => Assignment::query()->count()));
        $this->assertSame(0, Tenant::run($this->school, fn () => StoredFile::query()->count()));
        Storage::disk('local')->assertMissing($key);
    }

    public function test_student_cannot_delete(): void
    {
        $assignment = $this->assignment();

        $this->asStudent($this->student)->delete(self::HOST.'/assignments/'.$assignment->id)->assertSessionHas('error');
        $this->assertSame(1, Tenant::run($this->school, fn () => Assignment::query()->count()));
    }
}
