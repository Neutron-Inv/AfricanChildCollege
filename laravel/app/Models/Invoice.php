<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasUlids, BelongsToSchool;

    protected $table = 'invoices';

    const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'student_id',
        'term_id',
        'class_id',
        'status',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getTotalAttribute(): string
    {
        return (string) $this->lines()->sum('amount');
    }

    public function getPaidAttribute(): string
    {
        return (string) $this->payments()->sum('amount');
    }

    /** Sum of the invoice lines (uses the withSum() aggregate when loaded). */
    public function getTotalAmountAttribute(): float
    {
        return round((float) ($this->lines_sum_amount ?? $this->lines()->sum('amount')), 2);
    }

    /** Sum of the payments (uses the withSum() aggregate when loaded). */
    public function getPaidAmountAttribute(): float
    {
        return round((float) ($this->payments_sum_amount ?? $this->payments()->sum('amount')), 2);
    }

    public function getBalanceAttribute(): float
    {
        return round($this->total_amount - $this->paid_amount, 2);
    }
}
