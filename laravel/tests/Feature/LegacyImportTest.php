<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ImportLegacySchoolJob;
use App\Models\AcademicSession;
use App\Models\ClassSubject;
use App\Models\Enrolment;
use App\Models\EnrolmentSubject;
use App\Models\Guardian;
use App\Models\LegacyLink;
use App\Models\School;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\SubjectScore;
use App\Models\Term;
use App\Models\TermReport;
use App\Models\User;
use App\Services\Legacy\LegacyImporter;
use App\Services\Legacy\LegacyImportException;
use App\Support\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runs the importer against a small sqlite fixture that mimics the legacy MySQL dump.
 */
class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'legacy-secret-pw';

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useLegacyFixture();
        $this->school = School::create(['name' => 'Secondary', 'slug' => 'secondary', 'subdomain' => 'secondary']);
    }

    // ---------------------------------------------------------------- Command

    public function test_command_dispatches_the_orchestrator_job_for_the_school(): void
    {
        Bus::fake();

        $this->artisan('legacy:import secondary')->assertSuccessful();

        Bus::assertDispatched(ImportLegacySchoolJob::class, fn ($job) => $job->schoolId === $this->school->id && $job->runSync === false);
    }

    public function test_command_rejects_unknown_schools(): void
    {
        Bus::fake();

        $this->artisan('legacy:import nope')->assertFailed();

        Bus::assertNothingDispatched();
    }

    public function test_command_reports_an_unreachable_legacy_database_and_dispatches_nothing(): void
    {
        $this->breakLegacyConnection();
        Bus::fake();

        $this->artisan('legacy:import secondary')
            ->expectsOutputToContain('legacy database is not reachable')
            ->assertFailed();
        Bus::assertNothingDispatched();

        // --skip-check queues it anyway; the job itself then fails with the same message.
        $this->artisan('legacy:import secondary --skip-check')->assertSuccessful();
        Bus::assertDispatched(ImportLegacySchoolJob::class);
    }

    public function test_jobs_fail_with_a_clear_message_when_the_legacy_database_is_unavailable(): void
    {
        $this->breakLegacyConnection();

        $this->expectException(LegacyImportException::class);
        $this->expectExceptionMessage('Check the LEGACY_DB_* settings');

        (new ImportLegacySchoolJob($this->school->id))->handle();
    }

    public function test_jobs_fail_with_a_clear_message_when_tables_are_missing(): void
    {
        Schema::connection('legacy')->drop('student_exam');

        $this->expectException(LegacyImportException::class);
        $this->expectExceptionMessage('missing required tables: student_exam');

        (new LegacyImporter($this->school))->assertReady();
    }

    // ----------------------------------------------------------------- Import

    public function test_full_import_maps_people_structure_enrolments_and_scores(): void
    {
        $exit = Artisan::call('legacy:import', ['schoolSlug' => 'secondary', '--sync' => true]);
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertStringNotContainsString(self::SECRET, Artisan::output());
        $this->assertStringNotContainsString('12345', Artisan::output());

        Tenant::run($this->school, function () {
            // Staff: teacher + admin + accountant + registrar, with the right roles.
            $this->assertSame(4, Staff::query()->count());
            $teacher = Staff::query()->where('legacy_id', 10)->first();
            $this->assertSame('T-101', $teacher->index_number);
            $this->assertTrue($teacher->is_teacher);
            $this->assertSame('Titus', $teacher->first_name);
            $this->assertSame('Solomon', $teacher->last_name);
            $roles = User::query()->whereNotNull('staff_id')->pluck('role')->map(fn ($r) => $r->value)->sort()->values()->all();
            $this->assertSame(['ACCOUNTANT', 'ADMIN', 'REGISTRAR', 'TEACHER'], $roles);

            // Students: 3 (one LEFT), logins only where an email exists, all must change password.
            $this->assertSame(3, Student::query()->count());
            $this->assertSame('LEFT', Student::query()->where('index_number', '1999')->value('status'));
            $this->assertSame('2010-05-04', Student::query()->where('index_number', '1722')->first()->birth_date->toDateString());
            $studentUsers = User::query()->whereNotNull('student_id')->get();
            $this->assertCount(1, $studentUsers);

            // Passwords are hashed (never plaintext). The legacy value is kept and can be used immediately.
            $login = $studentUsers->first();
            $this->assertSame(Role::STUDENT, $login->role);
            $this->assertNotSame(self::SECRET, $login->password);
            $this->assertTrue(Hash::check(self::SECRET, $login->password));
            $this->assertFalse($login->must_change_password);
            $this->assertTrue(User::query()->get()->every(fn ($u) => ! $u->must_change_password && ! str_contains($u->password, self::SECRET)));

            // A login with no legacy password gets the shared school password.
            $noPassword = User::query()->where('email', 'accountant@legacy.test')->first();
            $this->assertTrue(Hash::check('12345', $noPassword->password));

            // Guardians + child link + parent login.
            $this->assertSame(1, Guardian::query()->count());
            $this->assertSame(1722, (int) StudentGuardian::query()->first()->student->index_number);
            $this->assertSame(Role::PARENT, User::query()->whereNotNull('guardian_id')->first()->role);

            // Sessions, terms, classes: 2 sessions x 2 grades.
            $this->assertSame(['2022/2023', '2023/2024'], AcademicSession::query()->orderBy('name')->pluck('name')->all());
            $this->assertSame('2023/2024', AcademicSession::query()->where('is_current', true)->value('name'));
            $this->assertSame(2, Term::query()->count());
            $this->assertSame(60, Term::query()->where('legacy_exam_id', 12)->value('days_school_open'));
            $this->assertSame(4, \App\Models\SchoolClass::query()->count());
            $class = \App\Models\SchoolClass::query()->where('legacy_grade_id', 14)->first();
            $this->assertSame($teacher->id, $class->form_teacher_id);
            $this->assertEquals(50000, $class->admission_fee);

            // Subjects + offerings: imported offerings are ELECTIVE.
            $this->assertSame(['English', 'Mathematics'], Subject::query()->orderBy('name')->pluck('name')->all());
            $this->assertSame(4, ClassSubject::query()->count());
            $this->assertSame(['ELECTIVE'], ClassSubject::query()->pluck('kind')->unique()->values()->all());
            $this->assertSame($teacher->id, ClassSubject::query()->where('legacy_routing_id', 17)->value('teacher_id'));

            // Enrolments: 1722 in both sessions (exam votes), 1830 via student_grade (2023 -> 2023/2024).
            $this->assertSame(3, Enrolment::query()->count());
            $old = AcademicSession::query()->where('name', '2022/2023')->first();
            $this->assertSame('LEFT', Enrolment::query()->where('session_id', $old->id)->value('status'));
            $this->assertSame('ACTIVE', Enrolment::query()->where('student_id', Student::query()->where('index_number', '1830')->value('id'))->value('status'));

            // student_subject -> registered subjects (+ those implied by scores).
            $this->assertGreaterThanOrEqual(2, EnrolmentSubject::query()->count());

            // Scores: 3 imported; cross-class row and unknown exam skipped.
            $this->assertSame(3, SubjectScore::query()->count());
            $math = SubjectScore::query()->where('legacy_id', 1)->first()->fresh();
            $this->assertSame('77.00', $math->total);
            $this->assertFalse($math->absent);

            // Term report: days + comments from the first exam row of the enrolment/term.
            $report = TermReport::query()->where('days_present', 55)->first();
            $this->assertSame(5, $report->days_absent);
            $this->assertSame('Good progress', $report->teacher_comment);
            $this->assertSame(4, $report->score('honesty'));
            $this->assertNull($report->score('neatness'));
        });
    }

    public function test_import_is_idempotent(): void
    {
        $count = fn () => Tenant::run($this->school, fn () => [
            Staff::query()->count(), Student::query()->count(), Guardian::query()->count(),
            User::query()->where('school_id', $this->school->id)->count(), AcademicSession::query()->count(), Term::query()->count(),
            \App\Models\SchoolClass::query()->count(), Subject::query()->count(), ClassSubject::query()->count(),
            Enrolment::query()->count(), EnrolmentSubject::query()->count(), SubjectScore::query()->count(),
            TermReport::query()->count(), LegacyLink::query()->count(),
        ]);

        Artisan::call('legacy:import', ['schoolSlug' => 'secondary', '--sync' => true]);
        $first = $count();
        $this->assertGreaterThan(0, $first[0]);

        // Run again, and with tiny chunks (different job boundaries): nothing is duplicated.
        Artisan::call('legacy:import', ['schoolSlug' => 'secondary', '--sync' => true]);
        $this->assertSame($first, $count());
        Artisan::call('legacy:import', ['schoolSlug' => 'secondary', '--sync' => true, '--people-chunk' => 1, '--score-chunk' => 1]);
        $this->assertSame($first, $count());
    }

    public function test_existing_records_are_adopted_not_duplicated(): void
    {
        Tenant::run($this->school, function () {
            Student::create(['index_number' => '1722', 'full_name' => 'Entered By Hand', 'status' => 'ACTIVE']);
            Subject::create(['name' => 'Mathematics']);
        });

        Artisan::call('legacy:import', ['schoolSlug' => 'secondary', '--sync' => true]);

        Tenant::run($this->school, function () {
            $this->assertSame(3, Student::query()->count());
            $this->assertSame('Entered By Hand', Student::query()->where('index_number', '1722')->value('full_name'));
            $this->assertSame(1, Subject::query()->where('name', 'Mathematics')->count());
            $this->assertSame(3, SubjectScore::query()->count());
        });
    }

    public function test_import_only_touches_the_target_school(): void
    {
        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);

        Artisan::call('legacy:import', ['schoolSlug' => 'secondary', '--sync' => true]);

        Tenant::run($other, function () {
            $this->assertSame(0, Student::query()->count());
            $this->assertSame(0, LegacyLink::query()->count());
        });
        $this->assertSame(0, User::query()->where('school_id', $other->id)->count());
    }

    // ---------------------------------------------------------------- Fixture

    private function breakLegacyConnection(): void
    {
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/legacy.sqlite', 'prefix' => '']]);
        DB::purge('legacy');
    }

    private function useLegacyFixture(): void
    {
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('legacy');

        $schema = Schema::connection('legacy');
        $db = DB::connection('legacy');

        foreach (['teacher' => 'T', 'admin' => 'A', 'accountant' => 'C', 'registrar' => 'R'] as $table => $_) {
            $schema->create($table, function (Blueprint $t) {
                $t->integer('id')->primary();
                $t->integer('index_number');
                $t->string('full_name');
                $t->string('gender')->default('');
                $t->string('address')->default('');
                $t->string('phone')->default('');
                $t->string('email')->default('');
            });
        }
        $schema->create('student', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('index_number');
            $t->string('full_name');
            $t->string('gender')->default('');
            $t->string('address')->default('');
            $t->string('phone')->default('');
            $t->string('email')->default('');
            $t->string('b_date')->default('0000-00-00');
            $t->string('_status')->nullable();
        });
        $schema->create('parents', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('index_number');
            $t->integer('my_son_index');
            $t->string('full_name');
            $t->string('gender')->default('');
            $t->string('address')->default('');
            $t->string('phone')->default('');
            $t->string('email')->default('');
        });
        $schema->create('user', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('email');
            $t->string('password');
            $t->string('type');
        });
        $schema->create('grade', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('name');
            $t->double('admission_fee')->default(0);
            $t->integer('hall_charge')->default(0);
            $t->integer('teacher_id')->default(0);
        });
        $schema->create('department', function (Blueprint $t) {
            $t->integer('dept_id')->primary();
            $t->string('dept_name');
            $t->integer('teacher_id')->default(0);
        });
        $schema->create('subject', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('name');
            $t->integer('dept_id')->default(0);
        });
        $schema->create('subject_routing', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('grade_id');
            $t->integer('subject_id');
            $t->integer('teacher_id')->default(0);
            $t->double('fee')->default(0);
        });
        $schema->create('exam', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('name');
            $t->string('r_date')->default('0000-00-00');
            $t->integer('schopen')->default(0);
        });
        $schema->create('student_grade', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('index_number');
            $t->integer('grade_id');
            $t->integer('year');
        });
        $schema->create('student_subject', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('index_number');
            $t->integer('sr_id');
        });
        $schema->create('student_exam', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('index_number');
            $t->integer('grade_id');
            $t->integer('exam_id');
            $t->integer('subject_id');
            $t->integer('CA1')->default(0);
            $t->integer('CA2')->default(0);
            $t->integer('CA3')->default(0);
            $t->integer('marks')->default(0);
            $t->integer('Total')->default(0);
            $t->string('pcomment')->default('');
            $t->string('tcomment')->default('');
            $t->integer('present')->default(0);
            $t->integer('absent')->default(0);
            foreach (['attendance', 'honesty', 'neatness', 'attentiveness', 'dexterity', 'handwriting', 'verbal', 'sports'] as $trait) {
                $t->integer($trait)->default(0);
            }
        });

        $db->table('teacher')->insert(['id' => 10, 'index_number' => 101, 'full_name' => 'Titus Solomon ', 'gender' => 'Male', 'email' => 'T1@legacy.test', 'phone' => '111']);
        $db->table('admin')->insert(['id' => 1, 'index_number' => 1, 'full_name' => 'Principal', 'gender' => 'Female', 'email' => 'principal@legacy.test']);
        $db->table('accountant')->insert(['id' => 1, 'index_number' => 1, 'full_name' => 'Accountant', 'email' => 'accountant@legacy.test']);
        $db->table('registrar')->insert(['id' => 1, 'index_number' => 1, 'full_name' => 'Registrar', 'email' => 'registrar@legacy.test']);

        $db->table('user')->insert([
            ['id' => 1, 'email' => 't1@legacy.test', 'password' => '12345', 'type' => 'teacher'],
            ['id' => 2, 'email' => 'principal@legacy.test', 'password' => '12345', 'type' => 'Admin'],
            ['id' => 3, 'email' => 'registrar@legacy.test', 'password' => '12345', 'type' => 'registrar'],
            ['id' => 4, 'email' => 'stud1@legacy.test', 'password' => self::SECRET, 'type' => 'student'],
            ['id' => 5, 'email' => 'parent1@legacy.test', 'password' => '12345', 'type' => 'parent'],
        ]); // no row for accountant@legacy.test

        $db->table('student')->insert([
            ['id' => 34, 'index_number' => 1722, 'full_name' => 'ADEKUNLE ADEBOLA', 'gender' => 'female', 'email' => 'stud1@legacy.test', 'b_date' => '2010-05-04', '_status' => null],
            ['id' => 35, 'index_number' => 1830, 'full_name' => 'Afia Uwana', 'gender' => 'Male', 'email' => '', 'b_date' => '0000-00-00', '_status' => null],
            ['id' => 36, 'index_number' => 1999, 'full_name' => 'Gone Kid', 'gender' => 'Male', 'email' => '', 'b_date' => '0000-00-00', '_status' => 'leave'],
        ]);
        $db->table('parents')->insert(['id' => 30, 'index_number' => 'G-1722', 'my_son_index' => 1722, 'full_name' => 'ADEKUNLE SENIOR', 'gender' => 'Male', 'email' => 'parent1@legacy.test']);

        $db->table('grade')->insert([
            ['id' => 14, 'name' => 'JS1 Diamond', 'admission_fee' => 50000, 'hall_charge' => 10500, 'teacher_id' => 10],
            ['id' => 15, 'name' => 'SS1 Gold', 'admission_fee' => 70000, 'hall_charge' => 10500, 'teacher_id' => 0],
        ]);
        $db->table('department')->insert([['dept_id' => 8, 'dept_name' => 'Mathematics', 'teacher_id' => 10], ['dept_id' => 10, 'dept_name' => 'Language ', 'teacher_id' => 0]]);
        $db->table('subject')->insert([['id' => 15, 'name' => 'Mathematics', 'dept_id' => 8], ['id' => 16, 'name' => 'English', 'dept_id' => 10]]);
        $db->table('subject_routing')->insert([
            ['id' => 17, 'grade_id' => 14, 'subject_id' => 15, 'teacher_id' => 10, 'fee' => 250],
            ['id' => 18, 'grade_id' => 14, 'subject_id' => 16, 'teacher_id' => 0, 'fee' => 250],
        ]);
        $db->table('exam')->insert([
            ['id' => 12, 'name' => '2022/2023 First Term', 'r_date' => '2023-01-09', 'schopen' => 60],
            ['id' => 15, 'name' => '2023/2024 First Term', 'r_date' => '0000-00-00', 'schopen' => 0],
            ['id' => 99, 'name' => 'Mock exam', 'r_date' => '0000-00-00', 'schopen' => 0],
        ]);
        $db->table('student_grade')->insert([['id' => 1, 'index_number' => 1830, 'grade_id' => 14, 'year' => 2023]]);
        $db->table('student_subject')->insert([['id' => 1, 'index_number' => 1722, 'sr_id' => 18]]);

        $exam = fn (array $row) => $row + [
            'CA1' => 0, 'CA2' => 0, 'CA3' => 0, 'marks' => 0, 'Total' => 0, 'pcomment' => '', 'tcomment' => '',
            'present' => 0, 'absent' => 0, 'honesty' => 0,
        ];
        $db->table('student_exam')->insert(array_map(fn ($r) => $r + array_fill_keys(['attendance', 'neatness', 'attentiveness', 'dexterity', 'handwriting', 'verbal', 'sports'], 0), [
            $exam(['id' => 1, 'index_number' => 1722, 'grade_id' => 14, 'exam_id' => 12, 'subject_id' => 15, 'CA1' => 8, 'CA2' => 9, 'CA3' => 10, 'marks' => 50, 'Total' => 77, 'present' => 55, 'absent' => 5, 'tcomment' => 'Good progress', 'honesty' => 4]),
            $exam(['id' => 2, 'index_number' => 1722, 'grade_id' => 14, 'exam_id' => 12, 'subject_id' => 16, 'CA1' => 5, 'CA2' => 5, 'CA3' => 5, 'marks' => 30, 'Total' => 45, 'present' => 55, 'absent' => 5]),
            $exam(['id' => 3, 'index_number' => 1722, 'grade_id' => 14, 'exam_id' => 15, 'subject_id' => 15, 'CA1' => 6, 'CA2' => 6, 'CA3' => 6, 'marks' => 40, 'Total' => 58]),
            // Recorded against a grade the student was not enrolled in that session: skipped.
            $exam(['id' => 4, 'index_number' => 1722, 'grade_id' => 15, 'exam_id' => 12, 'subject_id' => 15, 'marks' => 10, 'Total' => 10]),
            // Unparseable exam: skipped.
            $exam(['id' => 5, 'index_number' => 1830, 'grade_id' => 14, 'exam_id' => 99, 'subject_id' => 15, 'marks' => 10, 'Total' => 10]),
        ]));
    }
}
