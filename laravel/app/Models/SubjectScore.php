<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectScore extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'subject_scores';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'enrolment_id',
        'class_subject_id',
        'term_id',
        'ca1',
        'ca2',
        'ca3',
        'exam_score',
        'absent',
        'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'ca1' => 'decimal:2',
            'ca2' => 'decimal:2',
            'ca3' => 'decimal:2',
            'exam_score' => 'decimal:2',
            'absent' => 'boolean',
            'legacy_id' => 'integer',
            'total' => 'decimal:2',
        ];
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class, 'class_subject_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
