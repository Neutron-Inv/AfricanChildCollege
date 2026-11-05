<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Enrolment;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\StudentGuardian;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for school fees: the fee-item catalogue, invoice generation and payments.
 *
 * Scope of a fee item:
 *  - class_id set            -> only that class;
 *  - class_id null + session -> every class of that session;
 *  - neither                 -> every class, every session.
 *
 * An invoice belongs to one student for one term (unique), and its lines are a snapshot of the
 * applicable fee items at generation time. Status is stored: DRAFT -> ISSUED -> PARTIAL -> PAID,
 * or CANCELLED (only while nothing has been paid).
 */
class FeesService
{
    public const STATUSES = ['DRAFT', 'ISSUED', 'PARTIAL', 'PAID', 'CANCELLED'];

    public const METHODS = [
        'CASH' => 'Cash',
        'TRANSFER' => 'Bank transfer',
        'POS' => 'POS',
        'CHEQUE' => 'Cheque',
        'ONLINE' => 'Online',
        'OTHER' => 'Other',
    ];

    public const MAX_AMOUNT = 9999999999.99;

    // ----------------------------------------------------------------- Access

    /** Roles that can create fee items, generate invoices and record payments. */
    public function canManage(User $user): bool
    {
        return $user->isPlatformOwner() || $user->hasRole(Role::ADMIN, Role::ACCOUNTANT);
    }

    /** Roles that can see the whole catalogue and every invoice (read-only for the registrar). */
    public function canViewAll(User $user): bool
    {
        return $this->canManage($user) || $user->hasRole(Role::REGISTRAR);
    }

    // ---------------------------------------------------------------- Lookups

    public function currentSession(): ?AcademicSession
    {
        return AcademicSession::query()->where('is_current', true)->first();
    }

    /** Terms, newest session first then by sequence. */
    public function terms(): Collection
    {
        return Term::query()->with('session')->get()
            ->sortBy([
                fn ($a, $b) => ($b->session?->start_year ?? 0) <=> ($a->session?->start_year ?? 0),
                fn ($a, $b) => $a->sequence <=> $b->sequence,
            ])
            ->values();
    }

    /** All classes with level/arm/session, newest session first then by level order and arm. */
    public function classes(): Collection
    {
        return SchoolClass::query()->with(['level', 'arm', 'session'])->get()
            ->sortBy([
                fn ($a, $b) => ($b->session?->start_year ?? 0) <=> ($a->session?->start_year ?? 0),
                fn ($a, $b) => ($a->level?->sort_order ?? 0) <=> ($b->level?->sort_order ?? 0),
                fn ($a, $b) => strcmp((string) $a->level?->name, (string) $b->level?->name),
                fn ($a, $b) => strcmp((string) $a->arm?->name, (string) $b->arm?->name),
            ])
            ->values();
    }

    public function sessions(): Collection
    {
        return AcademicSession::query()->orderByDesc('start_year')->get();
    }

    // ------------------------------------------------------------ Fee items

    public function feeItems(): Collection
    {
        return FeeItem::query()->with(['class.level', 'class.arm', 'class.session', 'session'])->get()
            ->sortBy([
                fn ($a, $b) => $this->scopeRank($a) <=> $this->scopeRank($b),
                fn ($a, $b) => strcasecmp((string) $a->name, (string) $b->name),
            ])
            ->values();
    }

    private function scopeRank(FeeItem $item): int
    {
        return $item->class_id ? 2 : ($item->session_id ? 1 : 0);
    }

    public function scopeLabel(FeeItem $item): string
    {
        if ($item->class_id) {
            return 'Class: '.($item->class?->display_name ?: '—').($item->class?->session ? ' ('.$item->class->session->name.')' : '');
        }
        if ($item->session_id) {
            return 'Session: '.($item->session?->name ?: '—');
        }

        return 'Whole school';
    }

