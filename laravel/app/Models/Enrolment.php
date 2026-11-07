<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrolment extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'enrolments';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'student_id',
        'class_id',
        'session_id',
        'status',
        'outcome',
        'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'legacy_id' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(EnrolmentSubject::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(SubjectScore::class);
    }

    public function termReports(): HasMany
    {
        return $this->hasMany(TermReport::class);
    }
}
