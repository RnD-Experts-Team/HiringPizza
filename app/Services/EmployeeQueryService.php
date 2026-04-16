<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

class EmployeeQueryService
{
    public function index(Store $store, array $filters): LengthAwarePaginator
    {
        $query = Employee::query()
            ->whereHas('stores', fn(Builder $q) => $q->where('store_id', $store->id));

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        if (!empty($filters['ssn']) || !empty($filters['ssn_last4'])) {
            return $this->paginateWithInMemorySsnFilter($query, $filters, $perPage);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['employee_id'] ?? null, fn(Builder $q, $v) => $q->where('id', $v))
            ->when($filters['first_name'] ?? null, fn(Builder $q, $v) => $q->where('first_name', 'like', "%{$v}%"))
            ->when($filters['middle_name'] ?? null, fn(Builder $q, $v) => $q->where('middle_name', 'like', "%{$v}%"))
            ->when($filters['last_name'] ?? null, fn(Builder $q, $v) => $q->where('last_name', 'like', "%{$v}%"))
            ->when($filters['gender'] ?? null, fn(Builder $q, $v) => $q->where('gender', $v))
            ->when($filters['employment_type'] ?? null, fn(Builder $q, $v) => $q->where('employment_type', $v))
            ->when($filters['created_from'] ?? null, fn(Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['created_to'] ?? null, fn(Builder $q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['updated_from'] ?? null, fn(Builder $q, $v) => $q->whereDate('updated_at', '>=', $v))
            ->when($filters['updated_to'] ?? null, fn(Builder $q, $v) => $q->whereDate('updated_at', '<=', $v));

        if (!empty($filters['q'])) {
            $needle = $filters['q'];

            $query->where(function (Builder $q) use ($needle) {
                $q->where('first_name', 'like', "%{$needle}%")
                    ->orWhere('middle_name', 'like', "%{$needle}%")
                    ->orWhere('last_name', 'like', "%{$needle}%")
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$needle}%"])
                    ->orWhereRaw("CONCAT(first_name, ' ', middle_name, ' ', last_name) like ?", ["%{$needle}%"]);
            });
        }

        $query
            ->when($filters['status'] ?? null, function (Builder $q, $v) {
                $q->whereHas('statusHistories', fn(Builder $s) => $s->where('status', $v));
            })
            ->when($filters['status_in'] ?? null, function (Builder $q, $v) {
                $q->whereHas('statusHistories', fn(Builder $s) => $s->whereIn('status', $v));
            })
            ->when($filters['position_id'] ?? null, fn(Builder $q, $v) => $q->whereHas('positions', fn(Builder $p) => $p->where('position_id', $v)))
            ->when($filters['position_ids'] ?? null, fn(Builder $q, $v) => $q->whereHas('positions', fn(Builder $p) => $p->whereIn('position_id', $v)))
            ->when($filters['marital_id'] ?? null, fn(Builder $q, $v) => $q->whereHas('maritals', fn(Builder $m) => $m->where('marital_id', $v)))
            ->when($filters['id_type_id'] ?? null, fn(Builder $q, $v) => $q->whereHas('ids', fn(Builder $i) => $i->where('id_type_id', $v)))
            ->when($filters['attachment_type_id'] ?? null, fn(Builder $q, $v) => $q->whereHas('attachments', fn(Builder $a) => $a->where('type_id', $v)))
            ->when($filters['day_of_week'] ?? null, fn(Builder $q, $v) => $q->whereHas('availabilityDays', fn(Builder $a) => $a->where('day_of_week', $v)))
            ->when($filters['shift_type'] ?? null, fn(Builder $q, $v) => $q->whereHas('availabilityDays', fn(Builder $a) => $a->where('shift_type', $v)))
            ->when($filters['base_pay_min'] ?? null, fn(Builder $q, $v) => $q->whereHas('payHistories', fn(Builder $p) => $p->where('base_pay', '>=', $v)))
            ->when($filters['base_pay_max'] ?? null, fn(Builder $q, $v) => $q->whereHas('payHistories', fn(Builder $p) => $p->where('base_pay', '<=', $v)))
            ->when($filters['performance_pay_min'] ?? null, fn(Builder $q, $v) => $q->whereHas('payHistories', fn(Builder $p) => $p->where('performance_pay', '>=', $v)))
            ->when($filters['performance_pay_max'] ?? null, fn(Builder $q, $v) => $q->whereHas('payHistories', fn(Builder $p) => $p->where('performance_pay', '<=', $v)))
            ->when($filters['effective_pay_from'] ?? null, fn(Builder $q, $v) => $q->whereHas('payHistories', fn(Builder $p) => $p->whereDate('effective_date', '>=', $v)))
            ->when($filters['effective_pay_to'] ?? null, fn(Builder $q, $v) => $q->whereHas('payHistories', fn(Builder $p) => $p->whereDate('effective_date', '<=', $v)))
            ->when($filters['birth_from'] ?? null, fn(Builder $q, $v) => $q->whereHas('obsession', fn(Builder $o) => $o->whereDate('birth_date', '>=', $v)))
            ->when($filters['birth_to'] ?? null, fn(Builder $q, $v) => $q->whereHas('obsession', fn(Builder $o) => $o->whereDate('birth_date', '<=', $v)))
            ->when($filters['race'] ?? null, fn(Builder $q, $v) => $q->whereHas('obsession', fn(Builder $o) => $o->where('race', $v)))
            ->when($filters['religion'] ?? null, fn(Builder $q, $v) => $q->whereHas('obsession', fn(Builder $o) => $o->where('religion', $v)))
            ->when($filters['account_type'] ?? null, fn(Builder $q, $v) => $q->whereHas('financialInfos', fn(Builder $f) => $f->where('account_type', $v)))
            ->when(($filters['has_primary_email'] ?? null) !== null, fn(Builder $q, $v) => $q->whereHas('contacts', fn(Builder $c) => $c->where('contact_type', 'email')->where('is_primary', (bool) $v)))
            ->when(($filters['has_primary_phone'] ?? null) !== null, fn(Builder $q, $v) => $q->whereHas('contacts', fn(Builder $c) => $c->where('contact_type', 'phone')->where('is_primary', (bool) $v)));

        if (($filters['is_active'] ?? null) !== null) {
            $query->whereHas('statusHistories', function (Builder $q) use ($filters) {
                if ((bool) $filters['is_active']) {
                    $q->whereNotIn('status', ['resigned', 'terminated']);
                } else {
                    $q->whereIn('status', ['resigned', 'terminated']);
                }
            });
        }
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? 'id';
        $sortDir = strtolower($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowed = [
            'id',
            'first_name',
            'last_name',
            'created_at',
            'updated_at',
            'employment_type',
            'gender',
        ];

        if (!in_array($sortBy, $allowed, true)) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);
    }

    private function paginateWithInMemorySsnFilter(Builder $query, array $filters, int $perPage): LengthAwarePaginator
    {
        $ssn = isset($filters['ssn']) ? preg_replace('/\D+/', '', (string) $filters['ssn']) : null;
        $ssnLast4 = isset($filters['ssn_last4']) ? preg_replace('/\D+/', '', (string) $filters['ssn_last4']) : null;

        $collection = $query->get()->filter(function (Employee $employee) use ($ssn, $ssnLast4): bool {
            $candidate = preg_replace('/\D+/', '', (string) $employee->ssn);

            if ($ssn !== null && $ssn !== '' && $candidate !== $ssn) {
                return false;
            }

            if ($ssnLast4 !== null && $ssnLast4 !== '' && !str_ends_with($candidate, $ssnLast4)) {
                return false;
            }

            return true;
        })->values();

        $page = max((int) request()->query('page', 1), 1);
        $items = $collection->forPage($page, $perPage)->values();

        return new Paginator(
            $items,
            $collection->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }
}
