<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/employees/hire-hours/export/csv behind auth.secret.key.
 *
 * Ann: hired 2026-01-05 at store 1, rehired 2026-09-01 at store 2.
 * Bob: hired 2026-09-01 at store 2, no phone, 8 hours on 10-20 only (none on the latest date).
 * Hours column is id 3; the latest hours date in the data is 2026-10-27.
 */
class EmployeeHireHoursExportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/employees/hire-hours/export/csv';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.x_secret_key' => 'test-secret']);
        $this->seedData();
    }

    private function seedData(): void
    {
        $now = now();
        DB::table('stores')->insert([
            ['id' => 1, 'store_number' => '03795-00044', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'store_number' => '03795-00042', 'created_at' => $now, 'updated_at' => $now],
        ]);
        foreach ([[1, 'Ann', 'Marie', 'Lee'], [2, 'Bob', null, 'Ray']] as [$id, $first, $middle, $last]) {
            DB::table('employees')->insert([
                'id' => $id, 'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last,
                'gender' => 'male', 'ssn' => 'secret-'.$id, 'employment_type' => 'W2',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('employee_status_histories')->insert([
            ['employee_id' => 1, 'status' => 'hired', 'effective_date' => '2026-01-05', 'store_id' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 1, 'status' => 'resigned', 'effective_date' => '2026-06-01', 'store_id' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 1, 'status' => 'rehired', 'effective_date' => '2026-09-01', 'store_id' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 2, 'status' => 'hired', 'effective_date' => '2026-09-01', 'store_id' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('employee_contacts')->insert([
            ['employee_id' => 1, 'contact_name' => 'Ann', 'contact_type' => 'phone', 'contact_value' => '555-0001', 'is_primary' => false, 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 1, 'contact_name' => 'Ann', 'contact_type' => 'phone', 'contact_value' => '555-0002', 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => 1, 'contact_name' => 'Ann', 'contact_type' => 'email', 'contact_value' => 'ann@example.com', 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('employee_metric_columns')->insert([
            ['id' => 1, 'key' => 'sales', 'label' => 'Sales', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'key' => 'labor', 'label' => 'Labor', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'key' => 'total_hours', 'label' => 'Total Hours', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // [employee, date, hours-column value, other-column value]
        $metrics = [
            [1, '2026-01-05', 40, 999],   // first stint, day 0 of window
            [1, '2026-02-23', 16, 999],   // inside the window, which ends 2026-03-01
            [1, '2026-03-02', 100, 999],  // outside the first window
            [1, '2026-09-01', 24, 999],   // second stint window
            [1, '2026-09-07', 50, 999],   // second stint; the Monday before the last-8-weeks window
            [1, '2026-09-08', 16, 999],   // second stint; first day of the last-8-weeks window
            [1, '2026-10-27', 32, 999],   // latest date in the data
            [2, '2026-10-20', 8, 999],    // not on the latest date
        ];
        foreach ($metrics as $i => [$employee, $date, $hours, $other]) {
            $id = $i + 1;
            DB::table('employee_metrics')->insert([
                'id' => $id, 'employee_id' => $employee, 'metric_date' => $date, 'store_number' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('employee_metric_values')->insert([
                ['employee_metric_id' => $id, 'column_id' => 3, 'value' => (string) $hours, 'value_numeric' => $hours, 'created_at' => $now, 'updated_at' => $now],
                ['employee_metric_id' => $id, 'column_id' => 1, 'value' => (string) $other, 'value_numeric' => $other, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    private function csv(string $query = ''): array
    {
        $response = $this->get(self::URL.$query, ['X-Secret-Key' => 'test-secret'])->assertOk();

        $lines = array_filter(explode("\n", $response->streamedContent()), 'strlen');

        return array_map('str_getcsv', array_values($lines));
    }

    public function test_it_requires_the_secret_key(): void
    {
        $this->get(self::URL)->assertUnauthorized();
        $this->get(self::URL, ['X-Secret-Key' => 'wrong'])->assertUnauthorized();
    }

    public function test_every_hire_is_its_own_row_when_nothing_is_filtered(): void
    {
        $rows = $this->csv();

        $this->assertSame([
            'Store Number', 'Full Name', 'Phone Number', 'Date of Hire', 'Times Shown',
            'Avg Hours Per Week (First 8 Weeks)', 'Latest Hours', 'Avg Hours Per Week (Last 8 Weeks)',
        ], $rows[0]);

        $this->assertSame([
            ['03795-00044', 'Ann Marie Lee', '555-0002', '2026-01-05', '2', '7', '32', '6'],
            ['03795-00042', 'Ann Marie Lee', '555-0002', '2026-09-01', '2', '11.25', '32', '6'],
            ['03795-00042', 'Bob Ray', '', '2026-09-01', '1', '1', '0', '1'],
        ], array_slice($rows, 1));
    }

    public function test_blank_filters_are_the_same_as_none(): void
    {
        $this->assertCount(4, $this->csv('?store_number=&from=&to='));
    }

    public function test_store_filter_matches_the_store_of_the_hire_and_times_shown_follows_it(): void
    {
        $rows = $this->csv('?store_number=03795-00044');

        $this->assertCount(2, $rows);
        $this->assertSame(['03795-00044', 'Ann Marie Lee', '555-0002', '2026-01-05', '1', '7', '32', '6'], $rows[1]);
    }

    public function test_date_range_is_inclusive_and_open_ended_sides_are_allowed(): void
    {
        $this->assertCount(3, $this->csv('?from=2026-09-01&to=2026-09-01'));
        $this->assertCount(2, $this->csv('?to=2026-01-05'));
        $this->assertCount(3, $this->csv('?from=2026-01-06'));
    }

    public function test_bad_filters_are_rejected(): void
    {
        $this->getJson(self::URL.'?store_number=nope', ['X-Secret-Key' => 'test-secret'])->assertUnprocessable();
        $this->getJson(self::URL.'?from=2026-05-01&to=2026-04-01', ['X-Secret-Key' => 'test-secret'])->assertUnprocessable();
    }
}
