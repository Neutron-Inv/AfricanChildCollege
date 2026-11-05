<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Period extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'periods';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'class_subject_id',
        'room_id',
        'day',
        'start_time',
        'end_time',
    ];

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class, 'class_subject_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
