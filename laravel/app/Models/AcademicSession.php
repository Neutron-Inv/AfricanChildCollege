<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicSession extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'academic_sessions';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'name',
        'start_year',
        'end_year',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'end_year' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class, 'session_id');
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'session_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class, 'session_id');
    }
}
