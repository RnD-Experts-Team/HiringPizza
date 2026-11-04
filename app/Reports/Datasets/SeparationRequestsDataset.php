<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Enums\RequestDecision;
use App\Enums\ResignationReason;
use App\Enums\SeparationType;
use App\Enums\TerminationReason;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use App\Reports\Options;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Separation requests (resignations and terminations) on the day they were
 * made (the date the requests list filters on). `status` is the latest
 * decision, `pending` while there is none. The reason is the coded one (the
 * termination or the resignation reason, whichever applies); the employee, the
 * letter, the reason details and the notes are not offered.
 */
class SeparationRequestsDataset extends ReportDataset
{
    public const KEY = 'hiring.separation_requests';

    public function label(): string
    {
        return 'Separation requests';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Request date', 'date', 'DATE(separation_requests.created_at)', groupable: true),
            new Column('separation_type', 'Type', 'enum', 'separation_requests.separation_type', options: Options::of(SeparationType::class), groupable: true),
            new Column('reason', 'Reason', 'enum', 'COALESCE(separation_requests.termination_reason, separation_requests.resignation_reason)', options: Options::union([TerminationReason::class, ResignationReason::class]), groupable: true),
            new Column('status', 'Status', 'enum', "COALESCE(decision.decision, 'pending')", options: Options::values(['pending', ...array_map(fn (RequestDecision $case) => $case->value, RequestDecision::cases())]), groupable: true),
            new Column('final_working_day', 'Final working day', 'date', 'separation_requests.final_working_day', groupable: true),
            new Column('count', 'Separation requests', 'integer', 'separation_requests.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('separation_requests')
            ->join('stores', 'stores.id', '=', 'separation_requests.store_id')
            ->leftJoin('separation_request_decisions as decision', fn ($join) => $join
                ->on('decision.separation_request_id', '=', 'separation_requests.id')
                ->whereRaw('decision.id = (select newest.id from separation_request_decisions as newest where newest.separation_request_id = decision.separation_request_id order by newest.created_at desc, newest.id desc limit 1)'));
    }

    public function storeColumn(): string
    {
        return 'separation_requests.store_id';
    }

    public function dateColumn(): ?string
    {
        return 'DATE(separation_requests.created_at)';
    }
}
