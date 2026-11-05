<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Staff extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'staff';

    protected $fillable = [
        'school_id',
        'index_number',
        'first_name',
        'last_name',
        'gender',
        'phone',
        'email',
        'address',
        'photo_file_id',
        'is_teacher',
        'status',
        'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'is_teacher' => 'boolean',
            'legacy_id' => 'integer',
        ];
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'photo_file_id');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class, 'teacher_id');
    }

    public function formClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'form_teacher_id');
    }

    public function lessonPlans(): HasMany
    {
        return $this->hasMany(LessonPlan::class, 'author_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
