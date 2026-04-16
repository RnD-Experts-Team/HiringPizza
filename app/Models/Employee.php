<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\Gender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'ssn',
    ];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'employment_type' => EmploymentType::class,
            'ssn' => 'encrypted',
        ];
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(EmployeeStatusHistory::class);
    }

    public function payHistories(): HasMany
    {
        return $this->hasMany(EmployeePayHistory::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(EmployeeContact::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(EmployeeAddress::class);
    }

    public function availabilityDays(): HasMany
    {
        return $this->hasMany(EmployeeAvailabilityDay::class);
    }

    public function financialInfos(): HasMany
    {
        return $this->hasMany(EmployeeFinancialInfo::class);
    }

    public function ids(): HasMany
    {
        return $this->hasMany(EmployeeId::class);
    }

    public function obsession(): HasOne
    {
        return $this->hasOne(EmployeeObsession::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(EmployeePosition::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(EmployeeStore::class);
    }

    public function maritals(): HasMany
    {
        return $this->hasMany(EmployeeMarital::class, 'emp_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmployeeAttachment::class, 'emp_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}