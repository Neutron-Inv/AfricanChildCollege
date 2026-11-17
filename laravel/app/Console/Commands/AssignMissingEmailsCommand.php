<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\School;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class AssignMissingEmailsCommand extends Command
{
    private const DOMAIN = 'africanchildcollege.org';

    private const PASSWORD = '12345';

    protected $signature = 'people:assign-missing-emails
        {school : School slug, for example primary or secondary}
        {--dry-run : Show the emails without saving}';

    protected $description = 'Give every student and staff member with no email an address from their name and index number, and a login.';

    public function handle(): int
    {
        $school = School::query()->where('slug', $this->argument('school'))->first();
        if (! $school) {
            $this->error('No school with slug "'.$this->argument('school').'".');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $assigned = 0;
        $skipped = 0;

        Tenant::run($school, function () use ($school, $dryRun, &$assigned, &$skipped) {
            foreach (Student::query()->with('user')->orderBy('full_name')->get() as $student) {
                $this->assign($school, $student, $student->full_name, (string) $student->index_number, Role::STUDENT, 'student_id', $dryRun, $assigned, $skipped);
            }

            foreach (Staff::query()->with('user')->orderBy('last_name')->get() as $staff) {
                $role = $staff->user?->role ?? ($staff->is_teacher ? Role::TEACHER : null);
                $this->assign($school, $staff, $staff->full_name, (string) $staff->index_number, $role, 'staff_id', $dryRun, $assigned, $skipped);
            }
        });

        $this->info(sprintf(
            '%d email%s assigned, %d skipped%s. New logins use password %s.',
            $assigned,
            $assigned === 1 ? '' : 's',
            $skipped,
            $dryRun ? ' (dry run)' : '',
            self::PASSWORD,
        ));

        return self::SUCCESS;
    }

    private function assign(School $school, Model $person, string $name, string $index, ?Role $role, string $link, bool $dryRun, int &$assigned, int &$skipped): void
    {
        if (filled($person->email)) {
            return;
        }

        $existing = $person->user?->email;
        if (filled($existing)) {
            if (! $dryRun) {
                $person->update(['email' => $existing]);
            }
            $this->line("{$name}: kept login {$existing}.");
            $assigned++;

            return;
        }

        $email = $this->emailFor($name, $index);
        if ($email === null) {
            $this->warn("{$name}: no email, the name or index number is missing.");
            $skipped++;

            return;
        }

        if ($role === null) {
            $this->warn("{$name}: email would be {$email}, but this staff member has no login role.");
            $skipped++;

            return;
        }

        if ($this->emailTaken($school, $email, $person)) {
            $this->warn("{$name}: {$email} is already used.");
            $skipped++;

            return;
        }

        if (! $dryRun) {
            $person->update(['email' => $email]);
            User::create([
                'school_id' => $school->getKey(),
                'email' => $email,
                'password' => self::PASSWORD,
                'role' => $role,
                'must_change_password' => false,
                $link => $person->getKey(),
            ]);
        }

        $this->line("{$name}: {$email}");
        $assigned++;
    }

    private function emailFor(string $name, string $index): ?string
    {
        $first = strtolower(preg_split('/\s+/', trim($name), 2)[0] ?? '');
        $first = preg_replace('/[^a-z0-9]/', '', $first) ?? '';
        $index = preg_replace('/[^a-z0-9]/', '', strtolower($index)) ?? '';

        if ($first === '' || $index === '') {
            return null;
        }

        return $first.'.'.$index.'@'.self::DOMAIN;
    }

    private function emailTaken(School $school, string $email, Model $person): bool
    {
        if (User::query()->where('school_id', $school->getKey())->where('email', $email)->exists()) {
            return true;
        }

        $student = Student::query()->where('email', $email);
        $staff = Staff::query()->where('email', $email);
        if ($person instanceof Student) {
            $student->where('id', '!=', $person->getKey());
        }
        if ($person instanceof Staff) {
            $staff->where('id', '!=', $person->getKey());
        }

        return $student->exists() || $staff->exists();
    }
}
