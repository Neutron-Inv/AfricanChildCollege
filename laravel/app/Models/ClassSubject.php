<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSubject extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'class_subjects';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'class_id',
        'subject_id',
        'teacher_id',
        'kind',
        'fee',
        'legacy_routing_id',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'legacy_routing_id' => 'integer',
        ];
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'teacher_id');
    }

    public function enrolmentSubjects(): HasMany
    {
        return $this->hasMany(EnrolmentSubject::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(SubjectScore::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(Period::class);
    }

    public function lessonPlans(): HasMany
    {
        return $this->hasMany(LessonPlan::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
}