    /**
     * @param  array{name: string, amount: mixed, class_id?: ?string, session_id?: ?string}  $data
     */
    public function saveFeeItem(array $data, ?FeeItem $item = null): FeeItem
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new DomainException('Enter a name for the fee item.');
        }

        $amount = $this->parseAmount($data['amount'] ?? null, 'The fee amount');

        $classId = ($data['class_id'] ?? null) ?: null;
        $sessionId = ($data['session_id'] ?? null) ?: null;

        if ($classId !== null && ! SchoolClass::query()->whereKey($classId)->exists()) {
            throw new DomainException('Choose a class of this school.');
        }
        if ($classId === null && $sessionId !== null && ! AcademicSession::query()->whereKey($sessionId)->exists()) {
            throw new DomainException('Choose a session of this school.');
        }
        // A class already belongs to one session, so a class scope wins over a session scope.
        if ($classId !== null) {
            $sessionId = null;
        }

        $duplicate = FeeItem::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($classId, fn (Builder $q) => $q->where('class_id', $classId), fn (Builder $q) => $q->whereNull('class_id'))
            ->when($sessionId, fn (Builder $q) => $q->where('session_id', $sessionId), fn (Builder $q) => $q->whereNull('session_id'))
            ->when($item, fn (Builder $q) => $q->whereKeyNot($item->getKey()))
            ->exists();
        if ($duplicate) {
            throw new DomainException('A fee item with this name already exists for that scope.');
        }

        $attrs = ['name' => $name, 'amount' => $amount, 'class_id' => $classId, 'session_id' => $sessionId];

        if ($item) {
            $item->update($attrs);
            AuditService::record('update', 'FeeItem', $item->getKey(), ['name' => $name, 'amount' => $amount]);
        } else {
            $item = FeeItem::create($attrs);
            AuditService::record('create', 'FeeItem', $item->getKey(), ['name' => $name, 'amount' => $amount]);
        }

        return $item;
    }

    // -------------------------------------------------------------- Invoices

    /** Fee items that apply to a class of the given session. */
    public function applicableItems(SchoolClass $class, ?Collection $items = null): Collection
    {
        $items ??= FeeItem::query()->get();

        return $items->filter(function (FeeItem $item) use ($class) {
            if ($item->class_id !== null) {
                return $item->class_id === $class->getKey();
            }

            return $item->session_id === null || $item->session_id === $class->session_id;
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Create one invoice (with lines) per ACTIVE enrolment of the term's session, for one class or the
     * whole school. Students who already have an invoice for the term, or for whom no fee item applies,
     * are skipped, so running it twice is safe.
     *
     * @param  string  $status  DRAFT or ISSUED
     * @return array{created: int, existing: int, no_fees: int}
     */
    public function generateInvoices(Term $term, ?SchoolClass $class = null, string $status = 'ISSUED'): array
    {
        if (! in_array($status, ['DRAFT', 'ISSUED'], true)) {
            throw new DomainException('New invoices must be drafts or issued.');
        }
        if ($class && $class->session_id !== $term->session_id) {
            throw new DomainException('That class does not belong to the session of the chosen term.');
        }

        $items = FeeItem::query()->get();
        $classes = SchoolClass::query()->where('session_id', $term->session_id)->get()->keyBy('id');

        $enrolments = Enrolment::query()
            ->where('session_id', $term->session_id)
            ->where('status', 'ACTIVE')
            ->when($class, fn (Builder $q) => $q->where('class_id', $class->getKey()))
            ->get()
            ->unique('student_id');

        $existing = Invoice::query()->where('term_id', $term->getKey())->pluck('student_id')->flip();

        $result = ['created' => 0, 'existing' => 0, 'no_fees' => 0];

        foreach ($enrolments as $enrolment) {
            if ($existing->has($enrolment->student_id)) {
                $result['existing']++;

                continue;
            }

            $enrolmentClass = $classes->get($enrolment->class_id);
            $applicable = $enrolmentClass ? $this->applicableItems($enrolmentClass, $items) : collect();
            if ($applicable->isEmpty()) {
                $result['no_fees']++;

                continue;
            }

            DB::transaction(function () use ($enrolment, $term, $status, $applicable) {
                $invoice = Invoice::create([
                    'student_id' => $enrolment->student_id,
                    'term_id' => $term->getKey(),
                    'class_id' => $enrolment->class_id,
                    'status' => $status,
                ]);

                foreach ($applicable as $item) {
                    InvoiceLine::create([
                        'invoice_id' => $invoice->getKey(),
                        'description' => $item->name,
                        'amount' => $item->amount,
                    ]);
                }
            });

            $result['created']++;
        }

        AuditService::record('generate', 'Invoice', $term->getKey(), $result + [
            'term_id' => $term->getKey(),
            'class_id' => $class?->getKey(),
            'status' => $status,
        ]);

        return $result;
    }

    /** DRAFT -> ISSUED. */
    public function issue(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'DRAFT') {
            throw new DomainException('Only draft invoices can be issued.');
        }

        $invoice->update(['status' => 'ISSUED']);
        AuditService::record('issue', 'Invoice', $invoice->getKey());

        return $invoice;
    }

    /** Cancel an invoice that has no payments. */
    public function cancel(Invoice $invoice): Invoice
    {
        if ($invoice->status === 'CANCELLED') {
            throw new DomainException('This invoice is already cancelled.');
        }
        if ($invoice->payments()->exists()) {
            throw new DomainException('An invoice with payments cannot be cancelled.');
        }

        $invoice->update(['status' => 'CANCELLED']);
        AuditService::record('cancel', 'Invoice', $invoice->getKey());

        return $invoice;
    }

    /**
     * Filtered invoice query (no eager loads / aggregates).
     *
     * @param  array{term_id?: ?string, class_id?: ?string, status?: ?string, q?: ?string}  $filters
     */
    public function invoiceQuery(User $user, array $filters = []): Builder
    {
        $query = Invoice::query();

        if (! $this->canViewAll($user)) {
            // Parents only ever see their own children's invoices; DRAFT invoices are not yet billed to them.
            $studentIds = $user->hasRole(Role::PARENT) && $user->guardian_id
                ? StudentGuardian::query()->where('guardian_id', $user->guardian_id)->pluck('student_id')->all()
                : [];
            $query->whereIn('student_id', $studentIds)->where('status', '!=', 'DRAFT');
        }

        $query
            ->when($filters['term_id'] ?? null, fn (Builder $q, $v) => $q->where('term_id', $v))
            ->when($filters['class_id'] ?? null, fn (Builder $q, $v) => $q->where('class_id', $v))
            ->when(in_array($filters['status'] ?? null, self::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(trim((string) ($filters['q'] ?? '')) !== '', function (Builder $q) use ($filters) {
                $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';
                $q->whereHas('student', fn (Builder $s) => $s->where('full_name', 'like', $needle)->orWhere('index_number', 'like', $needle));
            });

        return $query;
    }

    public function invoices(User $user, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->invoiceQuery($user, $filters)
            ->with(['student', 'class.level', 'class.arm', 'term.session', 'lines', 'payments.receivedBy'])
            ->withSum('lines', 'amount')
            ->withSum('payments', 'amount')
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Billed / collected / outstanding across the filtered invoices (drafts and cancelled invoices excluded).
     *
     * @return array{billed: float, collected: float, outstanding: float}
     */
    public function totals(User $user, array $filters = []): array
    {
        $ids = $this->invoiceQuery($user, $filters)->whereIn('status', ['ISSUED', 'PARTIAL', 'PAID'])->select('invoices.id');

        $billed = (float) InvoiceLine::query()->whereIn('invoice_id', clone $ids)->sum('amount');
        $collected = (float) Payment::query()->whereIn('invoice_id', clone $ids)->sum('amount');

        return [
            'billed' => round($billed, 2),
            'collected' => round($collected, 2),
            'outstanding' => round($billed - $collected, 2),
        ];
    }

    // -------------------------------------------------------------- Payments

    /**
     * Record a payment against an invoice and move its status to PARTIAL or PAID.
     *
     * @param  array{amount: mixed, method: string, paid_on: string, reference?: ?string, note?: ?string}  $data
     */
    public function recordPayment(User $user, Invoice $invoice, array $data): Payment
    {
        $amount = $this->parseAmount($data['amount'] ?? null, 'The payment amount');

        $method = strtoupper((string) ($data['method'] ?? ''));
        if (! array_key_exists($method, self::METHODS)) {
            throw new DomainException('Choose a payment method.');
        }

        $paidOn = $this->parseDate($data['paid_on'] ?? null);

        return DB::transaction(function () use ($user, $invoice, $amount, $method, $paidOn, $data) {
            // Lock the invoice so two concurrent payments cannot overpay it.
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['DRAFT', 'CANCELLED'], true)) {
                throw new DomainException('Payments can only be recorded against an issued invoice.');
            }

            $total = $this->cents(InvoiceLine::query()->where('invoice_id', $locked->getKey())->sum('amount'));
            $paid = $this->cents(Payment::query()->where('invoice_id', $locked->getKey())->sum('amount'));
            $balance = $total - $paid;

            if ($balance <= 0) {
                throw new DomainException('This invoice is already fully paid.');
            }
            if ($this->cents($amount) > $balance) {
                throw new DomainException('The payment exceeds the outstanding balance of '.number_format($balance / 100, 2).'.');
            }

            $payment = Payment::create([
                'invoice_id' => $locked->getKey(),
                'amount' => $amount,
                'method' => $method,
                'reference' => $this->clean($data['reference'] ?? null, 100),
                'paid_on' => $paidOn->toDateString(),
                'note' => (string) $this->clean($data['note'] ?? null, 255),
                'received_by_id' => $user->getKey(),
            ]);

            $locked->update(['status' => $this->cents($amount) === $balance ? 'PAID' : 'PARTIAL']);

            AuditService::record('payment', 'Invoice', $locked->getKey(), [
                'payment_id' => $payment->getKey(),
                'amount' => $amount,
                'method' => $method,
            ]);

            return $payment;
        });
    }

    // --------------------------------------------------------------- Helpers

    public function parseDate(?string $date): Carbon
    {
        $date = trim((string) $date);
        $parsed = $date === '' ? null : Carbon::createFromFormat('!Y-m-d', $date);

        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException('Enter a valid date (YYYY-MM-DD).');
        }

        return $parsed;
    }

    private function parseAmount(mixed $value, string $label): float
    {
        if (! is_numeric($value)) {
            throw new DomainException("{$label} must be a number.");
        }

        $amount = round((float) $value, 2);
        if ($amount <= 0) {
            throw new DomainException("{$label} must be greater than zero.");
        }
        if ($amount > self::MAX_AMOUNT) {
            throw new DomainException("{$label} is too large.");
        }

        return $amount;
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
