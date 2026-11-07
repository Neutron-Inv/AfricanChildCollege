<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TimetableSlot extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'timetable_slots';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'day_group',
        'sequence',
        'kind',
        'label',
        'start_time',
        'end_time',
    ];
}
