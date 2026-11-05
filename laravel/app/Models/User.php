<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Users are intentionally NOT tenant-scoped (no BelongsToSchool global scope):
 * the auth guard must be able to resolve any user (including the platform owner,
 * whose school_id is null) regardless of the active tenant.
 *
 * @property-read string $name
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'school_id',
        'email',
        'password',
        'role',
        'must_change_password',
        'staff_id',
        'student_id',
        'guardian_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'must_change_password' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /**
     * Name derived from the linked staff / student / guardian record, falling back to email.
     */
    protected function name(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->staff_id && $this->staff) {
                return $this->staff->full_name ?: (string) $this->email;
            }
            if ($this->student_id && $this->student) {
                return $this->student->full_name ?: (string) $this->email;
            }
            if ($this->guardian_id && $this->guardian) {
                return $this->guardian->full_name ?: (string) $this->email;
            }

            return (string) $this->email;
        });
    }

    public function isPlatformOwner(): bool
    {
        return $this->role === Role::PLATFORM_OWNER;
    }

    public function hasRole(Role|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $role = $role instanceof Role ? $role : Role::tryFrom($role);
            if ($role !== null && $this->role === $role) {
                return true;
            }
        }

        return false;
    }
}
