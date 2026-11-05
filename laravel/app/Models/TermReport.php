<?php

namespace App\Models;

use App\Enums\ReportTrait;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TermReport extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'term_reports';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'enrolment_id',
        'term_id',
        'days_present',
        'days_absent',
        'teacher_comment',
        'principal_comment',
    ];

    protected function casts(): array
    {
        return [
            'days_present' => 'integer',
            'days_absent' => 'integer',
        ];
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(TermReportRating::class);
    }

    public function score(ReportTrait|string $trait): ?int
    {
        $value = $trait instanceof ReportTrait ? $trait->value : $trait;
        $ratings = $this->relationLoaded('ratings') ? $this->ratings : $this->ratings()->get();
        $match = $ratings->first(fn (TermReportRating $rating) => $rating->trait->value === $value);

        return $match?->score;
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
