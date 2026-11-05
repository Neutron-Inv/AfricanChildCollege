<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonPlan extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'lesson_plans';

    protected $fillable = [
        'school_id',
        'class_subject_id',
        'term_id',
        'status',
        'week',
        'lesson_date',
        'time',
        'duration',
        'period',
        'topic',
        'sub_topic',
        'specific_objective',
        'content',
        'skills',
        'previous_knowledge',
        'materials',
        'exploration',
        'step1',
        'step2',
        'step3',
        'step4',
        'application',
        'evaluation',
        'conclusion',
        'assignment',
        'reference',
        'attachment_file_id',
        'author_id',
        'hod_id',
        'hod_signed_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'lesson_date' => 'date',
            'hod_signed_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class, 'class_subject_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'author_id');
    }

    public function hod(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'hod_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'attachment_file_id');
    }
}
