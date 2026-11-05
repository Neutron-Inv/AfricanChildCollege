<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LegacyLink extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'legacy_links';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'entity',
        'legacy_key',
        'record_id',
    ];
}
