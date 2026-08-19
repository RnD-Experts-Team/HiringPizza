<?php

namespace Tests\Feature\Tcp;

use App\Models\Position;
use App\Models\Store;
use App\Models\TcpStoreRole;
use App\Services\EmployeeWorkflowService;
use App\Services\Tcp\FakeTcpEmployeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * tcp:sync-role-map derives the store -> roleId map from roleIds already
 * set (by hand, in TCP's UI) on existing employees — TCP itself does not
 * assign roleId from location. tcp:map-role covers what sync-role-map has
 * nothing to observe.
 */
class TcpRoleMapTest extends TestCase
{
    use RefreshDatabase;

    private FakeTcpEmployeeClient $tcp;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tcp.driver' => 'fake', 'tcp.writes_enabled' => true]);

        $this->tcp = app(FakeTcpEmployeeClient::class);
    }

    public function test_sync_derives_a_stores_role_from_its_existing_employees(): void
    {
        $this->tcp->seed('1001', ['location' => '03795-00001', 'roleId' => 'OH']);
        $this->tcp->seed('1002', ['location' => '03795-00001', 'roleId' => 'OH']);
        $this->tcp->seed('1003', ['location' => '03795-00002', 'roleId' => 'MI']);

        $this->artisan('tcp:sync-role-map')->assertSuccessful();

        $this->assertSame('OH', TcpStoreRole::query()->where('store_number', '03795-00001')->value('role_id'));
        $this->assertSame('MI', TcpStoreRole::query()->where('store_number', '03795-00002')->value('role_id'));
        $this->assertSame('observed', TcpStoreRole::query()->where('store_number', '03795-00001')->value('source'));
    }

    public function test_sync_leaves_a_store_unmapped_when_its_employees_disagree(): void
    {
        $this->tcp->seed('1001', ['location' => '03795-00001', 'roleId' => 'OH']);
        $this->tcp->seed('1002', ['location' => '03795-00001', 'roleId' => 'MI']);

        $this->artisan('tcp:sync-role-map')->assertSuccessful();

        $this->assertNull(TcpStoreRole::query()->where('store_number', '03795-00001')->value('role_id'));
    }

    public function test_sync_ignores_a_role_id_outside_the_valid_set(): void
    {
        $this->tcp->seed('1001', ['location' => '03795-00001', 'roleId' => 'Manager']);

        $this->artisan('tcp:sync-role-map')->assertSuccessful();

        $this->assertSame(0, TcpStoreRole::query()->count());
    }

    public function test_sync_never_overwrites_a_manual_mapping_without_force(): void
    {
        TcpStoreRole::query()->create(['store_number' => '03795-00001', 'role_id' => 'OH', 'source' => 'manual']);
        $this->tcp->seed('1001', ['location' => '03795-00001', 'roleId' => 'MI']);

        $this->artisan('tcp:sync-role-map')->assertSuccessful();
        $this->assertSame('OH', TcpStoreRole::query()->where('store_number', '03795-00001')->value('role_id'));

        $this->artisan('tcp:sync-role-map --force')->assertSuccessful();
        $this->assertSame('MI', TcpStoreRole::query()->where('store_number', '03795-00001')->value('role_id'));
    }

    public function test_check_mode_writes_nothing(): void
    {
        $this->tcp->seed('1001', ['location' => '03795-00001', 'roleId' => 'OH']);

        $this->artisan('tcp:sync-role-map --check')->assertSuccessful();

        $this->assertSame(0, TcpStoreRole::query()->count());
    }

    public function test_map_role_sets_a_manual_mapping(): void
    {
        $this->artisan('tcp:map-role 03795-00003 KY')->assertSuccessful();

        $row = TcpStoreRole::query()->where('store_number', '03795-00003')->first();
        $this->assertSame('KY', $row->role_id);
        $this->assertSame('manual', $row->source);
    }

    public function test_map_role_rejects_a_role_id_outside_the_valid_set(): void
    {
        $this->artisan('tcp:map-role 03795-00003 ZZ')->assertFailed();

        $this->assertSame(0, TcpStoreRole::query()->count());
    }

    public function test_map_role_unmap_removes_the_mapping(): void
    {
        TcpStoreRole::query()->create(['store_number' => '03795-00003', 'role_id' => 'KY', 'source' => 'manual']);

        $this->artisan('tcp:map-role --unmap=03795-00003')->assertSuccessful();

        $this->assertSame(0, TcpStoreRole::query()->where('store_number', '03795-00003')->count());
    }

    public function test_push_employee_picks_up_a_role_mapped_after_the_original_create(): void
    {
        $store = Store::query()->create(['id' => 1, 'store_number' => '03795-00001']);
        \App\Models\TcpJobCode::query()->create(['tcp_job_code_id' => '1', 'description' => 'Crew Member - 3795-01', 'store_number' => '03795-00001', 'clockable' => true, 'is_active' => true]);

        $employee = app(EmployeeWorkflowService::class)->create($store, [
            'first_name' => 'Marco',
            'last_name' => 'Rossi',
            'ssn' => '123-45-6789',
            'status_history' => [['status' => 'hired', 'effective_date' => '2026-01-15']],
            'positions' => [['position_id' => Position::query()->firstOrCreate(['label' => 'Crew Member'])->id, 'effective_date' => '2026-01-15']],
        ]);

        $remote = array_values($this->tcp->employees)[0];
        $this->assertArrayNotHasKey('roleId', $remote);

        TcpStoreRole::query()->create(['store_number' => '03795-00001', 'role_id' => 'OH', 'source' => 'manual']);

        $this->artisan("tcp:push-employee {$employee->id}")->assertSuccessful();

        $remote = array_values($this->tcp->employees)[0];
        $this->assertSame('OH', $remote['roleId']);
    }
}
