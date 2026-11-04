<?php

namespace Tests\Feature\Reports;

use App\Http\Requests\ReportDatasetQueryRequest;
use App\Reports\ReportDatasets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The contract routes end to end, through the real auth middleware with
 * pizzasys faked. Two stores: 03795-00044 (ids 1) and 03795-00042 (id 2).
 */
class ReportDatasetQueryTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private const EMPLOYEES = '/api/report-datasets/hiring.employees/query';

    private const STATUS_CHANGES = '/api/report-datasets/hiring.status_changes/query';

    private const PAY = '/api/report-datasets/hiring.pay/query';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seedHiring();
    }

    private function seedHiring(): void
    {
        $now = now();
        DB::table('stores')->insert([
            ['id' => 1, 'store_number' => '03795-00044', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'store_number' => '03795-00042', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('positions')->insert([
            ['id' => 1, 'label' => 'Driver', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'label' => 'Cook', 'created_at' => $now, 'updated_at' => $now],
        ]);
        foreach ([[1, 'Ann', 'Lee', 'W2'], [2, 'Bob', 'Ray', '1099'], [3, 'Cy', 'Fox', 'W2']] as [$id, $first, $last, $type]) {
            DB::table('employees')->insert([
                'id' => $id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'male',
                'ssn' => 'secret-'.$id, 'employment_type' => $type, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Ann: store 2 (until 10-01) then store 1; Bob and Cy only store 1 / 2.
        DB::table('employee_stores')->insert([
            ['employee_id' => 1, 'store_id' => 2, 'effective_date' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 1, 'store_id' => 1, 'effective_date' => '2026-10-01', 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 2, 'store_id' => 1, 'effective_date' => '2026-03-01', 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 3, 'store_id' => 2, 'effective_date' => '2026-03-01', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('employee_positions')->insert([
            ['employee_id' => 1, 'position_id' => 1, 'effective_date' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 1, 'position_id' => 2, 'effective_date' => '2026-10-01', 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 2, 'position_id' => 1, 'effective_date' => '2026-03-01', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('employee_status_histories')->insert([
            ['employee_id' => 1, 'status' => 'hired', 'effective_date' => '2026-01-01', 'store_id' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 1, 'status' => 'resigned', 'effective_date' => '2026-10-22', 'store_id' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 1, 'status' => 'rehired', 'effective_date' => '2026-10-24', 'store_id' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 2, 'status' => 'hired', 'effective_date' => '2026-10-23', 'store_id' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 3, 'status' => 'hired', 'effective_date' => '2026-10-23', 'store_id' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 3, 'status' => 'terminated', 'effective_date' => '2026-10-30', 'store_id' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('employee_pay_histories')->insert([
            ['employee_id' => 1, 'base_pay' => 15.00, 'performance_pay' => 1.00, 'effective_date' => '2026-10-21', 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 2, 'base_pay' => 17.50, 'performance_pay' => 2.00, 'effective_date' => '2026-10-23', 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 3, 'base_pay' => 12.00, 'performance_pay' => 0.50, 'effective_date' => '2026-10-23', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    private function body(array $overrides = []): array
    {
        return array_replace([
            'stores' => ['03795-00044'],
            'date_from' => '2026-10-21',
            'date_to' => '2026-10-27',
            'limit' => 50,
        ], $overrides);
    }

    public function test_status_changes_are_grouped_for_the_requested_store_only(): void
    {
        $this->postJson(self::STATUS_CHANGES, $this->body([
            'columns' => ['store', 'status'],
            'group_by' => ['store', 'status'],
            'aggregates' => [['column' => 'count', 'fn' => 'count', 'alias' => 'changes']],
            'filters' => [['column' => 'status', 'op' => 'in', 'value' => ['hired', 'rehired', 'resigned']]],
        ]), $this->headers())
            ->assertOk()
            ->assertJson([
                'columns' => [['key' => 'store', 'type' => 'string'], ['key' => 'status', 'type' => 'enum'], ['key' => 'changes', 'type' => 'integer']],
                'rows' => [['03795-00044', 'hired', 1], ['03795-00044', 'rehired', 1], ['03795-00044', 'resigned', 1]],
                'next_cursor' => null,
                'truncated' => false,
                'meta' => ['row_count' => 3, 'contract_version' => 1],
            ]);

        // pizzasys judged this exact route, with the store codes of the body.
        Http::assertSent(fn (ClientRequest $request) => str_starts_with($request->url(), 'http://auth.test')
            && $request['path'] === self::STATUS_CHANGES
            && $request['store_context']['body']['stores'] === ['03795-00044']
            && $request['store_context']['path']['dataset'] === 'hiring.status_changes');
    }

    public function test_the_employee_roster_uses_the_latest_store_position_and_status(): void
    {
        $response = $this->postJson(self::EMPLOYEES, $this->body([
            'stores' => ['03795-00044'],
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'columns' => ['store', 'employee', 'name', 'position', 'status', 'hire_date', 'is_active'],
            'group_by' => ['employee'],
        ]), $this->headers())->assertOk();

        // Ann moved to store 1 on 10-01 and is a Cook, rehired; Cy (store 2) is not listed.
        $this->assertSame([
            ['03795-00044', 1, 'Ann Lee', 'Cook', 'rehired', '2026-01-01', true],
            ['03795-00044', 2, 'Bob Ray', 'Driver', 'hired', '2026-10-23', true],
        ], array_map(fn (array $row) => $row, $response->json('rows')));
    }

    public function test_a_store_filter_in_the_body_is_what_scopes_the_rows(): void
    {
        $this->postJson(self::PAY, $this->body([
            'stores' => ['03795-00042'],
            'columns' => ['store'],
            'group_by' => ['store'],
            'aggregates' => [['column' => 'base_pay', 'fn' => 'sum', 'alias' => 'base_pay']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00042', 12]]);
    }

    public function test_pay_is_aggregated_per_store_and_date(): void
    {
        $this->postJson(self::PAY, $this->body([
            'stores' => ['03795-00044'],
            'columns' => ['store', 'date'],
            'group_by' => ['store', 'date'],
            'aggregates' => [
                ['column' => 'base_pay', 'fn' => 'sum', 'alias' => 'base_pay'],
                ['column' => 'performance_pay', 'fn' => 'max', 'alias' => 'best_bonus'],
            ],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00044', '2026-10-21', 15, 1], ['03795-00044', '2026-10-23', 17.5, 2]]);
    }

    public function test_no_stores_means_every_store(): void
    {
        $this->postJson(self::STATUS_CHANGES, $this->body([
            'stores' => [],
            'columns' => [],
            'group_by' => ['store'],
            'aggregates' => [['column' => 'count', 'fn' => 'count', 'alias' => 'changes']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00042', 1], ['03795-00044', 3]]);
    }

    public function test_a_store_pizzasys_refuses_is_a_403(): void
    {
        $this->tokenAuthorized = false;

        $this->postJson(self::STATUS_CHANGES, $this->body([
            'stores' => ['03795-00042'],
            'columns' => ['store'],
        ]), $this->headers())->assertForbidden();
    }

    public function test_unknown_columns_are_a_422(): void
    {
        $this->postJson(self::EMPLOYEES, $this->body(['columns' => ['store', 'ssn']]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.columns', ['Unknown column ssn.']);

        $this->postJson(self::PAY, $this->body(['aggregates' => [['column' => 'hourly_total', 'fn' => 'sum']]]), $this->headers())
            ->assertUnprocessable();

        $this->postJson(self::STATUS_CHANGES, $this->body(['date_to' => '2026-10-01', 'columns' => ['store']]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_a_caller_cannot_set_the_statement_timeout(): void
    {
        $this->assertArrayNotHasKey('timeout_ms', (new ReportDatasetQueryRequest())->rules());
    }

    public function test_the_catalog_names_hiring_and_lists_its_datasets(): void
    {
        $response = $this->getJson('/api/report-datasets', $this->headers())
            ->assertOk()
            ->assertJsonPath('service', 'Hiring')
            ->assertJsonPath('contract_version', 1);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $response->json('contract_sha1'));
        $this->assertSame([
            'hiring.employees', 'hiring.status_changes', 'hiring.pay', 'hiring.metrics',
            'hiring.hiring_requests', 'hiring.separation_requests', 'hiring.shirt_milestones',
        ], array_column($response->json('datasets'), 'key'));
        $this->assertSame(['store', 'employee'], $response->json('datasets.0.grain'));
        $this->assertSame('/report-datasets/hiring.pay/query', $response->json('datasets.2.path'));
        $this->assertSame(['Cook', 'Driver'], array_column($response->json('datasets.0.columns.3.options'), 'value'));

        // Only the roster is a snapshot; max_days is gone from the catalog.
        $this->assertSame(
            ['hiring.employees' => false, 'hiring.status_changes' => true, 'hiring.pay' => true, 'hiring.metrics' => true,
                'hiring.hiring_requests' => true, 'hiring.separation_requests' => true, 'hiring.shirt_milestones' => true],
            array_column($response->json('datasets'), 'dated', 'key'),
        );
        $this->assertArrayNotHasKey('max_days', $response->json('datasets.0'));
    }

    public function test_every_dataset_has_its_own_literal_query_route(): void
    {
        foreach (ReportDatasets::DATASETS as $dataset) {
            $route = app('router')->getRoutes()->match(Request::create('/api/report-datasets/'.$dataset::KEY.'/query', 'POST'));
            $this->assertSame('api/report-datasets/'.$dataset::KEY.'/query', $route->uri());
            $this->assertContains('auth.token.store', $route->gatherMiddleware());
        }
    }

    public function test_no_dataset_exposes_a_personal_or_banking_column(): void
    {
        $response = $this->getJson('/api/report-datasets', $this->headers())->assertOk();

        $keys = [];
        foreach ($response->json('datasets') as $dataset) {
            foreach ($dataset['columns'] as $column) {
                $keys[] = $column['key'];
                $this->assertDoesNotMatchRegularExpression('/ssn|account|routing|address|phone|email|birth/i', $column['key'].' '.$column['label']);
            }
        }

        $this->assertNotEmpty($keys);
        $this->assertNotContains('ssn', $keys);
        // The SQL behind a column is never published either.
        $this->assertStringNotContainsString('employees.', $response->getContent());
    }

    // ── Snapshot roster, any span ───────────────────────────────────────────

    public function test_the_roster_snapshot_ignores_the_period_and_derives_tenure_from_the_hire_date(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');

        $body = [
            'stores' => ['03795-00044'],
            'columns' => ['employee', 'hire_date', 'position', 'employment_type', 'status', 'is_active'],
            'group_by' => ['employee'],
            'aggregates' => [['column' => 'tenure_days', 'fn' => 'max', 'alias' => 'tenure']],
        ];
        $expected = [
            [1, '2026-01-01', 'Cook', 'W2', 'rehired', true, 306],
            [2, '2026-10-23', 'Driver', '1099', 'hired', true, 11],
        ];

        // No period at all.
        $this->postJson(self::EMPLOYEES, $body, $this->headers())->assertOk()->assertJsonPath('rows', $expected);
        // A period far from every status date changes nothing.
        $this->postJson(self::EMPLOYEES, [...$body, 'date_from' => '2001-01-01', 'date_to' => '2001-01-02'], $this->headers())
            ->assertOk()->assertJsonPath('rows', $expected);
    }

    public function test_a_dated_dataset_still_needs_a_period_and_accepts_any_span(): void
    {
        $this->postJson(self::PAY, ['stores' => [], 'columns' => ['store']], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');

        $this->postJson(self::PAY, $this->body([
            'stores' => [], 'date_from' => '2000-01-01', 'date_to' => '2030-12-31',
            'columns' => [], 'group_by' => [],
            'aggregates' => [['column' => 'base_pay', 'fn' => 'sum', 'alias' => 'total']],
        ]), $this->headers())->assertOk()->assertJsonPath('rows', [[44.5]]);
    }

    // ── hiring.metrics ──────────────────────────────────────────────────────

    /** Column 1 "Sales" (numbers only), column 2 "Shift code" (text), column 3 nothing imported. */
    private function seedMetrics(): void
    {
        $now = now();
        DB::table('employee_metric_columns')->insert([
            ['id' => 1, 'key' => 'sales', 'label' => 'Sales', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'key' => 'shift_code', 'label' => 'Shift code', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'key' => 'unused', 'label' => 'Unused', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $metrics = [
            // id, employee, date, store_number, sales, shift code
            [1, 1, '2026-10-21', '03795-00044', '100.5', 'A'],
            [2, 1, '2026-10-22', '03795-00044', '50', 'B'],
            [3, 2, '2026-10-21', '03795-00044', '20', null],
            [4, 3, '2026-10-21', '03795-00042', '999', 'A'],
            [5, 1, '2026-09-01', '03795-00044', '1000', 'A'],
        ];
        foreach ($metrics as [$id, $employee, $date, $store, $sales, $code]) {
            DB::table('employee_metrics')->insert([
                'id' => $id, 'employee_id' => $employee, 'metric_date' => $date, 'store_number' => $store,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('employee_metric_values')->insert(['employee_metric_id' => $id, 'column_id' => 1, 'value' => $sales, 'value_numeric' => $sales, 'created_at' => $now, 'updated_at' => $now]);
            if ($code !== null) {
                DB::table('employee_metric_values')->insert(['employee_metric_id' => $id, 'column_id' => 2, 'value' => $code, 'value_numeric' => null, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function test_the_catalog_lists_one_typed_column_per_metric_column(): void
    {
        $this->seedMetrics();

        $metrics = collect($this->getJson('/api/report-datasets', $this->headers())->assertOk()->json('datasets'))
            ->firstWhere('key', 'hiring.metrics');
        $columns = collect($metrics['columns'])->keyBy('key');

        $this->assertSame(['store', 'date', 'employee'], $metrics['grain']);
        $this->assertSame(['store', 'date', 'employee', 'count', 'm_1', 'm_2', 'm_3'], $columns->keys()->all());

        $this->assertSame('Sales', $columns['m_1']['label']);
        $this->assertSame('number', $columns['m_1']['type']);
        $this->assertSame(['sum', 'avg', 'min', 'max'], $columns['m_1']['aggregations']);
        $this->assertFalse($columns['m_1']['groupable']);

        $this->assertSame('Shift code', $columns['m_2']['label']);
        $this->assertSame('string', $columns['m_2']['type']);
        $this->assertTrue($columns['m_2']['groupable']);
        $this->assertSame([], $columns['m_2']['aggregations']);

        // Nothing imported yet: offered as a number.
        $this->assertSame('number', $columns['m_3']['type']);
    }

    public function test_metrics_are_pivoted_per_employee_and_store_within_the_period(): void
    {
        $this->seedMetrics();

        // Per employee: the 09-01 row is outside the period, store 2 outside the filter.
        $this->postJson('/api/report-datasets/hiring.metrics/query', $this->body([
            'columns' => ['store', 'employee'],
            'aggregates' => [
                ['column' => 'm_1', 'fn' => 'sum', 'alias' => 'sales'],
                ['column' => 'm_1', 'fn' => 'max', 'alias' => 'best_day'],
            ],
        ]), $this->headers())
            ->assertOk()
            ->assertJson([
                'columns' => [['key' => 'store', 'type' => 'string'], ['key' => 'employee', 'type' => 'integer'], ['key' => 'sales', 'type' => 'number'], ['key' => 'best_day', 'type' => 'number']],
                'rows' => [['03795-00044', 1, 150.5, 100.5], ['03795-00044', 2, 20, 20]],
            ]);

        // Every store: the store column is the imported code.
        $this->postJson('/api/report-datasets/hiring.metrics/query', $this->body([
            'stores' => [],
            'columns' => ['store'],
            'aggregates' => [['column' => 'm_1', 'fn' => 'sum', 'alias' => 'sales']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00042', 999], ['03795-00044', 170.5]]);

        // The text column groups, on the same row as the number.
        $this->postJson('/api/report-datasets/hiring.metrics/query', $this->body([
            'columns' => ['employee', 'date', 'm_2'],
            'aggregates' => [['column' => 'm_1', 'fn' => 'max', 'alias' => 'sales']],
            'filters' => [['column' => 'm_2', 'op' => 'in', 'value' => ['A', 'B']]],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [[1, '2026-10-21', 'A', 100.5], [1, '2026-10-22', 'B', 50]]);
    }

    public function test_a_renamed_metric_column_keeps_its_key(): void
    {
        $this->seedMetrics();
        DB::table('employee_metric_columns')->where('id', 1)->update(['label' => 'Net sales', 'key' => 'net_sales']);

        $columns = collect($this->getJson('/api/report-datasets', $this->headers())->json('datasets'))
            ->firstWhere('key', 'hiring.metrics')['columns'];
        $this->assertSame('Net sales', collect($columns)->firstWhere('key', 'm_1')['label']);

        $this->postJson('/api/report-datasets/hiring.metrics/query', $this->body([
            'columns' => ['employee'],
            'aggregates' => [['column' => 'm_1', 'fn' => 'sum', 'alias' => 'sales']],
        ]), $this->headers())->assertOk()->assertJsonPath('rows', [[1, 150.5], [2, 20]]);
    }

    public function test_a_metric_column_that_is_not_in_the_catalog_is_a_422(): void
    {
        $this->seedMetrics();

        $this->postJson('/api/report-datasets/hiring.metrics/query', $this->body([
            'aggregates' => [['column' => 'm_99', 'fn' => 'sum']],
        ]), $this->headers())->assertUnprocessable()->assertJsonValidationErrors(['aggregates.0.column' => 'Unknown column m_99.']);
    }

    // ── requests and shirts ─────────────────────────────────────────────────

    public function test_hiring_requests_are_counted_by_status_for_the_store(): void
    {
        $this->seedRequests();

        $this->postJson('/api/report-datasets/hiring.hiring_requests/query', $this->body([
            'columns' => ['status'],
            'aggregates' => [
                ['column' => 'employees_needed', 'fn' => 'sum', 'alias' => 'needed'],
                ['column' => 'positions_requested', 'fn' => 'sum', 'alias' => 'positions'],
                ['column' => 'candidates', 'fn' => 'sum', 'alias' => 'candidates'],
                ['column' => 'number_hired', 'fn' => 'sum', 'alias' => 'hired'],
                ['column' => 'count', 'fn' => 'count', 'alias' => 'requests'],
            ],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['completed', 3, 2, 2, 2, 1], ['pending', 1, 1, 1, 0, 1]]);
    }

    public function test_separation_requests_are_counted_by_type_reason_and_latest_decision(): void
    {
        $this->seedRequests();

        $this->postJson('/api/report-datasets/hiring.separation_requests/query', $this->body([
            'columns' => ['separation_type', 'reason', 'status'],
            'aggregates' => [['column' => 'count', 'fn' => 'count', 'alias' => 'requests']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [
                ['resignation', 'found_another_job', 'pending', 1],
                ['termination', 'performance_issues', 'completed', 1],
            ]);
    }

    public function test_shirt_milestones_are_counted_by_milestone_and_status_for_the_store(): void
    {
        $this->seedRequests();

        $this->postJson('/api/report-datasets/hiring.shirt_milestones/query', $this->body([
            'columns' => ['milestone', 'status'],
            'aggregates' => [['column' => 'count', 'fn' => 'count', 'alias' => 'milestones']],
        ]), $this->headers())
            ->assertOk()
            // The manual milestone has no due date and no month: it falls back to its creation day.
            ->assertJsonPath('rows', [[null, 'pending_entry', 1], [1, 'pending_entry', 1], [1, 'submitted', 1]]);
    }

    public function test_the_datasets_offer_exactly_these_columns(): void
    {
        $datasets = collect($this->getJson('/api/report-datasets', $this->headers())->json('datasets'))->keyBy('key');
        $keys = fn (string $dataset) => array_column($datasets[$dataset]['columns'], 'key');

        $this->assertSame(
            ['store', 'date', 'status', 'desired_start_date', 'employees_needed', 'positions_requested', 'candidates', 'number_hired', 'count'],
            $keys('hiring.hiring_requests'),
        );
        $this->assertSame(
            ['store', 'date', 'separation_type', 'reason', 'status', 'final_working_day', 'count'],
            $keys('hiring.separation_requests'),
        );
        $this->assertSame(['store', 'date', 'milestone', 'status', 'source', 'count'], $keys('hiring.shirt_milestones'));
        $this->assertSame(
            ['store', 'employee', 'name', 'position', 'status', 'employment_type', 'hire_date', 'tenure_days', 'status_date', 'is_active', 'count'],
            $keys('hiring.employees'),
        );
        $this->assertSame(
            ['pending', 'rejected', 'completed'],
            array_column($datasets['hiring.separation_requests']['columns'][4]['options'], 'value'),
        );
    }

    /**
     * Store 1 (03795-00044): a completed and a pending hiring request, a
     * resignation with no decision, a termination decided twice (rejected, then
     * completed) and three shirt milestones. Store 2 has one of each, which the
     * store filter must leave out.
     */
    private function seedRequests(): void
    {
        $now = now();
        $request = fn (int $id, int $store, string $day, int $needed) => DB::table('hiring_requests')->insert([
            'id' => $id, 'store_id' => $store, 'user_id' => 9, 'employees_needed' => $needed, 'desired_start_date' => '2026-11-01',
            'final_notes' => 'private note', 'created_at' => $day.' 10:00:00', 'updated_at' => $now,
        ]);
        $request(1, 1, '2026-10-22', 3);
        $request(2, 1, '2026-10-25', 1);
        $request(3, 2, '2026-10-22', 9);

        foreach ([[1, 'Ann'], [1, 'Bea'], [2, 'Cat'], [3, 'Dee']] as [$requestId, $name]) {
            DB::table('hiring_request_candidates')->insert(['hiring_request_id' => $requestId, 'name' => $name, 'phone' => '555', 'email' => $name.'@example.com', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([[1, 'AM'], [1, 'PM'], [2, 'OP'], [3, 'AM']] as [$requestId, $shift]) {
            DB::table('hiring_request_positions')->insert(['hiring_request_id' => $requestId, 'shift_type' => $shift, 'availability_type' => 'weekday', 'notes' => 'n', 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('hiring_request_decisions')->insert(['hiring_request_id' => 1, 'user_id' => 9, 'number_hired' => 2, 'created_at' => $now, 'updated_at' => $now]);

        $separation = fn (int $id, int $store, int $employee, string $type, array $reason, string $day) => DB::table('separation_requests')->insert([
            'id' => $id, 'store_id' => $store, 'user_id' => 9, 'employee_id' => $employee, 'separation_type' => $type,
            'final_working_day' => '2026-11-10', 'created_at' => $day.' 10:00:00', 'updated_at' => $now,
        ] + $reason);
        $separation(1, 1, 1, 'resignation', ['resignation_reason' => 'found_another_job'], '2026-10-22');
        $separation(2, 1, 2, 'termination', ['termination_reason' => 'performance_issues'], '2026-10-23');
        $separation(3, 2, 3, 'termination', ['termination_reason' => 'attendance_issues'], '2026-10-23');
        DB::table('separation_request_decisions')->insert([
            ['separation_request_id' => 2, 'user_id' => 9, 'decision' => 'rejected', 'created_at' => '2026-10-24 08:00:00', 'updated_at' => $now],
            ['separation_request_id' => 2, 'user_id' => 9, 'decision' => 'completed', 'created_at' => '2026-10-25 08:00:00', 'updated_at' => $now],
        ]);

        $shirt = fn (int $id, int $store, int $employee, ?int $month, ?string $due, string $status, string $source, string $created) => DB::table('employee_shirt_milestones')->insert([
            'id' => $id, 'employee_id' => $employee, 'store_id' => $store, 'milestone_month' => $month, 'stint_start_date' => $month ? '2026-09-25' : null,
            'due_date' => $due, 'source' => $source, 'status' => $status, 'created_at' => $created, 'updated_at' => $now,
        ]);
        $shirt(1, 1, 1, 1, '2026-10-25', 'submitted', 'automatic', '2026-10-25 01:00:00');
        $shirt(2, 1, 2, 1, '2026-10-26', 'pending_entry', 'automatic', '2026-10-26 01:00:00');
        $shirt(3, 1, 1, null, null, 'pending_entry', 'manual', '2026-10-24 10:00:00');
        $shirt(4, 2, 3, 1, '2026-10-25', 'submitted', 'automatic', '2026-10-25 01:00:00');
    }

    public function test_stream_mode_sends_ndjson_columns_rows_and_the_done_line(): void
    {
        $query = [
            'columns' => ['store', 'status'],
            'group_by' => ['store', 'status'],
            'aggregates' => [['column' => 'count', 'fn' => 'count', 'alias' => 'changes']],
        ];
        $page = $this->postJson(self::STATUS_CHANGES, $this->body($query), $this->headers())->assertOk()->json();

        $response = $this->postJson(self::STATUS_CHANGES, $this->body(['stream' => true, 'limit' => null] + $query), $this->headers())->assertOk();

        $this->assertStringStartsWith('application/x-ndjson', $response->headers->get('Content-Type'));
        $lines = array_map(fn (string $line) => json_decode($line, true), explode("\n", rtrim($response->streamedContent(), "\n")));
        $rows = array_slice($lines, 1, -1);

        $this->assertSame(['columns' => $page['columns']], $lines[0]);
        $this->assertNotEmpty($rows);
        $this->assertEqualsCanonicalizing($page['rows'], $rows);
        $this->assertSame(['done' => true, 'row_count' => count($rows)], end($lines));
    }

    public function test_a_stream_query_with_a_limit_is_a_422(): void
    {
        $this->postJson(self::STATUS_CHANGES, $this->body(['stream' => true, 'limit' => 10]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }
}
