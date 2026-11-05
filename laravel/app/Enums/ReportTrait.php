<?php

namespace App\Enums;

enum ReportTrait: string
{
    case Attendance = 'attendance';
    case Honesty = 'honesty';
    case Neatness = 'neatness';
    case Attentiveness = 'attentiveness';
    case Dexterity = 'dexterity';
    case Handwriting = 'handwriting';
    case Verbal = 'verbal';
    case Sports = 'sports';

    public function label(): string
    {
        return match ($this) {
            self::Attendance => 'Attendance',
            self::Honesty => 'Honesty',
            self::Neatness => 'Neatness',
            self::Attentiveness => 'Attentiveness',
            self::Dexterity => 'Dexterity',
            self::Handwriting => 'Handwriting',
            self::Verbal => 'Verbal fluency',
            self::Sports => 'Sports',
        };
    }

    public function domain(): string
    {
        return match ($this) {
            self::Attendance, self::Honesty, self::Neatness, self::Attentiveness => 'Affective',
            self::Dexterity, self::Handwriting, self::Verbal, self::Sports => 'Psychomotor',
        };
    }

    /** @return array<string, array<string, string>> domain => [trait value => label] */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::cases() as $trait) {
            $groups[$trait->domain()][$trait->value] = $trait->label();
        }

        return $groups;
    }
}
