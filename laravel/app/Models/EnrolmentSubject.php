<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrolmentSubject extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'enrolment_subjects';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'enrolment_id',
        'class_subject_id',
    ];

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class, 'class_subject_id');
    }
}
