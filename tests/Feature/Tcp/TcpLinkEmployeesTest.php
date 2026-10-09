<?php

namespace Tests\Feature\Tcp;

use App\Models\Employee;
use App\Models\EmployeeId;
use App\Models\ExternalIdType;
use App\Models\HiringOutboxEvent;
use App\Models\IdType;
use App\Models\Position;
use App\Models\Store;
use App\Services\EmployeeWorkflowService;
use App\Services\Tcp\FakeTcpEmployeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * tcp:link-employees only READS TCP's roster and stores "TCP ID" on the
 * employee it can match with certainty.
 */
class TcpLinkEmployeesTest extends TestCase
{
    use RefreshDatabase;

    private FakeTcpEmployeeClient $tcp;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        // Writes OFF: employees are created locally and nothing reaches TCP.
        config(['tcp.driver' => 'fake', 'tcp.writes_enabled' => false]);

        $this->tcp = app(FakeTcpEmployeeClient::class);
        $this->store = Store::query()->create(['id' => 1, 'store_number' => '03795-00001']);
        Store::query()->create(['id' => 2, 'store_number' => '03795-00002']);
    }

    private function employee(string $first, string $last, ?string $email = null, ?Store $store = null): Employee
    {
        return app(EmployeeWorkflowService::class)->create($store ?? $this->store, [
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'male',
            'ssn' => '123-45-' . random_int(1000, 9999),
            'employment_type' => 'W2',
            'contacts' => $email === null ? [] : [
                ['contact_name' => 'Work', 'contact_type' => 'email', 'contact_value' => $email, 'is_primary' => true],
            ],
            'status_history' => [['status' => 'hired', 'effective_date' => '2026-01-15']],
            'positions' => [['position_id' => Position::query()->firstOrCreate(['label' => 'Crew Member'])->id, 'effective_date' => '2026-01-15']],
        ]);
    }

    private function tcpIdOf(Employee $employee): ?string
    {
        return EmployeeId::query()
            ->where('employee_id', $employee->id)
            ->whereHas('idType', fn ($q) => $q->where('label', ExternalIdType::TCP))
            ->value('id_value');
    }

    public function test_links_by_export_code(): void
    {
        $marco = $this->employee('Marco', 'Rossi');
        $this->tcp->seed('5896', ['firstName' => 'Someone', 'lastName' => 'Else', 'exportCode' => (string) $marco->id]);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertSame('5896', $this->tcpIdOf($marco));
    }

    public function test_links_by_name_and_store(): void
    {
        $marco = $this->employee('Marco', 'Rossi');
        $this->tcp->seed('5896', ['firstName' => 'MARCO', 'lastName' => 'Rossi', 'location' => '03795-00001']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertSame('5896', $this->tcpIdOf($marco));
    }

    public function test_links_by_name_and_email_when_the_store_differs(): void
    {
        $marco = $this->employee('José', 'Núñez', 'jose@example.com');
        $this->tcp->seed('5897', ['firstName' => 'Jose', 'lastName' => 'Nunez', 'location' => '03795-00002', 'email' => 'JOSE@example.com']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertSame('5897', $this->tcpIdOf($marco));
    }

    public function test_the_same_name_in_another_store_is_not_a_match(): void
    {
        $marco = $this->employee('Marco', 'Rossi');
        $this->tcp->seed('5896', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00002']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertNull($this->tcpIdOf($marco));
    }

    public function test_two_employees_for_one_tcp_person_links_neither(): void
    {
        $first = $this->employee('Marco', 'Rossi');
        $second = $this->employee('Marco', 'Rossi');
        $this->tcp->seed('5896', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00001']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertNull($this->tcpIdOf($first));
        $this->assertNull($this->tcpIdOf($second));
    }

    public function test_two_tcp_people_for_one_employee_links_neither(): void
    {
        $marco = $this->employee('Marco', 'Rossi');
        $this->tcp->seed('5896', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00001']);
        $this->tcp->seed('5897', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00001']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertNull($this->tcpIdOf($marco));
    }

    public function test_an_existing_different_tcp_id_is_never_overwritten(): void
    {
        $marco = $this->employee('Marco', 'Rossi');
        EmployeeId::query()->create([
            'employee_id' => $marco->id,
            'id_type_id' => IdType::query()->firstOrCreate(['label' => ExternalIdType::TCP])->id,
            'id_value' => '1111',
        ]);
        $this->tcp->seed('5896', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00001']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertSame('1111', $this->tcpIdOf($marco));
    }

    public function test_check_mode_writes_nothing(): void
    {
        $marco = $this->employee('Marco', 'Rossi');
        $this->tcp->seed('5896', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00001']);

        $this->artisan('tcp:link-employees --check')->assertSuccessful();

        $this->assertNull($this->tcpIdOf($marco));
    }

    public function test_it_never_writes_to_tcp(): void
    {
        $this->employee('Marco', 'Rossi');
        $this->tcp->seed('5896', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00001']);
        $before = $this->tcp->employees;

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertSame($before, $this->tcp->employees);
    }

    public function test_running_twice_changes_nothing_the_second_time(): void
    {
        $marco = $this->employee('Marco', 'Rossi');
        $this->tcp->seed('5896', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00001']);

        $this->artisan('tcp:link-employees')->assertSuccessful();
        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertSame(1, EmployeeId::query()->where('employee_id', $marco->id)->where('id_value', '5896')->count());
    }

    public function test_republish_sends_only_the_newly_linked_employees_to_operations(): void
    {
        $linked = $this->employee('Marco', 'Rossi');
        $this->employee('Anna', 'Bianchi');
        $this->tcp->seed('5896', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00001']);
        $before = HiringOutboxEvent::query()->pluck('id')->all();

        $this->artisan('tcp:link-employees --republish')->assertSuccessful();

        $new = HiringOutboxEvent::query()->whereNotIn('id', $before)->get();
        $this->assertCount(1, $new);
        $this->assertSame('hiring.v1.employee.created', $new->first()->subject);

        $employee = data_get($new->first()->payload, 'data.employee');
        $this->assertSame($linked->id, $employee['id']);
        $this->assertContains('5896', collect($employee['ids'])->pluck('id_value')->all());
    }

    public function test_an_empty_roster_fails_loudly(): void
    {
        $this->artisan('tcp:link-employees')->assertFailed();
    }
}
