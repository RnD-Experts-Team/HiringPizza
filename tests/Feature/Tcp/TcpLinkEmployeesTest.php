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

    public function test_links_by_a_paychex_export_code(): void
    {
        $marco = $this->employee('Marco', 'Rossi');
        $paychex = IdType::query()->firstOrCreate(['label' => ExternalIdType::PAYCHEX]);
        EmployeeId::query()->create(['employee_id' => $marco->id, 'id_type_id' => $paychex->id, 'id_value' => '00412']);
        $this->tcp->seed('5896', ['firstName' => 'Someone', 'lastName' => 'Else', 'exportCode' => '00412']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertSame('5896', $this->tcpIdOf($marco));
    }

    public function test_an_export_code_that_is_one_paychex_id_and_another_employee_id_is_not_linked(): void
    {
        $marco = $this->employee('Marco', 'Rossi');
        $luca = $this->employee('Luca', 'Bianchi');
        $paychex = IdType::query()->firstOrCreate(['label' => ExternalIdType::PAYCHEX]);
        EmployeeId::query()->create(['employee_id' => $luca->id, 'id_type_id' => $paychex->id, 'id_value' => (string) $marco->id]);
        $this->tcp->seed('5896', ['firstName' => 'Someone', 'lastName' => 'Else', 'exportCode' => (string) $marco->id]);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertNull($this->tcpIdOf($marco));
        $this->assertNull($this->tcpIdOf($luca));
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

    public function test_ambiguous_rows_name_the_employees_they_matched(): void
    {
        $first = $this->employee('Marco', 'Rossi');
        $second = $this->employee('Marco', 'Rossi');
        $this->tcp->seed('5896', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00001']);

        $this->artisan('tcp:link-employees', ['--check' => true])
            ->expectsOutputToContain("employee ids {$first->id}, {$second->id}")
            ->assertSuccessful();
    }

    public function test_an_inactive_employee_is_not_matched_or_counted(): void
    {
        $marco = $this->employee('Marco', 'Rossi');
        \App\Models\EmployeeStatusHistory::query()->create([
            'employee_id' => $marco->id, 'status' => 'terminated', 'effective_date' => '2026-03-01',
        ]);
        $this->tcp->seed('5896', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00001']);

        // Not linked: the employee is terminated here, so the TCP person is
        // reported as active in TCP but inactive in HiringPizza.
        // Not matched by name: the terminated employee is out of the running,
        // so the TCP person is reported as matching nobody.
        $this->artisan('tcp:link-employees')
            ->expectsOutputToContain('Active in TCP but inactive in HiringPizza (terminate in TCP, or rehire here): 1')
            ->assertSuccessful();

        $this->assertNull($this->tcpIdOf($marco));
    }

    public function test_a_person_terminated_in_tcp_is_skipped(): void
    {
        $marco = $this->employee('Marco', 'Rossi');
        $this->tcp->seed('5896', ['firstName' => 'Marco', 'lastName' => 'Rossi', 'location' => '03795-00001', 'terminationDate' => '2026-02-01']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertNull($this->tcpIdOf($marco));
    }

    /**
     * The kinds of misspelling seen between HiringPizza and TCP (made-up
     * names): a vowel or doubled letter, a surname split in two, a middle
     * name, second surname or "Jr" on one side only. Each must link on its
     * own in the same store, with none crossing over to another pair.
     */
    public function test_the_same_person_spelled_differently_is_linked(): void
    {
        $pairs = [
            ['Hasan', 'Albaker', 'hassan', 'Al Baker'],
            ['Lina', 'Saad', 'Lena', 'Saad'],
            ['Tarek', 'Malik', 'Tarek', 'mallik'],
            ['Omer', 'Haddad', 'omar', 'haddad'],
            ['Fadi', 'Nassim', 'Fadi', 'Nassem'],
            ['Carlos', 'Ortiz Ortiz', 'Carlos', 'Ortiz'],
            ['Nabil', 'Albazi', 'Nabil', 'Albezi'],
            ['Hani', 'Maqdisi', 'Hadi', 'Makdisi'],
            ['Daniela', 'Lopez', 'Daniela', 'Lopez Herrera'],
            ['Karim', 'Bitar Nasser', 'karim', 'Nasser'],
            ['Megan', 'Fields', 'Magan', 'Fields'],
            ['victoria', 'Shamoun', 'Viktorya', 'Shamoun'],
            ['Walid', 'Sharif', 'Walid', 'Sharf'],
            ['Rosa', 'Maria Delgado', 'Rosa', 'Delgado'],
            ['Mark', 'Green', 'MARK ALLEN', 'THOMAS GREEN'],
            ['Leon', 'Carter', 'LEON BRYCE', 'CARTER JR'],
            ['Pedro', 'garcia', 'Pedro', 'Garcia Jr'],
            ['Kevin', 'McCarthy', 'Kevin', 'McCrathy'],
            ['Dustin', 'Fairbanks', 'Dusin', 'Fairbanks'],
            ['Luis M', 'Ortega', 'Luis M', 'Vargas Ortega'],
            ['Grace', 'Lindqvist', 'Grace Ann', 'Lindqvist'],
        ];

        $employees = [];

        foreach ($pairs as $i => [$first, $last, $tcpFirst, $tcpLast]) {
            $employees[$i] = $this->employee($first, $last);
            $this->tcp->seed((string) (7000 + $i), ['firstName' => $tcpFirst, 'lastName' => $tcpLast, 'location' => '03795-00001']);
        }

        $this->artisan('tcp:link-employees')->assertSuccessful();

        foreach ($pairs as $i => $pair) {
            $this->assertSame((string) (7000 + $i), $this->tcpIdOf($employees[$i]), "{$pair[0]} {$pair[1]} should link to {$pair[2]} {$pair[3]}");
        }
    }

    public function test_a_close_name_in_another_store_is_not_linked(): void
    {
        $hasan = $this->employee('Hasan', 'Albaker');
        $this->tcp->seed('7001', ['firstName' => 'hassan', 'lastName' => 'Al Baker', 'location' => '03795-00002']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertNull($this->tcpIdOf($hasan));
    }

    public function test_relatives_sharing_a_surname_are_not_linked(): void
    {
        $tarek = $this->employee('Tarek', 'Nassar');
        $this->tcp->seed('7001', ['firstName' => 'Rami', 'lastName' => 'Nassar', 'location' => '03795-00001']);
        $this->tcp->seed('7002', ['firstName' => 'Walid', 'lastName' => 'Nassar', 'location' => '03795-00001']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertNull($this->tcpIdOf($tarek));
    }

    public function test_a_close_name_with_a_different_email_is_not_linked(): void
    {
        // "Fadi" and "Hadi" are one letter apart and could be brothers.
        $fadi = $this->employee('Fadi', 'Nassar', 'fadi@example.com');
        $this->tcp->seed('7001', ['firstName' => 'Hadi', 'lastName' => 'Nassar', 'location' => '03795-00001', 'email' => 'hadi@example.com']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertNull($this->tcpIdOf($fadi));
    }

    public function test_a_close_name_with_the_same_email_is_linked(): void
    {
        $lina = $this->employee('Lina', 'Saad', 'lina@example.com');
        $this->tcp->seed('7001', ['firstName' => 'Lena', 'lastName' => 'Saad', 'location' => '03795-00001', 'email' => 'LINA@example.com']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertSame('7001', $this->tcpIdOf($lina));
    }

    public function test_two_close_employees_for_one_tcp_person_link_neither(): void
    {
        $omer = $this->employee('Omer', 'Haddad');
        $omar = $this->employee('Ommer', 'Haddad');
        $this->tcp->seed('7001', ['firstName' => 'omar', 'lastName' => 'haddad', 'location' => '03795-00001']);

        $this->artisan('tcp:link-employees')->assertSuccessful();

        $this->assertNull($this->tcpIdOf($omer));
        $this->assertNull($this->tcpIdOf($omar));
    }

    public function test_the_csv_lists_every_kind_of_problem(): void
    {
        $hasan = $this->employee('Hasan', 'Albaker');
        $nobody = $this->employee('Gordon', 'Pryce');
        $this->tcp->seed('7001', ['firstName' => 'hassan', 'lastName' => 'Al Baker', 'location' => '03795-00001']);
        $this->tcp->seed('7002', ['firstName' => 'Yusuf', 'lastName' => 'Darwish', 'location' => '03795-00001']);

        $path = tempnam(sys_get_temp_dir(), 'tcp-issues');

        $this->artisan('tcp:link-employees', ['--check' => true, '--csv' => $path])->assertSuccessful();

        $rows = array_map('str_getcsv', array_filter(explode("\n", (string) file_get_contents($path))));
        @unlink($path);

        $byIssue = collect(array_slice($rows, 1))->groupBy(0);

        $this->assertSame('hassan Al Baker', $byIssue['name spelled differently'][0][5]);
        $this->assertSame((string) $hasan->id, $byIssue['name spelled differently'][0][1]);
        $this->assertSame((string) $nobody->id, $byIssue['active here, no TCP match'][0][1]);
        $this->assertSame('7002', $byIssue['active in TCP, not in HiringPizza'][0][4]);

        // Check mode wrote nothing.
        $this->assertNull($this->tcpIdOf($hasan));
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
