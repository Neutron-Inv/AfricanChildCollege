<?php

namespace App\Services\Legacy;

use Carbon\Carbon;
use Throwable;

/**
 * Pure value mapping helpers for the legacy school database (port of the Nest importer's helpers).
 */
final class LegacyMapper
{
    /** Arm names the school normally uses; anything else is flagged as unusual in the report. */
    public const USUAL_ARMS = ['diamond', 'gold', 'pearl', 'emerald', 'joy', 'peace', 'success'];

    /** Primary levels. Longest name wins when splitting a class such as "Nursery 1 Joy". */
    public const PRIMARY_LEVELS = [
        'Preparatory',
        'Reception',
        'Pre-Nursery',
        'Nursery 1',
        'Nursery 2',
        'Primary 1',
        'Primary 2',
        'Primary 3',
        'Primary 4',
        'Primary 5',
    ];

    /** Teaching order, including the creche and the secondary years used at Bwari. */
    public const LEVEL_ORDER = [
        'Creche',
        'Preparatory',
        'Reception',
        'Pre-Nursery',
        'Nursery 1',
        'Nursery 2',
        'Primary 1',
        'Primary 2',
        'Primary 3',
        'Primary 4',
        'Primary 5',
        'JSS 1',
        'JSS 2',
        'JSS 3',
        'SSS 1',
        'SSS 2',
        'SSS 3',
    ];

    public static function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    public static function email(mixed $value): string
    {
        $email = strtolower(self::text($value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    public static function gender(mixed $value): string
    {
        $raw = strtolower(self::text($value));

        return match ($raw) {
            'm', 'male' => 'Male',
            'f', 'female' => 'Female',
            default => self::text($value),
        };
    }

    public static function date(mixed $value): ?string
    {
        $value = self::text($value);
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::parse(substr($value, 0, 10))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    public static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    public static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** Behaviour trait ratings are 1-5; anything else means "not rated". */
    public static function trait(mixed $value): ?int
    {
        $rating = (int) round(self::number($value));

        return $rating >= 1 && $rating <= 5 ? $rating : null;
    }

    /**
     * "JS1 Diamond", "SSS 2 Gold", or "Nursery 1 Joy" => level and arm.
     * A primary level with no stream ("Reception") has an empty arm.
     * Anything else => level "Other".
     *
     * @return array{level: string, arm: string, unusual: bool}
     */
    public static function className(?string $name): array
    {
        $cleaned = trim((string) preg_replace('/\s+/', ' ', (string) $name));

        if (preg_match('/^(JS|JSS|SS|SSS)\s*([123])\s+(.+)$/i', $cleaned, $m)) {
            $band = str_starts_with(strtoupper($m[1]), 'J') ? 'JS' : 'SS';
            $arm = trim($m[3]);

            return [
                'level' => $band.$m[2],
                'arm' => $arm,
                'unusual' => ! in_array(strtolower($arm), self::USUAL_ARMS, true),
            ];
        }

        if (preg_match('/^(JS|JSS|SS|SSS)\s*([123])$/i', $cleaned, $m)) {
            $band = str_starts_with(strtoupper($m[1]), 'J') ? 'JSS' : 'SSS';

            return ['level' => $band.' '.$m[2], 'arm' => '', 'unusual' => false];
        }

        if (strcasecmp($cleaned, 'Creche') === 0) {
            return ['level' => 'Creche', 'arm' => '', 'unusual' => false];
        }

        $primary = self::primaryClassName($cleaned);
        if ($primary !== null) {
            return $primary;
        }

        return ['level' => 'Other', 'arm' => $cleaned !== '' ? $cleaned : 'Unknown', 'unusual' => true];
    }

    /**
     * @return array{level: string, arm: string, unusual: bool}|null
     */
    private static function primaryClassName(string $cleaned): ?array
    {
        $levels = self::PRIMARY_LEVELS;
        usort($levels, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($levels as $level) {
            if (strcasecmp($cleaned, $level) === 0) {
                return ['level' => $level, 'arm' => '', 'unusual' => false];
            }

            $prefix = $level.' ';
            if (stripos($cleaned, $prefix) !== 0) {
                continue;
            }

            $arm = self::primaryArm(trim(substr($cleaned, strlen($prefix))));

            return [
                'level' => $level,
                'arm' => $arm,
                'unusual' => ! in_array(strtolower($arm), self::USUAL_ARMS, true),
            ];
        }

        return null;
    }

    private static function primaryArm(string $arm): string
    {
        return match (strtolower($arm)) {
            'joy' => 'Joy',
            'peace' => 'Peace',
            'success' => 'Success',
            default => $arm,
        };
    }

    /**
     * "2023/2024 First Term" => session + term; null when the name does not follow the pattern.
     *
     * @return array{session: string, start_year: int, end_year: int, sequence: int, term_name: string}|null
     */
    public static function examName(?string $name): ?array
    {
        if (! preg_match('/^(\d{4})\/(\d{4})\s+(First|Second|Third)\s+Term$/i', trim((string) $name), $m)) {
            return null;
        }

        $word = strtolower($m[3]);

        return [
            'session' => $m[1].'/'.$m[2],
            'start_year' => (int) $m[1],
            'end_year' => (int) $m[2],
            'sequence' => ['first' => 1, 'second' => 2, 'third' => 3][$word],
            'term_name' => ucfirst($word).' Term',
        ];
    }
}
