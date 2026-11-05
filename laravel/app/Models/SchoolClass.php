<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'classes';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'session_id',
        'level_id',
        'arm_id',
        'form_teacher_id',
        'admission_fee',
        'hall_charge',
        'legacy_grade_id',
    ];

    protected function casts(): array
    {
        return [
            'admission_fee' => 'decimal:2',
            'hall_charge' => 'decimal:2',
            'legacy_grade_id' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function arm(): BelongsTo
    {
        return $this->belongsTo(Arm::class);
    }

    public function formTeacher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'form_teacher_id');
    }

    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class, 'class_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class, 'class_id');
    }

    public function feeItems(): HasMany
    {
        return $this->hasMany(FeeItem::class, 'class_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return trim(($this->level?->name ?? '').' '.($this->arm?->name ?? ''));
    }
}
