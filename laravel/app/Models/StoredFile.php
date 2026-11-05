<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StoredFile extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'stored_files';

    const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'purpose',
        'storage_key',
        'mime_type',
        'size',
        'original_name',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }
}
