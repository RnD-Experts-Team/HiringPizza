<?php

namespace Tests\Feature\Tcp;

use App\Models\Employee;
use App\Models\EmployeeId;
use App\Models\ExternalIdType;
use App\Models\HiringOutboxEvent;
use App\Models\IdType;
use App\Models\Store;
use App\Models\TcpJobCode;
use App\Services\EmployeeWorkflowService;
use App\Services\Tcp\FakeTcpEmployeeClient;
use App\Services\Tcp\TcpException;
use App\Services\Tcp\TcpJobCodeNotMappedException;
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
        // "Restaurant Id" custom field, not by their description. Seeded
        // into the LOCAL mirror — jobCodeCatalog() reads tcp_job_codes, not
        // the live client, so seeding the fake client alone would leave the
        // catalog empty.
        TcpJobCode::query()->create(['tcp_job_code_id' => '37950101', 'description' => 'Crew Member - 3795-01', 'store_number' => '03795-00001', 'clockable' => true, 'is_active' => true]);
        TcpJobCode::query()->create(['tcp_job_code_id' => '37950103', 'description' => 'Manager - 3795-01', 'store_number' => '03795-00001', 'clockable' => true, 'is_active' => true]);
        TcpJobCode::query()->create(['tcp_job_code_id' => '37950201', 'description' => 'Crew Member - 3795-02', 'store_number' => '03795-00002', 'clockable' => true, 'is_active' => true]);
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
            'positions' => [
                ['position_id' => $this->positionId('Crew Member'), 'effective_date' => '2026-01-15'],
            ],
        ], $overrides);
    }

    public function test_creating_an_employee_pushes_them_to_tcp(): void
    {
        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $this->assertCount(1, $this->tcp->employees);

        $remote = array_values($this->tcp->employees)[0];

        // employeeId is OUR OWN candidate, offset far from our auto-increment
        // range so it doesn't collide with the live roster's native ids.
        // exportCode still carries our raw id, for the exportCode roster scan.
        $this->assertNotSame((string) $employee->id, (string) $remote['employeeId']);
        $this->assertSame((string) (9000000 + $employee->id * 100), $remote['employeeId']);
        $this->assertSame((string) $employee->id, $remote['exportCode']);

        $this->assertSame('marco@example.com', $remote['email']);
        $this->assertSame('2026-01-15', $remote['hireDate']);
        // workStatus, not employeeType — TCP's schema has no employeeType field.
        $this->assertSame('FullTime', $remote['workStatus']);
        // location is the store number — TCP Locations are named by it.
        $this->assertSame('03795-00001', $remote['location']);

        // The only three fields TCP's schema does not mark nullable. Omitting
        // any of them is what "The cell must have a value" turned out to mean.
        $this->assertFalse($remote['assignEmpAccess']);
        $this->assertFalse($remote['infoOverrideRole']);
        $this->assertFalse($remote['jobsOverrideRole']);

        // Not a real TCP field — confirms we're not still sending it.
        $this->assertArrayNotHasKey('employeeType', $remote);
        $this->assertArrayNotHasKey('middleName', $remote);
    }

    public function test_employee_id_is_omitted_and_tcp_auto_generates_when_the_flag_is_off(): void
    {
        config(['tcp.assign_employee_id' => false]);

        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $remote = array_values($this->tcp->employees)[0];

        // TCP assigned it, per its own documented behaviour for a null value —
        // the fake client's auto-increment stands in for that here.
        $this->assertNotSame((string) (9000000 + $employee->id * 100), $remote['employeeId']);
        $this->assertSame((string) $employee->id, $remote['exportCode']);
    }

    public function test_a_phone_contact_is_sent_as_cell_not_phone(): void
    {
        app(EmployeeWorkflowService::class)->create($this->store, $this->payload([
            'contacts' => [
                ['contact_name' => 'Work', 'contact_type' => 'email', 'contact_value' => 'marco@example.com', 'is_primary' => true],
                ['contact_name' => 'Mobile', 'contact_type' => 'phone', 'contact_value' => '555-0100', 'is_primary' => true],
            ],
        ]));

        $remote = array_values($this->tcp->employees)[0];

        // TCP's field is `cell` — there is no `phone` field in its schema, so
        // sending the wrong key silently drops the value.
        $this->assertSame('555-0100', $remote['cell']);
        $this->assertArrayNotHasKey('phone', $remote);
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
        // payload()'s default position is already 'Crew Member' for this store.
        app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

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

    public function test_a_taken_employee_id_candidate_is_retried_with_the_next_one(): void
    {
        // Create locally only, so we get a real employee id to compute the
        // candidate from before anything is pushed to TCP.
        config(['tcp.writes_enabled' => false]);
        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $mapper = app(\App\Services\Tcp\TcpEmployeeMapper::class);
        $baseCandidate = (string) $mapper->candidateEmployeeId($employee);

        // Someone else already occupies the id we'd pick first.
        $this->tcp->seed($baseCandidate, ['firstName' => 'Someone', 'lastName' => 'Else']);

        config(['tcp.writes_enabled' => true]);
        $tcpId = app(\App\Services\Tcp\TcpEmployeeSyncService::class)->upsert($employee, $this->store);

        $this->assertNotSame($baseCandidate, $tcpId);
        $this->assertSame((string) ((int) $baseCandidate + 1), $tcpId);
        // The seeded conflict plus the one that actually landed.
        $this->assertCount(2, $this->tcp->employees);
    }

    public function test_exhausting_every_employee_id_candidate_throws_and_creates_nothing(): void
    {
        config(['tcp.writes_enabled' => false]);
        $employee = app(EmployeeWorkflowService::class)->create($this->store, $this->payload());

        $mapper = app(\App\Services\Tcp\TcpEmployeeMapper::class);

        // Occupy every candidate the retry loop will try (attempts 0..4).
        for ($attempt = 0; $attempt <= 4; $attempt++) {
            $this->tcp->seed((string) $mapper->candidateEmployeeId($employee, $attempt));
        }

        config(['tcp.writes_enabled' => true]);

        try {
            app(\App\Services\Tcp\TcpEmployeeSyncService::class)->upsert($employee, $this->store);
            $this->fail('Expected every candidate to be rejected.');
        } catch (TcpException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        // Only the 5 pre-seeded conflicts — nothing from our side landed.
        $this->assertCount(5, $this->tcp->employees);
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

    public function test_an_unresolvable_job_code_rolls_the_whole_creation_back_locally(): void
    {
        // No position, no TCP_DEFAULT_JOB_CODE — nothing to resolve.
        config(['tcp.default_job_code' => null]);

        try {
            app(EmployeeWorkflowService::class)->create($this->store, $this->payload(['positions' => []]));
            $this->fail('Expected TcpJobCodeNotMappedException.');
        } catch (TcpJobCodeNotMappedException) {
            // expected — proves the original "cell must have a value" failure
            // is now caught locally, before ever reaching TCP.
        }

        $this->assertSame(0, Employee::count());
        $this->assertSame(0, HiringOutboxEvent::count());
        $this->assertCount(0, $this->tcp->employees);
    }

    public function test_a_valid_default_job_code_fallback_lets_an_unmatched_create_succeed(): void
    {
        TcpJobCode::query()->create(['tcp_job_code_id' => '99999999', 'description' => 'Regular', 'store_number' => null, 'clockable' => true, 'is_active' => true]);
        config(['tcp.default_job_code' => '99999999']);

        app(EmployeeWorkflowService::class)->create($this->store, $this->payload(['positions' => []]));

        $remote = array_values($this->tcp->employees)[0];
        $this->assertSame(99999999, $remote['defaultJobCode']);
    }

    public function test_a_default_job_code_absent_from_the_local_catalog_still_throws(): void
    {
        // Configured, but never synced — a typo or a stale value.
        config(['tcp.default_job_code' => '00000000']);

        try {
            app(EmployeeWorkflowService::class)->create($this->store, $this->payload(['positions' => []]));
            $this->fail('Expected TcpJobCodeNotMappedException.');
        } catch (TcpJobCodeNotMappedException) {
            // expected
        }

        $this->assertSame(0, Employee::count());
    }

    private function positionId(string $label): int
    {
        return (int) \App\Models\Position::query()->firstOrCreate(['label' => $label])->id;
    }
}
