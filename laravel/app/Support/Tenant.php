<?php

namespace App\Support;

use App\Models\School;

/**
 * Holds the school (tenant) for the current request.
 */
final class Tenant
{
    public const SESSION_KEY = 'active_school_id';

    private static ?School $school = null;

    public static function set(?School $school): void
    {
        self::$school = $school;
    }

    public static function get(): ?School
    {
        return self::$school;
    }

    public static function id(): ?string
    {
        return self::$school?->getKey();
    }

    public static function check(): bool
    {
        return self::$school !== null;
    }

    public static function clear(): void
    {
        self::$school = null;
    }

    /**
     * School a platform owner has "entered" (stored in session), if any.
     */
    public static function activeSchoolId(): ?string
    {
        if (! app()->bound('session')) {
            return null;
        }

        $id = app('session')->get(self::SESSION_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public static function setActiveSchoolId(?string $id): void
    {
        if (! app()->bound('session')) {
            return;
        }

        if ($id === null) {
            app('session')->forget(self::SESSION_KEY);
        } else {
            app('session')->put(self::SESSION_KEY, $id);
        }
    }

    /**
     * Run a callback with a given tenant, restoring the previous one afterwards.
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public static function run(?School $school, callable $callback): mixed
    {
        $previous = self::$school;
        self::$school = $school;

        try {
            return $callback();
        } finally {
            self::$school = $previous;
        }
    }
}
