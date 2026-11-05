<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\GenerateInvoicesJob;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\Enrolment;
use App\Models\FeeItem;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Level;
use App\Models\Payment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Term;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FeesTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://fee.localhost';

    private School $school;

    private School $other;

    private AcademicSession $session;

    private Term $term;

    private SchoolClass $classA;

    private SchoolClass $classB;

    private Student $kay;

    private Student $zed;

    private Student $bee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->school = School::create(['name' => 'Fee School', 'slug' => 'fee', 'subdomain' => 'fee']);
        $this->other = School::create(['name' => 'Other School', 'slug' => 'oth', 'subdomain' => 'oth']);

        Tenant::run($this->school, function () {
            $this->session = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027, 'is_current' => true]);
            $this->term = Term::create(['session_id' => $this->session->id, 'sequence' => 1, 'name' => 'First Term']);
            $level = Level::create(['name' => 'JSS1']);
            $this->classA = SchoolClass::create(['session_id' => $this->session->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
            $this->classB = SchoolClass::create(['session_id' => $this->session->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);

            $this->kay = $this->enrol($this->classA, 'S1', 'Kay Ade');
            $this->zed = $this->enrol($this->classA, 'S2', 'Zed Bello');
            $this->bee = $this->enrol($this->classB, 'S3', 'Bee Cole');
            $this->enrol($this->classA, 'S4', 'Lee Gone', 'WITHDRAWN');

            FeeItem::create(['name' => 'Tuition', 'amount' => 50000]);                                  // whole school
            FeeItem::create(['name' => 'Lab', 'amount' => 5000, 'class_id' => $this->classA->id]);        // class A only
            FeeItem::create(['name' => 'Bus', 'amount' => 1000, 'session_id' => $this->session->id]);     // this session
        });
    }

    private function enrol(SchoolClass $class, string $index, string $name, string $status = 'ACTIVE'): Student
    {
        $student = Student::create(['index_number' => $index, 'full_name' => $name, 'gender' => 'Male', 'status' => 'ACTIVE']);
        Enrolment::create(['student_id' => $student->id, 'class_id' => $class->id, 'session_id' => $this->session->id, 'status' => $status]);

        return $student;
    }

    private function as(Role $role, array $attrs = []): static
    {
        return $this->actingAs(User::factory()->create(['school_id' => $this->school->id, 'role' => $role] + $attrs));
    }

    private function in(callable $fn): mixed
    {
        return Tenant::run($this->school, $fn);
    }

    private function generate(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(self::HOST.'/fees/invoices/generate', $data + ['term_id' => $this->term->id]);
    }

    private function invoiceFor(Student $student): ?Invoice
    {
        return $this->in(fn () => Invoice::query()->where('student_id', $student->id)->with(['lines', 'payments'])->first());
    }

    private function issued(Student $student, string $status = 'ISSUED'): Invoice
    {
        $this->as(Role::ACCOUNTANT)->generate(['class_id' => $this->classA->id, 'draft' => $status === 'DRAFT' ? 1 : 0])->assertSessionHasNoErrors();

        return $this->invoiceFor($student);
    }

    // ------------------------------------------------------------ Access

    public function test_fees_access_by_role(): void
    {
        foreach ([Role::ADMIN, Role::ACCOUNTANT, Role::REGISTRAR, Role::PARENT] as $role) {
            $this->as($role)->get(self::HOST.'/fees')->assertOk();
        }
        foreach ([Role::TEACHER, Role::STUDENT] as $role) {
            $this->as($role)->get(self::HOST.'/fees')->assertForbidden();
        }
    }

    public function test_registrar_and_parent_cannot_manage(): void
    {
        $this->as(Role::REGISTRAR);
        $this->post(self::HOST.'/fees/items', ['name' => 'X', 'amount' => 10])->assertForbidden();
        $this->generate()->assertForbidden();

        $this->as(Role::PARENT);
        $this->post(self::HOST.'/fees/items', ['name' => 'X', 'amount' => 10])->assertForbidden();
        $this->generate()->assertForbidden();

        $this->assertSame(3, $this->in(fn () => FeeItem::count()));
    }

    public function test_nav_shows_enabled_fees_link(): void
    {
        $this->as(Role::ACCOUNTANT)->get(self::HOST.'/dashboard')->assertOk()->assertSee(route('fees'), false);
        $this->as(Role::REGISTRAR)->get(self::HOST.'/dashboard')->assertSee(route('fees'), false);
    }

    // --------------------------------------------------------- Fee items

    public function test_accountant_creates_and_edits_fee_items_with_scopes(): void
    {
        $this->as(Role::ACCOUNTANT);

        $this->post(self::HOST.'/fees/items', ['name' => 'Sports', 'amount' => '2500.50', 'class_id' => $this->classB->id, 'session_id' => $this->session->id])
            ->assertRedirect()->assertSessionHas('success');

        $item = $this->in(fn () => FeeItem::query()->where('name', 'Sports')->first());
        $this->assertSame($this->classB->id, $item->class_id);
        $this->assertNull($item->session_id, 'class scope overrides session scope');
        $this->assertSame('2500.50', $item->amount);

        $this->put(self::HOST.'/fees/items/'.$item->id, ['name' => 'Sports kit', 'amount' => 3000, 'session_id' => $this->session->id])
            ->assertSessionHas('success');
        $item->refresh();
        $this->assertSame('Sports kit', $item->name);
        $this->assertNull($item->class_id);
        $this->assertSame($this->session->id, $item->session_id);
    }

    public function test_fee_item_validation_and_duplicates(): void
    {
        $this->as(Role::ADMIN);

        $this->post(self::HOST.'/fees/items', ['name' => 'Bad', 'amount' => 0])->assertSessionHasErrors('amount');
        $this->post(self::HOST.'/fees/items', ['name' => '', 'amount' => 10])->assertSessionHasErrors('name');
        $this->post(self::HOST.'/fees/items', ['name' => 'tuition', 'amount' => 10])->assertSessionHas('error');
        $this->post(self::HOST.'/fees/items', ['name' => 'Ghost', 'amount' => 10, 'class_id' => 'nope'])->assertSessionHas('error');

        $this->assertSame(3, $this->in(fn () => FeeItem::count()));
    }

    public function test_fee_items_page_lists_scopes(): void
    {
        $this->as(Role::REGISTRAR)->get(self::HOST.'/fees?tab=items')
            ->assertOk()->assertSee('Tuition')->assertSee('Whole school')->assertSee('Lab')->assertSee('Session: 2026/2027');

        // Parents have no catalogue tab.
        $this->as(Role::PARENT)->get(self::HOST.'/fees?tab=items')->assertOk()->assertDontSee('Tuition');
    }

    public function test_cannot_edit_another_schools_fee_item(): void
    {
        $foreign = Tenant::run($this->other, fn () => FeeItem::create(['name' => 'Theirs', 'amount' => 1]));

        $this->as(Role::ADMIN)->put(self::HOST.'/fees/items/'.$foreign->id, ['name' => 'Hacked', 'amount' => 1])->assertNotFound();
        $this->assertSame('Theirs', FeeItem::withoutGlobalScopes()->find($foreign->id)->name);
    }

    // ---------------------------------------------------------- Generate

    public function test_generating_for_a_class_builds_invoices_from_applicable_items_for_active_enrolments(): void
    {
        $this->as(Role::ACCOUNTANT)->generate(['class_id' => $this->classA->id])->assertSessionHas('success');

        $kay = $this->invoiceFor($this->kay);
        $this->assertSame('ISSUED', $kay->status);
        $this->assertSame($this->classA->id, $kay->class_id);
        $this->assertEqualsCanonicalizing(['Tuition', 'Lab', 'Bus'], $kay->lines->pluck('description')->all());
        $this->assertEquals(56000, $kay->total_amount);
        $this->assertEquals(56000, $kay->balance);

        $this->assertNotNull($this->invoiceFor($this->zed));
        $this->assertNull($this->invoiceFor($this->bee), 'other class untouched');
        $this->assertSame(2, $this->in(fn () => Invoice::count()), 'withdrawn student is not invoiced');
    }

    public function test_whole_school_generation_is_queued_via_job_and_is_class_aware(): void
    {
        Queue::fake();

        $this->as(Role::ADMIN)->generate()->assertSessionHas('success');
        Queue::assertPushed(GenerateInvoicesJob::class, fn ($job) => $job->schoolId === $this->school->id
            && $job->termId === $this->term->id && $job->classId === null && $job->status === 'ISSUED');
        $this->assertSame(0, $this->in(fn () => Invoice::count()));
    }

    public function test_job_generates_for_whole_school(): void
    {
        (new GenerateInvoicesJob($this->school->id, $this->term->id))->handle(app(\App\Services\FeesService::class));

        $this->assertEquals(56000, $this->invoiceFor($this->kay)->total_amount);
        $this->assertEquals(51000, $this->invoiceFor($this->bee)->total_amount, 'class B does not pay the class A lab fee');
        $this->assertSame(3, $this->in(fn () => Invoice::count()));
        $this->assertFalse(Tenant::check() && Tenant::id() !== null, 'tenant restored after job');
    }

    public function test_generation_is_idempotent_and_skips_students_without_fees(): void
    {
        $this->as(Role::ACCOUNTANT);
        $this->generate(['class_id' => $this->classA->id]);
        $this->generate(['class_id' => $this->classA->id])->assertSessionHas('success', fn ($m) => str_contains($m, '0 invoice(s) created; 2 already invoiced'));
        $this->assertSame(2, $this->in(fn () => Invoice::count()));

        $this->in(fn () => FeeItem::query()->delete());
        $this->generate(['class_id' => $this->classB->id])->assertSessionHas('success', fn ($m) => str_contains($m, '0 invoice(s) created') && str_contains($m, '1 without applicable fees'));
        $this->assertNull($this->invoiceFor($this->bee));
    }

    public function test_generate_can_create_drafts_and_rejects_class_from_other_session(): void
    {
        $this->as(Role::ACCOUNTANT);
        $this->generate(['class_id' => $this->classA->id, 'draft' => 1]);
        $this->assertSame('DRAFT', $this->invoiceFor($this->kay)->status);

        $other = $this->in(function () {
            $s = AcademicSession::create(['name' => '2027/2028', 'start_year' => 2027, 'end_year' => 2028]);

            return SchoolClass::create(['session_id' => $s->id, 'level_id' => Level::first()->id, 'arm_id' => Arm::first()->id]);
        });
        $this->generate(['class_id' => $other->id])->assertSessionHas('error');
        $this->generate(['term_id' => 'missing'])->assertSessionHas('error');
    }

    // ---------------------------------------------------------- Payments

    private function pay(Invoice $invoice, array $data = [])
    {
        return $this->post(self::HOST.'/fees/invoices/'.$invoice->id.'/payments', $data + [
            'amount' => 10000, 'method' => 'TRANSFER', 'reference' => 'TRX-1', 'paid_on' => '2026-10-29',
        ]);
    }

    public function test_partial_then_full_payment_updates_status_and_balance(): void
    {
        $invoice = $this->issued($this->kay);

        $this->pay($invoice, ['amount' => 20000])->assertSessionHas('success');
        $invoice = $this->invoiceFor($this->kay);
        $this->assertSame('PARTIAL', $invoice->status);
        $this->assertEquals(36000, $invoice->balance);
        $payment = $invoice->payments->first();
        $this->assertSame('TRANSFER', $payment->method);
        $this->assertSame('TRX-1', $payment->reference);
        $this->assertSame('2026-10-29', $payment->paid_on->toDateString());
        $this->assertNotNull($payment->received_by_id);

        $this->pay($invoice, ['amount' => 36000, 'method' => 'CASH', 'reference' => ''])->assertSessionHas('success');
        $invoice = $this->invoiceFor($this->kay);
        $this->assertSame('PAID', $invoice->status);
        $this->assertEquals(0, $invoice->balance);

        $this->pay($invoice, ['amount' => 1])->assertSessionHas('error');
        $this->assertSame(2, $this->in(fn () => Payment::count()));
    }

    public function test_payment_rules(): void
    {
        $invoice = $this->issued($this->kay);

        $this->pay($invoice, ['amount' => 56000.01])->assertSessionHas('error');   // overpay
        $this->pay($invoice, ['amount' => 0])->assertSessionHasErrors('amount');
        $this->pay($invoice, ['amount' => 'abc'])->assertSessionHasErrors('amount');
        $this->pay($invoice, ['method' => 'BARTER'])->assertSessionHas('error');
        $this->pay($invoice, ['paid_on' => '29/10/2026'])->assertSessionHasErrors('paid_on');
        $this->assertSame(0, $this->in(fn () => Payment::count()));
        $this->assertSame('ISSUED', $this->invoiceFor($this->kay)->status);

        // Exact full payment in one go.
        $this->pay($invoice, ['amount' => 56000])->assertSessionHas('success');
        $this->assertSame('PAID', $this->invoiceFor($this->kay)->status);
    }

    public function test_no_payment_on_draft_or_cancelled_invoice_and_cancel_rules(): void
    {
        $draft = $this->issued($this->kay, 'DRAFT');
        $this->pay($draft)->assertSessionHas('error');
        $this->assertSame(0, $this->in(fn () => Payment::count()));

        $this->post(self::HOST.'/fees/invoices/'.$draft->id.'/issue')->assertSessionHas('success');
        $this->assertSame('ISSUED', $this->invoiceFor($this->kay)->status);
        $this->post(self::HOST.'/fees/invoices/'.$draft->id.'/issue')->assertSessionHas('error');

        $zed = $this->invoiceFor($this->zed);
        $this->post(self::HOST.'/fees/invoices/'.$zed->id.'/cancel')->assertSessionHas('success');
        $this->assertSame('CANCELLED', $this->invoiceFor($this->zed)->status);
        $this->pay($zed)->assertSessionHas('error');

        // A paid-on invoice cannot be cancelled.
        $this->pay($draft, ['amount' => 100])->assertSessionHas('success');
        $this->post(self::HOST.'/fees/invoices/'.$draft->id.'/cancel')->assertSessionHas('error');
        $this->assertSame('PARTIAL', $this->invoiceFor($this->kay)->status);
    }

    public function test_registrar_cannot_record_payment_or_cancel(): void
    {
        $invoice = $this->issued($this->kay);

        $this->as(Role::REGISTRAR);
        $this->pay($invoice)->assertForbidden();
        $this->post(self::HOST.'/fees/invoices/'.$invoice->id.'/cancel')->assertForbidden();
        $this->assertSame(0, $this->in(fn () => Payment::count()));
    }

    public function test_cannot_pay_another_schools_invoice(): void
    {
        $foreign = Tenant::run($this->other, function () {
            $level = Level::create(['name' => 'X']);
            $session = AcademicSession::create(['name' => '2026/2027', 'start_year' => 2026, 'end_year' => 2027]);
            $term = Term::create(['session_id' => $session->id, 'sequence' => 1, 'name' => 'First Term']);
            $class = SchoolClass::create(['session_id' => $session->id, 'level_id' => $level->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
            $student = Student::create(['index_number' => 'Z1', 'full_name' => 'Foreign', 'gender' => 'Male', 'status' => 'ACTIVE']);
            $invoice = Invoice::create(['student_id' => $student->id, 'term_id' => $term->id, 'class_id' => $class->id, 'status' => 'ISSUED']);
            $invoice->lines()->create(['description' => 'Fee', 'amount' => 100]);

            return $invoice;
        });

        $this->as(Role::ADMIN)->post(self::HOST.'/fees/invoices/'.$foreign->id.'/payments', [
            'amount' => 10, 'method' => 'CASH', 'paid_on' => '2026-10-29',
        ])->assertNotFound();
    }

    // ------------------------------------------------------------- Lists

    public function test_invoice_list_shows_student_amount_status_balance_and_filters(): void
    {
        $this->as(Role::ACCOUNTANT)->generate(['class_id' => $this->classA->id]);
        $kay = $this->invoiceFor($this->kay);
        $this->pay($kay, ['amount' => 6000]);

        $this->get(self::HOST.'/fees')
            ->assertOk()
            ->assertSee('Kay Ade')->assertSee('Zed Bello')
            ->assertSee('56,000.00')->assertSee('50,000.00')   // kay balance
            ->assertSee('Partial')->assertSee('Issued')
            ->assertSee('Record payment');

        $this->get(self::HOST.'/fees?status=PARTIAL')->assertSee('Kay Ade')->assertDontSee('Zed Bello');
        $this->get(self::HOST.'/fees?q=zed')->assertSee('Zed Bello')->assertDontSee('Kay Ade');
        $this->get(self::HOST.'/fees?class_id='.$this->classB->id)->assertDontSee('Kay Ade');
    }

    public function test_totals_exclude_cancelled_and_drafts(): void
    {
        $this->as(Role::ACCOUNTANT)->generate(['class_id' => $this->classA->id]);
        $this->generate(['class_id' => $this->classB->id, 'draft' => 1]);
        $this->pay($this->invoiceFor($this->kay), ['amount' => 6000]);
        $this->post(self::HOST.'/fees/invoices/'.$this->invoiceFor($this->zed)->id.'/cancel');

        $totals = app(\App\Services\FeesService::class)->totals(auth()->user(), []);
        // Only Kay's invoice counts as billed.
        $this->assertSame(['billed' => 56000.0, 'collected' => 6000.0, 'outstanding' => 50000.0], $totals);
    }

    public function test_registrar_sees_invoices_read_only(): void
    {
        $this->as(Role::ACCOUNTANT)->generate(['class_id' => $this->classA->id]);

        $this->as(Role::REGISTRAR)->get(self::HOST.'/fees')
            ->assertOk()->assertSee('Kay Ade')->assertSee('view-only')
            ->assertDontSee('Record payment')->assertDontSee('Generate invoices');
    }

    public function test_parent_sees_only_own_childrens_issued_invoices(): void
    {
        $this->as(Role::ACCOUNTANT)->generate(['class_id' => $this->classA->id]);
        $this->generate(['class_id' => $this->classB->id, 'draft' => 1]);

        $guardian = $this->in(function () {
            $g = Guardian::create(['full_name' => 'Mrs Ade', 'phone' => '0800']);
            StudentGuardian::create(['student_id' => $this->kay->id, 'guardian_id' => $g->id, 'relationship' => 'mother']);
            StudentGuardian::create(['student_id' => $this->bee->id, 'guardian_id' => $g->id, 'relationship' => 'mother']);

            return $g;
        });

        $this->as(Role::PARENT, ['guardian_id' => $guardian->id]);
        $this->get(self::HOST.'/fees')
            ->assertOk()
            ->assertSee('Kay Ade')
            ->assertDontSee('Zed Bello')     // not their child
            ->assertDontSee('Bee Cole')      // draft, not yet billed
            ->assertDontSee('Record payment');

        $kay = $this->invoiceFor($this->kay);
        $this->pay($kay)->assertForbidden();
    }

    public function test_invoices_are_tenant_scoped(): void
    {
        $this->as(Role::ACCOUNTANT)->generate(['class_id' => $this->classA->id]);

        $otherAdmin = User::factory()->create(['school_id' => $this->other->id, 'role' => Role::ADMIN]);
        $this->actingAs($otherAdmin)->get('http://oth.localhost/fees')->assertOk()->assertDontSee('Kay Ade');
    }
}
