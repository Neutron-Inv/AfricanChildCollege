<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Arm extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'arms';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'name',
    ];

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }
}
