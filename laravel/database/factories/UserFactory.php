<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'school_id' => null,
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::ADMIN,
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function platformOwner(): static
    {
        return $this->state(['school_id' => null, 'role' => Role::PLATFORM_OWNER]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(['must_change_password' => true]);
    }
}
