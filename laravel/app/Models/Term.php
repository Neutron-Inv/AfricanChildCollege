<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Term extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'terms';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'session_id',
        'sequence',
        'name',
        'days_school_open',
        'result_date',
        'legacy_exam_id',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'days_school_open' => 'integer',
            'result_date' => 'date',
            'legacy_exam_id' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function subjectScores(): HasMany
    {
        return $this->hasMany(SubjectScore::class);
    }

    public function termReports(): HasMany
    {
        return $this->hasMany(TermReport::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
