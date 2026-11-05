<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'rooms';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'name',
        'capacity',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(Period::class);
    }
}
