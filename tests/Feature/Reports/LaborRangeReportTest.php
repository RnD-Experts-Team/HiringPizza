<?php

namespace Tests\Feature\Reports;

use App\Models\Store;
use App\Services\LaborReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * GET /api/v1/reports/labor through the real auth middleware (pizzasys faked).
 *
 * Store 1 (03795-00044): Ann (hired 01-01, 66h in the week of 10-20), Bob
 * (hired 03-01, resigned 10-22, 45h that week), and Dee until she moves to
 * store 2 on 10-15. Store 2 (03795-00042): Cy (41h that week) and Dee.
 * Business weeks run Tue–Mon: 10-13..10-19 and 10-20..10-26.
 */
class LaborRangeReportTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private const URL = '/api/v1/reports/labor';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seedLabor();
    }

    private function seedLabor(): void
    {
        $now = now();
        $stamp = ['created_at' => $now, 'updated_at' => $now];

        DB::table('stores')->insert([
            ['id' => 1, 'store_number' => '03795-00044', ...$stamp],
            ['id' => 2, 'store_number' => '03795-00042', ...$stamp],
        ]);
        DB::table('positions')->insert([
            ['id' => 1, 'label' => 'Driver', ...$stamp],
            ['id' => 2, 'label' => 'Cook', ...$stamp],
        ]);
        foreach ([[1, 'Ann', 'Lee'], [2, 'Bob', 'Ray'], [3, 'Cy', 'Fox'], [4, 'Dee', 'Kim']] as [$id, $first, $last]) {
            DB::table('employees')->insert([
                'id' => $id, 'first_name' => $first, 'last_name' => $last, 'gender' => 'male',
                'ssn' => 'secret-'.$id, 'employment_type' => 'W2', ...$stamp,
            ]);
        }

        DB::table('employee_stores')->insert([
            ['employee_id' => 1, 'store_id' => 1, 'effective_date' => '2026-01-01', ...$stamp],
            ['employee_id' => 2, 'store_id' => 1, 'effective_date' => '2026-03-01', ...$stamp],
            ['employee_id' => 3, 'store_id' => 2, 'effective_date' => '2026-02-01', ...$stamp],
            ['employee_id' => 4, 'store_id' => 1, 'effective_date' => '2025-06-01', ...$stamp],
            ['employee_id' => 4, 'store_id' => 2, 'effective_date' => '2026-10-15', ...$stamp],
        ]);
        DB::table('employee_positions')->insert([
            ['employee_id' => 1, 'position_id' => 2, 'effective_date' => '2026-01-01', ...$stamp],
            ['employee_id' => 2, 'position_id' => 1, 'effective_date' => '2026-03-01', ...$stamp],
            ['employee_id' => 3, 'position_id' => 1, 'effective_date' => '2026-02-01', ...$stamp],
            ['employee_id' => 4, 'position_id' => 2, 'effective_date' => '2025-06-01', ...$stamp],
        ]);
        DB::table('employee_status_histories')->insert([
            ['employee_id' => 1, 'status' => 'hired', 'effective_date' => '2026-01-01', 'store_id' => 1, ...$stamp],
            ['employee_id' => 2, 'status' => 'hired', 'effective_date' => '2026-03-01', 'store_id' => 1, ...$stamp],
            ['employee_id' => 2, 'status' => 'resigned', 'effective_date' => '2026-10-22', 'store_id' => 1, ...$stamp],
            ['employee_id' => 3, 'status' => 'hired', 'effective_date' => '2026-02-01', 'store_id' => 2, ...$stamp],
            ['employee_id' => 4, 'status' => 'hired', 'effective_date' => '2025-06-01', 'store_id' => 1, ...$stamp],
        ]);
        DB::table('employee_pay_histories')->insert([
            ['employee_id' => 1, 'base_pay' => 15.00, 'performance_pay' => 1.00, 'effective_date' => '2026-01-01', ...$stamp],
            ['employee_id' => 2, 'base_pay' => 12.00, 'performance_pay' => 0.50, 'effective_date' => '2026-03-01', ...$stamp],
        ]);
        DB::table('employee_obsessions')->insert([
            ['employee_id' => 1, 'birth_date' => '1990-10-22', ...$stamp],
            ['employee_id' => 3, 'birth_date' => '2000-10-14', ...$stamp],
        ]);
        DB::table('separation_requests')->insert([
            'store_id' => 1, 'user_id' => $this->authUser->id, 'employee_id' => 2, 'separation_type' => 'resignation',
            'final_working_day' => '2026-10-21', 'resignation_reason' => 'personal_reasons', ...$stamp,
        ]);

        DB::table('employee_metric_columns')->insert([
            ['id' => 1, 'key' => 'total_hours', 'label' => 'Total Hours', ...$stamp],
            ['id' => 2, 'key' => 'hourly_pay', 'label' => 'Hourly Pay', ...$stamp],
            ['id' => 3, 'key' => 'labor', 'label' => 'Labor', ...$stamp],
            ['id' => 4, 'key' => 'performance_score', 'label' => 'Performance Score', ...$stamp],
            ['id' => 5, 'key' => 'total_tips', 'label' => 'Total Tips', ...$stamp],
        ]);

        // employee, date, hours, hourly pay, labor, performance, tips
        $metrics = [
            [1, '2026-10-20', 22, 15, 0.25, 80, 10],
            [1, '2026-10-21', 22, 15, 0.25, 80, 10],
            [1, '2026-10-22', 22, 15, 0.25, 80, 10],
            [2, '2026-10-14', 10, 12, 0.20, 90, 0],
            [2, '2026-10-20', 30, 12, 0.20, 90, 5],
            [2, '2026-10-21', 15, 12, 0.20, 90, 5],
            [3, '2026-10-20', 41, 14, 0.30, 70, 0],
            [4, '2026-10-14', 8, 13, 0.40, 60, 0],
        ];
        foreach ($metrics as $i => $row) {
            [$employee, $date] = $row;
            $values = array_slice($row, 2);
            $metricId = $i + 1;
            DB::table('employee_metrics')->insert(['id' => $metricId, 'employee_id' => $employee, 'metric_date' => $date, ...$stamp]);
            foreach ($values as $column => $value) {
                DB::table('employee_metric_values')->insert([
                    'employee_metric_id' => $metricId, 'column_id' => $column + 1,
                    'value' => (string) $value, 'value_numeric' => $value, ...$stamp,
                ]);
            }
        }
    }

    private function report(array $stores, string $start, string $end): array
    {
        return $this->getJson(self::URL.'?'.http_build_query(['stores' => $stores, 'start_date' => $start, 'end_date' => $end]), $this->headers())
            ->assertOk()
            ->json();
    }

    public function test_one_store_and_one_week_matches_the_single_store_labor_report(): void
    {
        $range = $this->report(['03795-00044'], '2026-10-20', '2026-10-26');
        $week = app(LaborReportService::class)->getLaborReport(Store::query()->find(1), '2026-10-22');
        $week = json_decode(json_encode($week), true);
        $combined = $range['combined'];

        $this->assertSame([['week_start' => '2026-10-20', 'week_end' => '2026-10-26']], $range['weeks']);

        // Bob (active on 10-19) resigned on 10-22: 2 → 1, one separation, 1 / 1.5.
        $this->assertSame([
            'active_start' => 2, 'active_end' => 1, 'new_hires' => 0, 'separations' => 1, 'net_change' => -1,
            'by_position' => [['position' => 'Cook', 'count' => 1]],
        ], $combined['headcount']);
        $this->assertSame(Arr::except($week['headcount'], ['active_start_of_week', 'active_end_of_week']), Arr::except($combined['headcount'], ['active_start', 'active_end']));
        $this->assertSame(66.67, $combined['turnover']['turnover_rate_percent']);
        $this->assertEquals($week['turnover'], $combined['turnover']);

        // Bob: 55h in his 28 days (13.75/week) vs the store's 121h for 1 active (30.25), but 90 vs 85 performance.
        $event = $combined['turnover']['events'][0];
        $this->assertSame(['personal_reasons', '03795-00044'], [$event['reason'], $event['store']]);
        $this->assertEquals(
            ['avg_weekly_hours' => 13.75, 'store_avg_weekly_hours_same_period' => 30.25, 'above_average_hours' => false,
                'avg_performance_score' => 90, 'store_avg_performance_score_same_period' => 85, 'above_average_performance' => true],
            Arr::only($event['impact_snapshot'], ['avg_weekly_hours', 'store_avg_weekly_hours_same_period', 'above_average_hours',
                'avg_performance_score', 'store_avg_performance_score_same_period', 'above_average_performance']),
        );

        $this->assertEquals([
            'headcount_start' => 2, 'headcount_end' => 1, 'new_hires' => 0, 'separations' => 1, 'notable_departures' => 1,
            'turnover_rate_percent' => 66.67, 'avg_weekly_gross_pay' => null, 'avg_weekly_hours' => 111,
            'employee_weeks_over_40' => 2, 'employee_weeks_over_60' => 1,
        ], $combined['summary']);
        $this->assertSame($week['summary']['notable_departures_this_week'], $combined['summary']['notable_departures']);

        $this->assertEquals($week['tenure'], $combined['tenure']);
        $this->assertEquals($week['labor'], $combined['labor']);
        $this->assertEquals(111, $combined['labor']['highlights']['total_hours']);

        $this->assertSame(['Ann Lee', 'Bob Ray'], array_column($combined['overtime']['over_40'], 'name'));
        $this->assertEquals([66, 45], array_column($combined['overtime']['over_40'], 'total_hours'));
        $this->assertSame(array_column($week['overtime']['over_40_hours'], 'total_hours'), array_column($combined['overtime']['over_40'], 'total_hours'));
        $this->assertSame(['Ann Lee'], array_column($combined['overtime']['over_60'], 'name'));

        $this->assertEquals(end($week['trend']['weeks']), $combined['trend'][0]);

        // Roster: Ann active, Bob inactive but worked; metrics aggregated, not per day.
        $this->assertSame(['Ann Lee', 'Bob Ray'], array_column($combined['roster'], 'name'));
        $this->assertSame(['active', 'inactive'], array_column($combined['roster'], 'status'));
        $this->assertEquals(['label' => 'Total Hours', 'sum' => 66, 'avg' => 22], $combined['roster'][0]['metrics']['total_hours']);
        $this->assertEquals(
            array_map(fn (array $row) => Arr::except($row, ['store', 'birthday']), $week['employees']),
            array_map(fn (array $row) => Arr::except($row, ['store', 'birthday']), $combined['roster']),
        );

        $this->assertEquals([
            'average_hourly_pay' => 13.8, 'maximum_hourly_pay' => 15, 'minimum_hourly_pay' => 12,
            'total_tips' => 40, 'total_hours' => 111, 'tips_per_hour' => 0.36,
        ], $combined['pay']);
        $this->assertEquals([['week_start' => '2026-10-20', 'week_end' => '2026-10-26', 'labor' => 23]], $combined['final_labor']);
        $this->assertSame([['employee_id' => 1, 'name' => 'Ann Lee', 'store' => '03795-00044', 'position' => 'Cook',
            'birth_date' => '1990-10-22', 'turns_age' => 36, 'date' => '2026-10-22']], $combined['birthdays']);
    }

    public function test_several_stores_are_combined_and_split_per_store_without_the_lists(): void
    {
        $range = $this->report(['03795-00044', '03795-00042'], '2026-10-20', '2026-10-26');
        $combined = $range['combined'];

        $this->assertSame(['03795-00042', '03795-00044'], array_keys($range['by_store']));

        // Store 2: Cy and Dee (moved in 10-15) on both ends; 4 → 3 together.
        $this->assertSame([2, 2], [$range['by_store']['03795-00042']['headcount']['active_start'], $range['by_store']['03795-00042']['headcount']['active_end']]);
        $this->assertSame([4, 3, 1], [$combined['headcount']['active_start'], $combined['headcount']['active_end'], $combined['headcount']['separations']]);
        $this->assertSame(28.57, $combined['turnover']['turnover_rate_percent']);
        $this->assertSame(0.0, (float) $range['by_store']['03795-00042']['turnover']['turnover_rate_percent']);

        $this->assertSame(['Ann Lee', 'Bob Ray', 'Cy Fox'], array_column($combined['overtime']['over_40'], 'name'));
        $this->assertSame(['03795-00044', '03795-00044', '03795-00042'], array_column($combined['overtime']['over_40'], 'store'));
        $this->assertSame([3, 1], [$combined['overtime']['over_40_count'], $combined['overtime']['over_60_count']]);
        $this->assertSame(1, $range['by_store']['03795-00042']['overtime']['over_40_count']);

        // Combined averages weigh every row: (3×15 + 2×12 + 14) / 6.
        $this->assertEquals(152, $combined['labor']['highlights']['total_hours']);
        $this->assertEquals(13.83, $combined['labor']['highlights']['average_hourly_pay']);
        $this->assertEquals(41, $range['by_store']['03795-00042']['labor']['highlights']['total_hours']);

        // Final labor: the mean of the two stores' weekly values (23 and 30).
        $this->assertEquals(26.5, $combined['final_labor'][0]['labor']);
        $this->assertEquals(30, $range['by_store']['03795-00042']['final_labor'][0]['labor']);

        foreach ($range['by_store'] as $block) {
            $this->assertArrayNotHasKey('roster', $block);
            $this->assertArrayNotHasKey('birthdays', $block);
            $this->assertArrayNotHasKey('events', $block['turnover']);
            $this->assertArrayNotHasKey('newest_hires', $block['tenure']);
            $this->assertArrayNotHasKey('over_40', $block['overtime']);
        }
        // Roster in name order: Fox, Kim (store 2), Lee, Ray (store 1).
        $this->assertSame(['03795-00042', '03795-00042', '03795-00044', '03795-00044'], array_column($combined['roster'], 'store'));

        $this->assertEquals($range, $this->report(['all'], '2026-10-20', '2026-10-26'));
    }

    public function test_a_range_covers_every_overlapping_week_and_its_birthdays(): void
    {
        $range = $this->report(['03795-00044', '03795-00042'], '2026-10-14', '2026-10-22');
        $combined = $range['combined'];

        $this->assertSame([
            ['week_start' => '2026-10-13', 'week_end' => '2026-10-19'],
            ['week_start' => '2026-10-20', 'week_end' => '2026-10-26'],
        ], $range['weeks']);
        $this->assertSame(['2026-10-13', '2026-10-20'], array_column($combined['trend'], 'week_start'));

        // Week 1: Bob's 10h at store 1, Dee's 8h counted at store 2 (where she is on 10-19).
        $this->assertEquals([10, 111], array_column($range['by_store']['03795-00044']['trend'], 'total_hours'));
        $this->assertEquals([8, 41], array_column($range['by_store']['03795-00042']['trend'], 'total_hours'));

        // Range totals are dated 10-14..10-22 only.
        $this->assertEquals(10 + 8 + 66 + 45 + 41, $combined['labor']['highlights']['total_hours']);

        $this->assertSame(['2026-10-14', '2026-10-22'], array_column($combined['birthdays'], 'date'));
        $this->assertSame([26, 36], array_column($combined['birthdays'], 'turns_age'));
    }

    public function test_bad_input_is_a_422(): void
    {
        $this->getJson(self::URL.'?'.http_build_query(['stores' => ['99999-00001'], 'start_date' => '2026-10-20', 'end_date' => '2026-10-26']), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stores');

        $this->getJson(self::URL.'?'.http_build_query(['stores' => ['all'], 'start_date' => '2026-10-20', 'end_date' => '2026-10-19']), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');

        $this->getJson(self::URL, $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stores', 'start_date', 'end_date']);
    }

    public function test_the_old_multi_store_route_is_gone(): void
    {
        $this->getJson('/api/v1/reports?'.http_build_query(['stores' => ['all'], 'start_date' => '2026-10-20', 'end_date' => '2026-10-26']), $this->headers())
            ->assertNotFound();
    }
}
