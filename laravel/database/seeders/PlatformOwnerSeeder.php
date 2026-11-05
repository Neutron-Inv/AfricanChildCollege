<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class PlatformOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SEED_PLATFORM_EMAIL');
        $password = env('SEED_PLATFORM_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('SEED_PLATFORM_EMAIL / SEED_PLATFORM_PASSWORD not set - skipping platform owner.');

            return;
        }

        // Platform owner has no school (school_id = null).
        User::updateOrCreate(
            ['email' => $email, 'school_id' => null],
            [
                'password' => $password, // hashed by the model cast
                'role' => Role::PLATFORM_OWNER,
                'must_change_password' => false,
            ],
        );
    }
}
