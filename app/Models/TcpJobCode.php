<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Local mirror of TCP's job-code catalog. Written only by tcp:sync-job-codes. */
class TcpJobCode extends Model
{
    protected $fillable = [
        'tcp_job_code_id', 'description', 'store_number',
        'clockable', 'is_active', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'clockable' => 'boolean',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
