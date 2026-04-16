<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeparationRequestAttachment extends Model
{
    protected $table = 'separation_request_attachments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function separationRequest(): BelongsTo
    {
        return $this->belongsTo(SeparationRequest::class);
    }
}
