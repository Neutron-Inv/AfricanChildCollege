<?php

namespace App\Enums;

enum Role: string
{
    case PLATFORM_OWNER = 'PLATFORM_OWNER';
    case ADMIN = 'ADMIN';
    case ACCOUNTANT = 'ACCOUNTANT';
    case REGISTRAR = 'REGISTRAR';
    case TEACHER = 'TEACHER';
    case STUDENT = 'STUDENT';
    case PARENT = 'PARENT';

    public function label(): string
    {
        return match ($this) {
            self::PLATFORM_OWNER => 'Platform Owner',
            self::ADMIN => 'Administrator',
            self::ACCOUNTANT => 'Accountant',
            self::REGISTRAR => 'Registrar',
            self::TEACHER => 'Teacher',
            self::STUDENT => 'Student',
            self::PARENT => 'Parent',
        };
    }

    /** Roles that belong to a school (everyone except the platform owner). */
    public static function schoolRoles(): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r !== self::PLATFORM_OWNER));
    }

    /** Roles that are school staff / back-office users. */
    public function isStaff(): bool
    {
        return in_array($this, [self::ADMIN, self::ACCOUNTANT, self::REGISTRAR, self::TEACHER], true);
    }
}
