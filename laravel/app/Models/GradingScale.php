<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingScale extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'grading_scales';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'name',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function bands(): HasMany
    {
        return $this->hasMany(GradingBand::class, 'scale_id');
    }
}
