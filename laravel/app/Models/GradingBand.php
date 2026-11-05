<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradingBand extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'grading_bands';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'scale_id',
        'min_score',
        'max_score',
        'letter',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'min_score' => 'integer',
            'max_score' => 'integer',
        ];
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class, 'scale_id');
    }
}
