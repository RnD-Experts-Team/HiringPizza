<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\ShirtMilestoneSource;
use App\Enums\ShirtMilestoneStatus;
use App\Jobs\PublishOutboxEventJob;
use App\Models\Employee;
use App\Models\EmployeeObsession;
use App\Models\EmployeeShirtMilestone;
use App\Models\ShirtTemplate;
use App\Models\Store;
use App\Services\HiringEvents\HiringEventFactory;
use App\Services\HiringEvents\HiringOutboxService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShirtMilestoneWorkflowService
{
    /**
     * Relations every read carries. Loading these up front is what keeps the
     * store and HQ queues off five extra queries per row.
     */
    private const RELATIONS = [
        'employee',
        // Only the shirt size. The obsession row also holds race,
        // religion and birth date, which have no business in a shirt
        // queue payload.
        'employee.obsession:id,employee_id,t_shirt',
        'store',
        'shirtColor',
        'secondaryShirtColor',
        'shirtLogo',
        'shirtTemplate',
        'createdByUser',
        'submittedByUser',
        'orderedByUser',
        'deliveredByUser',
        'cancelledByUser',
    ];

    public function resolveStoreByNumber(string $storeNumber): Store
    {
        return Store::query()->where('store_number', $storeNumber)->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    public function indexForStore(Store $store, array $filters): LengthAwarePaginator
    {
        return $this->applyFilters(
            EmployeeShirtMilestone::query()->with(self::RELATIONS)->where('store_id', $store->id),
            $filters
        );
    }

    /**
     * The store manager's queue across several of their stores in one read —
     * the same shape as indexForStore, just not limited to one store.
     *
     * @param array<int> $storeIds
     */
    public function indexForStores(array $storeIds, array $filters): LengthAwarePaginator
    {
        return $this->applyFilters(
            EmployeeShirtMilestone::query()->with(self::RELATIONS)->whereIn('store_id', $storeIds),
            $filters
        );
    }

    public function indexGlobal(array $filters): LengthAwarePaginator
    {
        $query = EmployeeShirtMilestone::query()->with(self::RELATIONS);

        if (!empty($filters['stores']) && is_array($filters['stores'])) {
            $storeIds = Store::query()
                ->whereIn('store_number', $filters['stores'])
                ->pluck('id');

            $query->whereIn('store_id', $storeIds);
        }

        return $this->applyFilters($query, $filters);
    }

    private function applyFilters(Builder $query, array $filters): LengthAwarePaginator
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['statuses'] ?? null, fn (Builder $q, $v) => $q->whereIn('status', (array) $v))
            ->when($filters['source'] ?? null, fn (Builder $q, $v) => $q->where('source', $v))
            ->when($filters['employee_id'] ?? null, fn (Builder $q, $v) => $q->where('employee_id', $v))
            ->when($filters['milestone_month'] ?? null, fn (Builder $q, $v) => $q->where('milestone_month', $v))
            ->when($filters['due_from'] ?? null, fn (Builder $q, $v) => $q->whereDate('due_date', '>=', $v))
            ->when($filters['due_to'] ?? null, fn (Builder $q, $v) => $q->whereDate('due_date', '<=', $v))
            ->when($filters['q'] ?? null, function (Builder $q, $v) {
                $q->whereHas('employee', function (Builder $e) use ($v) {
                    $e->where('first_name', 'like', "%{$v}%")
                        ->orWhere('last_name', 'like', "%{$v}%")
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$v}%"]);
                });
            });

        // Oldest due first — that is the order the queue should be worked in.
        $query->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderByDesc('id');

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $query->paginate($perPage)->withQueryString();
    }

    public function load(EmployeeShirtMilestone $milestone): EmployeeShirtMilestone
    {
        return $milestone->load(self::RELATIONS);
    }

    /**
     * How many shirts this employee has received so far, and every detail of
     * each one.
     */
    public function historyForEmployee(Employee $employee): array
    {
        // One aggregate, not a get()->groupBy() in PHP.
        $counts = EmployeeShirtMilestone::query()
            ->where('employee_id', $employee->id)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status');

        $byStatus = [];
        foreach (ShirtMilestoneStatus::cases() as $case) {
            $byStatus[$case->value] = (int) ($counts[$case->value] ?? 0);
        }

        $milestones = EmployeeShirtMilestone::query()
            ->with(self::RELATIONS)
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $stintStart = $this->currentStintStart($employee);

        $lastDelivered = $milestones
            ->where('status', ShirtMilestoneStatus::Delivered)
            ->pluck('delivered_at')
            ->filter()
            ->max();

        return [
            'employee' => [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'middle_name' => $employee->middle_name,
                'last_name' => $employee->last_name,
                'gender' => $employee->gender,
                't_shirt_size' => $employee->obsession?->t_shirt,
            ],
            'summary' => [
                'total_delivered' => $byStatus[ShirtMilestoneStatus::Delivered->value],
                'total_milestones' => array_sum($byStatus),
                'by_status' => $byStatus,
                'current_stint_start_date' => $stintStart?->toDateString(),
                'months_with_company_current_stint' => $stintStart !== null
                    ? (int) $stintStart->diffInMonths(now())
                    : null,
                'last_delivered_at' => $lastDelivered?->toIso8601String(),
            ],
            'milestones' => $milestones,
        ];
    }

    // -------------------------------------------------------------------------
    // Transitions
    // -------------------------------------------------------------------------

    /**
     * Manual creation: the store fills the form there and then, without
     * waiting for a month to come around. Lands straight in `submitted`.
     */
    public function createManual(Store $store, array $data): EmployeeShirtMilestone
    {
        return DB::transaction(function () use ($store, $data) {
            $employee = Employee::findOrFail($data['employee_id']);

            $this->assertEmployeeInStore($store, $employee);

            $milestone = EmployeeShirtMilestone::query()->create([
                'employee_id' => $employee->id,
                'store_id' => $store->id,
                // NULL month and stint: not tied to a tenure milestone, and
                // the NULLs are what let any number of these coexist under
                // the (employee, stint, month) unique index.
                'milestone_month' => null,
                'stint_start_date' => null,
                'due_date' => $data['due_date'] ?? null,
                'source' => ShirtMilestoneSource::Manual,
                'status' => ShirtMilestoneStatus::PendingEntry,
                'created_by_user_id' => Auth::id(),
            ]);

            return $this->applyEntry($milestone, $employee, $store, $data);
        });
    }

    /**
     * Fill in the shirt form for a milestone the nightly generator opened.
     */
    public function submitEntry(Store $store, EmployeeShirtMilestone $milestone, array $data): EmployeeShirtMilestone
    {
        return DB::transaction(function () use ($store, $milestone, $data) {
            $this->assertMilestoneInStore($store, $milestone);
            $this->assertStatus($milestone, ShirtMilestoneStatus::PendingEntry);

            $employee = $milestone->employee()->firstOrFail();

            return $this->applyEntry($milestone, $employee, $store, $data);
        });
    }

    /**
     * Shared by manual creation and by filling in an existing milestone.
     */
    private function applyEntry(
        EmployeeShirtMilestone $milestone,
        Employee $employee,
        Store $store,
        array $data
    ): EmployeeShirtMilestone {
        $size = $data['t_shirt_size'] ?? $employee->obsession?->t_shirt?->value;

        $milestone->update([
            'shirt_color_id' => $data['shirt_color_id'],
            // Required by the request, so every real entry carries one. The
            // `?? null` only spares callers that go through the service
            // directly, as the existing tests do.
            'secondary_shirt_color_id' => $data['secondary_shirt_color_id'] ?? null,
            'shirt_logo_id' => $data['shirt_logo_id'],
            'shirt_template_id' => $data['shirt_template_id'] ?? $this->resolveTemplateId($employee),
            // Snapshot the size and gender: the catalogue and the employee
            // profile both move on, but what was ordered must not.
            't_shirt_size' => $size,
            'gender' => $employee->gender,
            'entry_notes' => $data['entry_notes'] ?? null,
            'status' => ShirtMilestoneStatus::Submitted,
            'submitted_by_user_id' => Auth::id(),
            'submitted_at' => now(),
        ]);

        // A size typed in here is also the employee's size from now on.
        if (!empty($data['t_shirt_size'])) {
            $this->persistShirtSize($employee, $data['t_shirt_size']);
        }

        $loaded = $this->load($milestone->refresh());

        $this->notifyFulfilment(
            $store,
            $loaded,
            'shirt_milestone_submitted',
            'Shirt entry submitted',
            'is ready to order.'
        );

        return $loaded;
    }

    public function markOrdered(EmployeeShirtMilestone $milestone, array $data): EmployeeShirtMilestone
    {
        return DB::transaction(function () use ($milestone, $data) {
            $this->assertStatus($milestone, ShirtMilestoneStatus::Submitted);

            $milestone->update([
                'status' => ShirtMilestoneStatus::Ordered,
                'ordered_by_user_id' => Auth::id(),
                'ordered_at' => now(),
                'delivery_date' => $data['delivery_date'],
            ]);

            $loaded = $this->load($milestone->refresh());

            $this->notifyEntry(
                $loaded,
                'shirt_milestone_ordered',
                'Shirt ordered',
                'has been ordered, expected ' . $loaded->delivery_date->toDateString() . '.'
            );

            return $loaded;
        });
    }

    /**
     * The delivery date slips; that is routine and does not move the status.
     */
    public function updateDeliveryDate(EmployeeShirtMilestone $milestone, array $data): EmployeeShirtMilestone
    {
        return DB::transaction(function () use ($milestone, $data) {
            $this->assertStatus($milestone, ShirtMilestoneStatus::Ordered);

            $milestone->update(['delivery_date' => $data['delivery_date']]);

            $loaded = $this->load($milestone->refresh());

            $this->notifyEntry(
                $loaded,
                'shirt_milestone_delivery_date_updated',
                'Shirt delivery date updated',
                'is now expected ' . $loaded->delivery_date->toDateString() . '.'
            );

            return $loaded;
        });
    }

    public function markDelivered(EmployeeShirtMilestone $milestone, array $data): EmployeeShirtMilestone
    {
        return DB::transaction(function () use ($milestone, $data) {
            $this->assertStatus($milestone, ShirtMilestoneStatus::Ordered);

            $milestone->update([
                'status' => ShirtMilestoneStatus::Delivered,
                'delivered_by_user_id' => Auth::id(),
                'delivered_at' => now(),
                'delivery_notes' => $data['delivery_notes'] ?? null,
            ]);

            $loaded = $this->load($milestone->refresh());

            $this->notifyEntry(
                $loaded,
                'shirt_milestone_delivered',
                'Shirt delivered',
                'has been delivered to the employee.'
            );

            return $loaded;
        });
    }

    public function cancel(EmployeeShirtMilestone $milestone, array $data): EmployeeShirtMilestone
    {
        return DB::transaction(function () use ($milestone, $data) {
            $this->assertStatus(
                $milestone,
                ShirtMilestoneStatus::PendingEntry,
                ShirtMilestoneStatus::Submitted,
                ShirtMilestoneStatus::Ordered,
            );

            $milestone->update([
                'status' => ShirtMilestoneStatus::Cancelled,
                'cancelled_by_user_id' => Auth::id(),
                'cancelled_at' => now(),
                'cancellation_reason' => $data['cancellation_reason'],
            ]);

            $loaded = $this->load($milestone->refresh());

            $this->notifyEntry(
                $loaded,
                'shirt_milestone_cancelled',
                'Shirt milestone cancelled',
                'was cancelled: ' . $data['cancellation_reason']
            );

            return $loaded;
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Writes the size back onto the employee's profile, creating the obsession
     * row when there isn't one. birth_date was made nullable for exactly this
     * case — see the 2026_09_16_000001 migration.
     */
    private function persistShirtSize(Employee $employee, string $size): void
    {
        EmployeeObsession::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            ['t_shirt' => $size]
        );
    }

    /**
     * Gender-specific artwork beats unisex, then whatever is flagged default.
     * Ordered explicitly because nothing stops two rows claiming is_default.
     */
    private function resolveTemplateId(Employee $employee): ?int
    {
        return ShirtTemplate::query()
            ->where('is_active', true)
            ->where(function (Builder $q) use ($employee) {
                $q->where('gender', $employee->gender->value)->orWhereNull('gender');
            })
            ->orderByRaw('gender IS NULL')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');
    }

    /**
     * Start of the employee's current employment stint, or null when they are
     * not currently employed.
     *
     * Deliberately not LaborReportService::currentStintStarts(): that one
     * filters to hired/rehired rows, and a terminated employee still has a
     * `hired` row behind them, so it keeps returning a start date forever.
     * The check has to be on the LATEST row, not on the presence of one.
     */
    public function currentStintStart(Employee $employee): ?CarbonInterface
    {
        $latest = $employee->statusHistories()
            ->whereDate('effective_date', '<=', now()->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first(['status', 'effective_date']);

        if ($latest === null) {
            return null;
        }

        $active = [EmployeeStatus::Hired, EmployeeStatus::Rehired];

        return in_array($latest->status, $active, true) ? $latest->effective_date : null;
    }

    // -------------------------------------------------------------------------
    // Notifications
    // -------------------------------------------------------------------------

    /**
     * A milestone has come due and is waiting on the store manager's form.
     * Called by the nightly generator.
     */
    public function notifyMilestoneOpened(EmployeeShirtMilestone $milestone): void
    {
        $month = $milestone->milestone_month;

        $this->notifyEntry(
            $milestone,
            'shirt_milestone_entry_required',
            'Shirt milestone ready',
            $month !== null
                ? "is due for their {$this->ordinal((int) $month)}-month shirt — please fill in the shirt details."
                : 'needs its shirt details filled in.'
        );
    }

    private function ordinal(int $n): string
    {
        $suffix = match (true) {
            in_array($n % 100, [11, 12, 13], true) => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };

        return $n . $suffix;
    }

    private function notifyEntry(
        EmployeeShirtMilestone $milestone,
        string $type,
        string $title,
        string $bodySuffix
    ): void {
        $this->sendRoleNotification(
            (array) config('shirt_milestones.roles.entry'),
            $milestone,
            $type,
            $title,
            $bodySuffix
        );
    }

    private function notifyFulfilment(
        Store $store,
        EmployeeShirtMilestone $milestone,
        string $type,
        string $title,
        string $bodySuffix
    ): void {
        $roles = (array) config('shirt_milestones.roles.fulfilment');

        // The fulfilment role has not been named yet. Fail closed rather than
        // send with an empty role list, which NotificationsPizza resolves as
        // "every role in these stores".
        if ($roles === []) {
            Log::warning('Shirt milestone submitted but no fulfilment role is configured; notification skipped.', [
                'milestone_id' => $milestone->id,
                'config_key' => 'shirt_milestones.roles.fulfilment',
            ]);

            return;
        }

        $this->sendRoleNotification($roles, $milestone, $type, $title, $bodySuffix, $store);
    }

    private function sendRoleNotification(
        array $roles,
        EmployeeShirtMilestone $milestone,
        string $type,
        string $title,
        string $bodySuffix,
        ?Store $store = null
    ): void {
        if ($roles === []) {
            return;
        }

        $store ??= $milestone->store ?? $milestone->store()->first();

        if ($store === null) {
            return;
        }

        $employee = $milestone->employee ?? $milestone->employee()->first();
        $employeeName = $employee !== null
            ? trim("{$employee->first_name} {$employee->last_name}")
            : "employee #{$milestone->employee_id}";

        $this->recordEvent('notifications.v1.notification.role.send', [
            'channels' => ['web'],
            'roles'    => array_values($roles),
            // NotificationsPizza matches user_store_roles.store_id, which is
            // populated from store_lc_id — the store NUMBER, not the id.
            'stores'   => [$store->store_number],
            'payload'  => [
                'type'       => $type,
                'title'      => $title,
                'body'       => "The shirt for {$employeeName} at Store {$store->store_number} {$bodySuffix}",
                'action_url' => "/hiring/store/{$store->store_number}/shirt-milestones/{$milestone->id}",
            ],
        ]);
    }

    private function recordEvent(string $subject, array $data): void
    {
        $factory = app(HiringEventFactory::class);
        $outbox  = app(HiringOutboxService::class);

        $envelope = $factory->make($subject, $data);
        $row      = $outbox->record($subject, $envelope);

        PublishOutboxEventJob::dispatch($row->id);
    }

    // -------------------------------------------------------------------------
    // Guards
    // -------------------------------------------------------------------------

    protected function assertEmployeeInStore(Store $store, Employee $employee): void
    {
        $latestStoreId = $employee->stores()
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->value('store_id');

        if ($latestStoreId === null || (int) $latestStoreId !== $store->id) {
            throw new ModelNotFoundException(
                "Employee {$employee->id} is not assigned to store {$store->store_number}"
            );
        }
    }

    /**
     * {storeId} is a store_number string, so route model binding resolves
     * {shirtMilestone} by global id and cannot scope it. Without this guard,
     * one store's manager could read and mutate another store's milestone.
     */
    protected function assertMilestoneInStore(Store $store, EmployeeShirtMilestone $milestone): void
    {
        if ((int) $milestone->store_id !== (int) $store->id) {
            throw new ModelNotFoundException(
                "Shirt milestone {$milestone->id} does not belong to store {$store->store_number}"
            );
        }
    }

    protected function assertStatus(EmployeeShirtMilestone $milestone, ShirtMilestoneStatus ...$allowed): void
    {
        if (!in_array($milestone->status, $allowed, true)) {
            $list = implode(', ', array_map(fn (ShirtMilestoneStatus $s) => $s->value, $allowed));

            throw new \LogicException(
                "Cannot perform this action on a shirt milestone with status '{$milestone->status->value}'. Allowed: {$list}."
            );
        }
    }
}
