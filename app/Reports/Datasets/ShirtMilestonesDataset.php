<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Enums\ShirtMilestoneSource;
use App\Enums\ShirtMilestoneStatus;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use App\Reports\Options;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shirt milestones (the store managers' queue) on their due date, the date the
 * queue is ordered by. A manual milestone may have no due date; it falls back
 * to the day it was created so it is not lost from every period. The milestone
 * is the month of the stint it is for (empty for a manual one). Sizes, notes
 * and the people who handled it are not offered.
 */
class ShirtMilestonesDataset extends ReportDataset
{
    public const KEY = 'hiring.shirt_milestones';

    public function label(): string
    {
        return 'Shirt milestones';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Due date (created date when none)', 'date', $this->dateColumn(), groupable: true),
            new Column('milestone', 'Milestone (month of the stint)', 'integer', 'employee_shirt_milestones.milestone_month', groupable: true),
            new Column('status', 'Status', 'enum', 'employee_shirt_milestones.status', options: Options::of(ShirtMilestoneStatus::class), groupable: true),
            new Column('source', 'Source', 'enum', 'employee_shirt_milestones.source', options: Options::of(ShirtMilestoneSource::class), groupable: true),
            new Column('count', 'Shirt milestones', 'integer', 'employee_shirt_milestones.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('employee_shirt_milestones')
            ->join('stores', 'stores.id', '=', 'employee_shirt_milestones.store_id');
    }

    public function storeColumn(): string
    {
        return 'employee_shirt_milestones.store_id';
    }

    public function dateColumn(): ?string
    {
        return 'COALESCE(employee_shirt_milestones.due_date, DATE(employee_shirt_milestones.created_at))';
    }
}
