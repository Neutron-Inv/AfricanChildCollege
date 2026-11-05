<?php

namespace App\Models;

use App\Enums\ReportTrait;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermReportRating extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'term_report_ratings';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'term_report_id',
        'trait',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'trait' => ReportTrait::class,
            'score' => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(TermReport::class, 'term_report_id');
    }
}
