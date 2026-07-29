<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeparationRequestAttachment extends Model
{
    protected $table = 'separation_request_attachments';

    protected $guarded = [];

    protected $appends = ['attachment_url'];

    protected function getAttachmentUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        $secure = str_starts_with((string) config('app.url'), 'https://') ? true : null;

        return asset('storage/' . $this->file_path, $secure);
    }

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
