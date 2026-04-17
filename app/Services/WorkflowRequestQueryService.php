<?php

namespace App\Services;

use App\Models\HiringRequest;
use App\Models\SeparationRequest;
use App\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

class WorkflowRequestQueryService
{
    public function index(Store $store, array $filters): LengthAwarePaginator
    {
        $requestTypes = $this->resolveRequestTypes($filters);

        $rows = collect();

        if (in_array('separation', $requestTypes, true)) {
            $rows = $rows->concat($this->fetchSeparationRows($store, $filters));
        }

        if (in_array('hiring', $requestTypes, true)) {
            $rows = $rows->concat($this->fetchHiringRows($store, $filters));
        }

        $rows = $this->applyPostMergeFilters($rows, $filters);
        $rows = $this->applySorting($rows, $filters);

        return $this->paginateCollection($rows, $filters);
    }

    private function fetchSeparationRows(Store $store, array $filters): Collection
    {
        $query = SeparationRequest::query()
            ->with(['user', 'employee', 'attachments', 'decisions.user'])
            ->where('store_id', $store->id);

        $query
            ->when($filters['requested_by_user_id'] ?? null, fn(Builder $q, $v) => $q->where('user_id', $v))
            ->when($filters['employee_id'] ?? null, fn(Builder $q, $v) => $q->where('employee_id', $v))
            ->when($filters['separation_type'] ?? null, fn(Builder $q, $v) => $q->where('separation_type', $v))
            ->when($filters['created_from'] ?? null, fn(Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['created_to'] ?? null, fn(Builder $q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['final_working_from'] ?? null, fn(Builder $q, $v) => $q->whereDate('final_working_day', '>=', $v))
            ->when($filters['final_working_to'] ?? null, fn(Builder $q, $v) => $q->whereDate('final_working_day', '<=', $v))
            ->when($filters['decision_by_user_id'] ?? null, fn(Builder $q, $v) => $q->whereHas('decisions', fn(Builder $d) => $d->where('user_id', $v)))
            ->when($filters['decided_from'] ?? null, fn(Builder $q, $v) => $q->whereHas('decisions', fn(Builder $d) => $d->whereDate('created_at', '>=', $v)))
            ->when($filters['decided_to'] ?? null, fn(Builder $q, $v) => $q->whereHas('decisions', fn(Builder $d) => $d->whereDate('created_at', '<=', $v)));

        if (!empty($filters['q'])) {
            $search = trim((string) $filters['q']);

            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('employee', function (Builder $e) use ($search) {
                    $e->where('first_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$search}%"]);
                })->orWhere('additional_notes', 'like', "%{$search}%")
                    ->orWhere('termination_reason_details', 'like', "%{$search}%")
                    ->orWhere('resignation_reason_details', 'like', "%{$search}%");
            });
        }

        return $query->get()->map(fn(SeparationRequest $request) => $this->mapSeparationRow($request));
    }

    private function fetchHiringRows(Store $store, array $filters): Collection
    {
        $query = HiringRequest::query()
            ->with([
                'user',
                'candidates',
                'positions',
                'decisions.user',
                'decisions.employees.employee',
            ])
            ->where('store_id', $store->id);

        $query
            ->when($filters['requested_by_user_id'] ?? null, fn(Builder $q, $v) => $q->where('user_id', $v))
            ->when($filters['created_from'] ?? null, fn(Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['created_to'] ?? null, fn(Builder $q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['desired_start_from'] ?? null, fn(Builder $q, $v) => $q->whereDate('desired_start_date', '>=', $v))
            ->when($filters['desired_start_to'] ?? null, fn(Builder $q, $v) => $q->whereDate('desired_start_date', '<=', $v))
            ->when($filters['shift_type'] ?? null, fn(Builder $q, $v) => $q->whereHas('positions', fn(Builder $p) => $p->where('shift_type', $v)))
            ->when($filters['availability_type'] ?? null, fn(Builder $q, $v) => $q->whereHas('positions', fn(Builder $p) => $p->where('availability_type', $v)))
            ->when($filters['decision_by_user_id'] ?? null, fn(Builder $q, $v) => $q->whereHas('decisions', fn(Builder $d) => $d->where('user_id', $v)))
            ->when($filters['decided_from'] ?? null, fn(Builder $q, $v) => $q->whereHas('decisions', fn(Builder $d) => $d->whereDate('created_at', '>=', $v)))
            ->when($filters['decided_to'] ?? null, fn(Builder $q, $v) => $q->whereHas('decisions', fn(Builder $d) => $d->whereDate('created_at', '<=', $v)))
            ->when($filters['employee_id'] ?? null, fn(Builder $q, $v) => $q->whereHas('decisions.employees', fn(Builder $d) => $d->where('employee_id', $v)));

        if (!empty($filters['q'])) {
            $search = trim((string) $filters['q']);

            $query->where(function (Builder $q) use ($search) {
                $q->where('final_notes', 'like', "%{$search}%")
                    ->orWhereHas('candidates', function (Builder $c) use ($search) {
                        $c->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        return $query->get()->map(fn(HiringRequest $request) => $this->mapHiringRow($request));
    }

    private function mapSeparationRow(SeparationRequest $request): array
    {
        $latestDecision = $request->decisions
            ->sortByDesc('created_at')
            ->values()
            ->first();

        return [
            'id' => $request->id,
            'request_type' => 'separation',
            'store_id' => $request->store_id,
            'requested_by_user_id' => $request->user_id,
            'requested_at' => $request->created_at,
            'workflow_status' => $this->resolveWorkflowStatus($latestDecision?->decision),
            'latest_decision' => $this->mapLatestDecision($latestDecision),
            'separation_request' => $request,
            'hiring_request' => null,
        ];
    }

    private function mapHiringRow(HiringRequest $request): array
    {
        $latestDecision = $request->decisions
            ->sortByDesc('created_at')
            ->values()
            ->first();

        return [
            'id' => $request->id,
            'request_type' => 'hiring',
            'store_id' => $request->store_id,
            'requested_by_user_id' => $request->user_id,
            'requested_at' => $request->created_at,
            'workflow_status' => $latestDecision ? 'completed' : 'pending',
            'latest_decision' => $this->mapLatestHiringDecision($latestDecision),
            'separation_request' => null,
            'hiring_request' => $request,
        ];
    }

    private function mapLatestDecision(mixed $decision): ?array
    {
        if (!$decision) {
            return null;
        }

        return [
            'id' => $decision->id,
            'decision' => $decision->decision,
            'decided_by_user_id' => $decision->user_id,
            'decided_at' => $decision->created_at,
            'completed_at' => $decision->completed_at,
            'notes' => $decision->notes,
        ];
    }

    private function mapLatestHiringDecision(mixed $decision): ?array
    {
        if (!$decision) {
            return null;
        }

        return [
            'id' => $decision->id,
            'decision' => 'completed',
            'decided_by_user_id' => $decision->user_id,
            'decided_at' => $decision->created_at,
            'completed_at' => $decision->completed_at,
            'number_hired' => $decision->number_hired,
            'employee_ids' => $decision->employees->pluck('employee_id')->values()->all(),
        ];
    }

    private function resolveWorkflowStatus(?string $decision): string
    {
        return match ($decision) {
            'rejected' => 'rejected',
            'completed' => 'completed',
            default => 'pending',
        };
    }

    private function applyPostMergeFilters(Collection $rows, array $filters): Collection
    {
        $workflowStatuses = $this->resolveWorkflowStatuses($filters);
        $decisions = $this->resolveDecisionFilters($filters);

        return $rows
            ->when(!empty($workflowStatuses), function (Collection $c) use ($workflowStatuses) {
                return $c->whereIn('workflow_status', $workflowStatuses);
            })
            ->when(!empty($decisions), function (Collection $c) use ($decisions) {
                return $c->filter(function (array $row) use ($decisions): bool {
                    $decision = $row['latest_decision']['decision'] ?? null;

                    return $decision !== null && in_array($decision, $decisions, true);
                });
            })
            ->values();
    }

    private function applySorting(Collection $rows, array $filters): Collection
    {
        $sortBy = $filters['sort_by'] ?? 'requested_at';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowed = ['requested_at', 'id', 'final_working_day', 'desired_start_date', 'latest_decided_at'];
        if (!in_array($sortBy, $allowed, true)) {
            $sortBy = 'requested_at';
        }

        $sorted = $rows->sortBy(function (array $row) use ($sortBy) {
            return match ($sortBy) {
                'id' => $row['id'],
                'final_working_day' => $row['separation_request']?->final_working_day,
                'desired_start_date' => $row['hiring_request']?->desired_start_date,
                'latest_decided_at' => $row['latest_decision']['decided_at'] ?? null,
                default => $row['requested_at'],
            };
        });

        return $sortDir === 'desc'
            ? $sorted->reverse()->values()
            : $sorted->values();
    }

    private function paginateCollection(Collection $rows, array $filters): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 25), 100);
        $page = max((int) request()->query('page', 1), 1);

        $items = $rows->forPage($page, $perPage)->values();

        return new Paginator(
            $items,
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    private function resolveRequestTypes(array $filters): array
    {
        if (!empty($filters['request_type'])) {
            return [(string) $filters['request_type']];
        }

        if (!empty($filters['request_types']) && is_array($filters['request_types'])) {
            return array_values(array_unique(array_map('strval', $filters['request_types'])));
        }

        return ['separation', 'hiring'];
    }

    private function resolveWorkflowStatuses(array $filters): array
    {
        if (!empty($filters['workflow_status'])) {
            return [(string) $filters['workflow_status']];
        }

        if (!empty($filters['workflow_statuses']) && is_array($filters['workflow_statuses'])) {
            return array_values(array_unique(array_map('strval', $filters['workflow_statuses'])));
        }

        return [];
    }

    private function resolveDecisionFilters(array $filters): array
    {
        if (!empty($filters['decision'])) {
            return [(string) $filters['decision']];
        }

        if (!empty($filters['decision_in']) && is_array($filters['decision_in'])) {
            return array_values(array_unique(array_map('strval', $filters['decision_in'])));
        }

        return [];
    }
}
