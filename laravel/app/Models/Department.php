<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'departments';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'name',
        'hod_id',
    ];

    public function hod(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'hod_id');
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }
}
