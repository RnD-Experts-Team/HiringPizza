<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local mirror of "which TCP roleId belongs to which store". Written by
 * tcp:sync-role-map (observed from existing employees) or tcp:map-role
 * (manual). TcpEmployeeMapper reads this — never TCP live.
 */
class TcpStoreRole extends Model
{
    protected $fillable = [
        'store_number', 'role_id', 'source', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }
}
