<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Guardian extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'guardians';

    protected $fillable = [
        'school_id',
        'full_name',
        'phone',
        'email',
        'address',
        'gender',
        'legacy_index',
    ];

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function studentGuardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardians')->withPivot('relationship');
    }
}
