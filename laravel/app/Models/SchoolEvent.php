<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SchoolEvent extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'school_events';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'all_day',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
        ];
    }
}
