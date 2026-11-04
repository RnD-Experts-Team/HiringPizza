<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use App\Reports\Options;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Hiring requests on the day they were made (the date the requests list filters
 * on). A request is `completed` once it has a decision (the workflow records
 * how many were hired) and `pending` before. Candidate names, phones, emails,
 * the requester and the notes are not offered, only how many candidates there are.
 */
class HiringRequestsDataset extends ReportDataset
{
    public const KEY = 'hiring.hiring_requests';

    private const SUMS = ['sum', 'avg', 'min', 'max'];

    public function label(): string
    {
        return 'Hiring requests';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Request date', 'date', 'DATE(hiring_requests.created_at)', groupable: true),
            new Column('status', 'Status', 'enum', "CASE WHEN decision.id IS NULL THEN 'pending' ELSE 'completed' END", options: Options::values(['pending', 'completed']), groupable: true),
            new Column('desired_start_date', 'Desired start date', 'date', 'hiring_requests.desired_start_date', groupable: true),
            new Column('employees_needed', 'Employees needed', 'integer', 'hiring_requests.employees_needed', measure: true, aggregations: self::SUMS),
            new Column('positions_requested', 'Shift positions requested', 'integer', 'COALESCE(position_totals.positions, 0)', measure: true, aggregations: self::SUMS),
            new Column('candidates', 'Candidates', 'integer', 'COALESCE(candidate_totals.candidates, 0)', measure: true, aggregations: self::SUMS),
            new Column('number_hired', 'Number hired', 'integer', 'COALESCE(decision.number_hired, 0)', measure: true, aggregations: self::SUMS),
            new Column('count', 'Hiring requests', 'integer', 'hiring_requests.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        $candidates = DB::table('hiring_request_candidates')
            ->groupBy('hiring_request_id')
            ->selectRaw('hiring_request_id, COUNT(*) as candidates');
        $positions = DB::table('hiring_request_positions')
            ->groupBy('hiring_request_id')
            ->selectRaw('hiring_request_id, COUNT(*) as positions');

        return DB::table('hiring_requests')
            ->join('stores', 'stores.id', '=', 'hiring_requests.store_id')
            ->leftJoinSub($candidates, 'candidate_totals', 'candidate_totals.hiring_request_id', '=', 'hiring_requests.id')
            ->leftJoinSub($positions, 'position_totals', 'position_totals.hiring_request_id', '=', 'hiring_requests.id')
            ->leftJoin('hiring_request_decisions as decision', fn ($join) => $join
                ->on('decision.hiring_request_id', '=', 'hiring_requests.id')
                ->whereRaw('decision.id = (select newest.id from hiring_request_decisions as newest where newest.hiring_request_id = decision.hiring_request_id order by newest.created_at desc, newest.id desc limit 1)'));
    }

    public function storeColumn(): string
    {
        return 'hiring_requests.store_id';
    }

    public function dateColumn(): ?string
    {
        return 'DATE(hiring_requests.created_at)';
    }
}
