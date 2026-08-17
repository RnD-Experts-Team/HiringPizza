<?php

namespace Tests\Feature\Tcp;

use App\Models\Employee;
use App\Models\EmployeeId;
use App\Models\ExternalIdType;
use App\Models\HiringOutboxEvent;
use App\Models\IdType;
use App\Models\Store;
use App\Services\EmployeeWorkflowService;
use App\Services\Tcp\FakeTcpEmployeeClient;
use App\Services\Tcp\TcpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The TCP employee push on the busiest write path in the service: an external
 * call that can roll a successful employee creation back. Ported from the
 * deleted EmployeeHumanityPushTest when the direct Humanity push was removed
 * (TCP's connector owns Humanity's employee records).
 */
class EmployeeTcpPushTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private FakeTcpEmployeeClient $tcp;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tcp.driver' => 'fake',
            'tcp.writes_enabled' => true,
            'nats.dev_mode' => false,
        ]);

        $this->store = Store::query()->create(['id' => 1, 'store_number' => '03795-00001']);
        $this->tcp = app(FakeTcpEmployeeClient::class);

        // The real catalog shape: per-store codes attributed by the
        // "Restaurant Id" custom field, not by their description.
        $this->tcp->seedJobCode('37950101', 'Crew Member - 3795-01', '03795-00001');
        $this->tcp->seedJobCode('37950103', 'Manager - 3795-01', '03795-00001');
        $this->tcp->seedJobCode('37950201', 'Crew Member - 3795-02', '03795-00002');
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

    public function test_creating_an_employee_pushes_them_to_tcp(): void
    {
        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $this->assertCount(1, $this->tcp->employees);

        $remote = array_values($this->tcp->employees)[0];

        // employeeId is TCP-ASSIGNED — the live roster's native ids would
        // collide with our auto-increment. exportCode carries our id instead.
        $this->assertNotSame((string) $employee->id, (string) $remote['employeeId']);
        $this->assertSame((string) $employee->id, $remote['exportCode']);

        $this->assertSame('marco@example.com', $remote['email']);
        $this->assertSame('2026-01-15', $remote['hireDate']);
        $this->assertSame('FullTime', $remote['employeeType']);
        // location is the store number — TCP Locations are named by it.
        $this->assertSame('03795-00001', $remote['location']);
    }

    public function test_the_tcp_assigned_id_is_stored_as_an_external_id(): void
    {
        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $idType = IdType::query()->where('label', ExternalIdType::TCP)->first();
        $this->assertNotNull($idType);

        $link = EmployeeId::query()
            ->where('employee_id', $employee->id)
            ->where('id_type_id', $idType->id)
            ->first();

        $this->assertNotNull($link);
        // Reaches OperationsPizza for free: the snapshot serialises ids[].id_type.
        $this->assertArrayHasKey($link->id_value, $this->tcp->employees);
    }

    public function test_ssn_and_bank_details_are_never_sent(): void
    {
        app(EmployeeWorkflowService::class)->create($this->store, $this->payload([
            'financial_info' => [
                ['account_number' => '111', 'routing_number' => '222', 'account_type' => 'checking', 'effective_date' => '2026-01-15'],
            ],
        ]));

        $remote = array_values($this->tcp->employees)[0];

        foreach (['ssn', 'account_number', 'routing_number'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $remote);
        }
    }

    public function test_the_default_job_code_matches_the_stores_catalog_entry(): void
    {
        app(EmployeeWorkflowService::class)->create($this->store, $this->payload([
            'positions' => [
                ['position_id' => $this->positionId('Crew Member'), 'effective_date' => '2026-01-15'],
            ],
        ]));

        $remote = array_values($this->tcp->employees)[0];

        // "Crew Member" + store 03795-00001 → "Crew Member - 3795-01", never
        // the other store's code.
        $this->assertSame(37950101, $remote['defaultJobCode']);
    }

    public function test_a_tcp_failure_rolls_the_whole_creation_back(): void
    {
        $this->tcp->failNext('createEmployee');

        try {
            app(EmployeeWorkflowService::class)->create($this->store, $this->payload());
            $this->fail('Expected the TCP failure to propagate.');
        } catch (TcpException) {
            // expected
        }

        // Fail-the-request: no half-created employee, no event for anyone
        // downstream to act on.
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, HiringOutboxEvent::count());
    }

    public function test_a_retried_push_adopts_the_existing_record_instead_of_duplicating(): void
    {
        // Create locally without pushing, to stand in for "a previous attempt
        // landed in TCP but we never recorded the id" — exactly what a timeout
        // looks like.
        config(['tcp.writes_enabled' => false]);
        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $this->tcp->seed('9004321', ['exportCode' => (string) $employee->id, 'email' => 'marco@example.com']);

        // The retry. TCP has no idempotency key, so the exportCode scan is the
        // only thing standing between us and a duplicate person.
        config(['tcp.writes_enabled' => true]);
        $tcpId = app(\App\Services\Tcp\TcpEmployeeSyncService::class)->upsert($employee, $this->store);

        $this->assertSame('9004321', $tcpId);
        $this->assertCount(1, $this->tcp->employees);
        $this->assertSame('9004321', app(\App\Services\Tcp\TcpEmployeeSyncService::class)
            ->existingTcpId($employee));
    }

    public function test_writes_are_refused_while_the_flag_is_off(): void
    {
        // Nothing is pushed, and the employee is still created locally — this
        // is not an error path.
        config(['tcp.writes_enabled' => false]);

        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $this->assertSame(1, Employee::count());
        $this->assertCount(0, $this->tcp->employees);
        $this->assertNull(app(\App\Services\Tcp\TcpEmployeeSyncService::class)
            ->existingTcpId($employee));
    }

    private function positionId(string $label): int
    {
        return (int) \App\Models\Position::query()->firstOrCreate(['label' => $label])->id;
    }
}
