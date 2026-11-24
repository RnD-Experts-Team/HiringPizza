<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\ShirtMilestoneSource;
use App\Enums\ShirtMilestoneStatus;
use App\Enums\TShirtSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeShirtMilestone extends Model
{
    protected $table = 'employee_shirt_milestones';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => ShirtMilestoneSource::class,
            'status' => ShirtMilestoneStatus::class,
            't_shirt_size' => TShirtSize::class,
            'gender' => Gender::class,
            'stint_start_date' => 'date',
            'due_date' => 'date',
            'delivery_date' => 'date',
            'submitted_at' => 'datetime',
            'ordered_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shirtColor(): BelongsTo
    {
        return $this->belongsTo(ShirtColor::class);
    }

    /**
     * The fallback colour, ordered when the first choice is not available.
     */
    public function secondaryShirtColor(): BelongsTo
    {
        return $this->belongsTo(ShirtColor::class, 'secondary_shirt_color_id');
    }

    public function shirtLogo(): BelongsTo
    {
        return $this->belongsTo(ShirtLogo::class);
    }

    public function shirtTemplate(): BelongsTo
    {
        return $this->belongsTo(ShirtTemplate::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function orderedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by_user_id');
    }

    public function deliveredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }
}
