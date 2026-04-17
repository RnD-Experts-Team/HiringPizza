<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class HiringOutboxEvent extends Model
{
    use HasUlids;

    protected $table = 'hiring_outbox_events';

    protected $fillable = [
        'subject',
        'type',
        'payload',
        'attempts',
        'last_error',
        'published_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'datetime',
    ];
}
