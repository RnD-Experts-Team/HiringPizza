<?php

namespace Tests\Feature\Tcp;

use App\Models\Store;
use App\Models\TcpJobCode;
use App\Services\Tcp\FakeTcpEmployeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * tcp:sync-job-codes is what TcpEmployeeSyncService::jobCodeCatalog() reads
 * from — this is the command that keeps that local mirror correct.
 */
class TcpJobCodeSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeTcpEmployeeClient $tcp;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tcp.driver' => 'fake']);

        Store::query()->create(['id' => 1, 'store_number' => '03795-00001']);
        Store::query()->create(['id' => 2, 'store_number' => '03795-00002']);

        $this->tcp = app(FakeTcpEmployeeClient::class);
    }

    public function test_job_codes_are_attributed_by_the_restaurant_id_custom_field(): void
    {
        $this->tcp->seedJobCode('37950101', 'Crew Member - 3795-01', '03795-00001');
        $this->tcp->seedJobCode('1000', 'Regular'); // company-wide, no store

        $this->artisan('tcp:sync-job-codes')->assertSuccessful();

        $perStore = TcpJobCode::query()->where('tcp_job_code_id', '37950101')->sole();
        $this->assertSame('03795-00001', $perStore->store_number);
        $this->assertTrue($perStore->clockable);

        $global = TcpJobCode::query()->where('tcp_job_code_id', '1000')->sole();
        $this->assertNull($global->store_number);
    }

    public function test_it_warns_about_a_store_with_no_clockable_job_codes(): void
    {
        $this->tcp->seedJobCode('37950101', 'Crew Member - 3795-01', '03795-00001');
        // Store 2 has no job code at all.

        $this->artisan('tcp:sync-job-codes')
            ->expectsOutputToContain('03795-00002')
            ->assertSuccessful();
    }

    public function test_check_mode_writes_nothing(): void
    {
        $this->tcp->seedJobCode('37950101', 'Crew Member - 3795-01', '03795-00001');

        $this->artisan('tcp:sync-job-codes --check')->assertSuccessful();

        $this->assertSame(0, TcpJobCode::query()->count());
    }

    public function test_it_updates_an_existing_row_rather_than_duplicating(): void
    {
        TcpJobCode::query()->create([
            'tcp_job_code_id' => '37950101', 'description' => 'Old description',
            'store_number' => '03795-00001', 'clockable' => false, 'is_active' => true,
        ]);

        $this->tcp->seedJobCode('37950101', 'Crew Member - 3795-01', '03795-00001', clockable: true);

        $this->artisan('tcp:sync-job-codes')->assertSuccessful();

        $this->assertSame(1, TcpJobCode::query()->count());
        $row = TcpJobCode::sole();
        $this->assertSame('Crew Member - 3795-01', $row->description);
        $this->assertTrue($row->clockable);
    }
}
