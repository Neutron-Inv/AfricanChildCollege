<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'students';

    protected $fillable = [
        'school_id',
        'index_number',
        'full_name',
        'gender',
        'phone',
        'email',
        'address',
        'birth_date',
        'photo_file_id',
        'status',
        'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
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

    public function studentGuardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Note: create links via StudentGuardian::create() so id/school_id are populated.
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardians')->withPivot('relationship');
    }
}
