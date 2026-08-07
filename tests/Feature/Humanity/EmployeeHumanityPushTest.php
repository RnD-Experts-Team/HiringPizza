<?php

namespace Tests\Feature\Humanity;

use App\Models\Employee;
use App\Models\EmployeeId;
use App\Models\IdType;
use App\Models\Store;
use App\Services\EmployeeWorkflowService;
use App\Services\Humanity\FakeHumanityStaffClient;
use App\Services\Humanity\HumanityException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HiringPizza had no tests at all before this. These cover the failure mode the
 * Humanity push introduces on the busiest write path in the service: an
 * external call that can now roll a successful employee creation back.
 */
class EmployeeHumanityPushTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private FakeHumanityStaffClient $humanity;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'humanity.driver' => 'fake',
            'humanity.environment' => 'sandbox',
            'humanity.writes_enabled' => true,
            'nats.dev_mode' => false,
        ]);

        $this->store = Store::query()->create(['id' => 1, 'store_number' => '03759-00001']);
        $this->humanity = app(FakeHumanityStaffClient::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Marco',
            'last_name' => 'Rossi',
            'gender' => 'male',
            'ssn' => '123-45-6789',
            'employment_type' => 'W2',
            'contacts' => [
                ['contact_name' => 'Work', 'contact_type' => 'email', 'contact_value' => 'marco@example.com', 'is_primary' => true],
            ],
            'pay_history' => [
                ['base_pay' => 16.50, 'performance_pay' => 0, 'effective_date' => '2026-01-15'],
            ],
            'status_history' => [
                ['status' => 'hired', 'effective_date' => '2026-01-15'],
            ],
        ], $overrides);
    }

    public function test_creating_an_employee_pushes_them_to_humanity(): void
    {
        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $this->assertCount(1, $this->humanity->employees);

        $remote = array_values($this->humanity->employees)[0];
        // eid is our employee id — the whole basis of idempotent upserts.
        $this->assertSame((string) $employee->id, $remote['eid']);
        $this->assertSame('marco@example.com', $remote['email']);
        $this->assertSame(16.5, $remote['wage']);
        $this->assertSame('2026-01-15', $remote['work_start_date']);
        // W2 -> Full Time
        $this->assertSame(1, $remote['employee_type']);
        $this->assertSame(1, $remote['status']);
    }

    public function test_the_returned_id_is_stored_as_an_external_id(): void
    {
        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $idType = IdType::query()->where('label', 'Humanity ID')->first();
        $this->assertNotNull($idType);

        $link = EmployeeId::query()
            ->where('employee_id', $employee->id)
            ->where('id_type_id', $idType->id)
            ->first();

        $this->assertNotNull($link);
        // Reaches OperationsPizza for free: the snapshot serialises ids[].id_type.
        $this->assertArrayHasKey($link->id_value, $this->humanity->employees);
    }

    public function test_ssn_and_bank_details_are_never_sent(): void
    {
        app(EmployeeWorkflowService::class)->create($this->store, $this->payload([
            'financial_info' => [
                ['account_number' => '111', 'routing_number' => '222', 'account_type' => 'checking', 'effective_date' => '2026-01-15'],
            ],
        ]));

        $remote = array_values($this->humanity->employees)[0];

        foreach (['ssn', 'account_number', 'routing_number'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $remote);
        }
    }

    public function test_a_humanity_failure_rolls_the_whole_creation_back(): void
    {
        $this->humanity->failNext('createEmployee');

        try {
            app(EmployeeWorkflowService::class)->create($this->store, $this->payload());
            $this->fail('Expected the Humanity failure to propagate.');
        } catch (HumanityException) {
            // expected
        }

        // Fail-the-request: no half-created employee, no event for anyone
        // downstream to act on.
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, \App\Models\HiringOutboxEvent::count());
    }

    public function test_a_retried_push_adopts_the_existing_record_instead_of_duplicating(): void
    {
        // A previous attempt landed in Humanity but we never recorded the id —
        // exactly what a timeout looks like. Humanity has no idempotency key,
        // so the eid lookup is the only thing standing between us and a
        // duplicate person.
        $this->humanity->seed('90500', ['eid' => '1', 'email' => 'marco@example.com']);

        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $this->assertSame(1, $employee->id);
        $this->assertCount(1, $this->humanity->employees);
        $this->assertSame('90500', app(\App\Services\Humanity\HumanityEmployeeSyncService::class)
            ->existingHumanityId($employee));
    }

    public function test_a_duplicate_email_adopts_the_existing_record(): void
    {
        // Humanity answers a duplicate email with the generic code 12 and no
        // explanation, so the pre-check turns an unactionable failure into a link.
        $this->humanity->seed('90600', ['eid' => null, 'email' => 'marco@example.com']);

        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $this->assertCount(1, $this->humanity->employees);
        $this->assertSame('1', $this->humanity->employees['90600']['eid']);
        $this->assertSame('90600', app(\App\Services\Humanity\HumanityEmployeeSyncService::class)
            ->existingHumanityId($employee));
    }

    public function test_a_status_change_deactivates_the_employee_in_humanity(): void
    {
        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $this->assertSame(1, array_values($this->humanity->employees)[0]['status']);

        app(EmployeeWorkflowService::class)->changeStatus($this->store, $employee, [
            'status' => 'terminated',
            'effective_date' => '2026-06-01',
        ]);

        // Without this path a terminated employee stays schedulable.
        $this->assertSame(0, array_values($this->humanity->employees)[0]['status']);
    }

    public function test_writes_are_refused_while_the_flag_is_off(): void
    {
        // The gate that stops us duplicating the live roster before the
        // backfill has run. Nothing is pushed, and the employee is still
        // created locally — this is not an error path.
        config(['humanity.writes_enabled' => false]);

        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $this->assertSame(1, Employee::count());
        $this->assertCount(0, $this->humanity->employees);
        $this->assertNull(app(\App\Services\Humanity\HumanityEmployeeSyncService::class)
            ->existingHumanityId($employee));
    }
}
