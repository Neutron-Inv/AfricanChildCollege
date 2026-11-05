<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\School;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;

class SchoolsSeeder extends Seeder
{
    public function run(): void
    {
        $secondary = School::updateOrCreate(['slug' => 'secondary'], [
            'name' => 'African Child Secondary',
            'subdomain' => 'secondary',
            'address' => 'Byazhin, Kubwa, Abuja',
            'email' => 'Info4@africanchildcollege.org',
            'phone' => '',
            'motto' => 'Nursery/Primary/Secondary',
            'primary_color' => '#a01010',
        ]);

        School::updateOrCreate(['slug' => 'primary'], [
            'name' => 'African Child Primary',
            'subdomain' => 'primary',
            'primary_color' => '#a01010',
        ]);

        School::updateOrCreate(['slug' => 'bwari'], [
            'name' => 'African Child Bwari',
            'subdomain' => 'bwari',
            'primary_color' => '#a01010',
        ]);

        $this->seedAdmin($secondary);
    }

    private function seedAdmin(School $school): void
    {
        $email = env('SEED_ADMIN_EMAIL');
        $password = env('SEED_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD not set - skipping school admin.');

            return;
        }

        $existing = User::query()->where('school_id', $school->id)->where('email', $email)->first();
        if ($existing) {
            $existing->update([
                'password' => $password,
                'role' => Role::ADMIN,
                'must_change_password' => false,
            ]);

            return;
        }

        $staff = Staff::firstOrCreate(
            ['school_id' => $school->id, 'index_number' => 'ADM-001'],
            [
                'first_name' => 'School',
                'last_name' => 'Admin',
                'email' => $email,
                'is_teacher' => false,
            ],
        );

        User::updateOrCreate(
            ['school_id' => $school->id, 'email' => $email],
            [
                'password' => $password, // hashed by the model cast
                'role' => Role::ADMIN,
                'must_change_password' => false,
                'staff_id' => $staff->id,
            ],
        );
    }
}
