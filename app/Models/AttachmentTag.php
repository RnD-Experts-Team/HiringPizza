<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachmentTag extends Model
{
    protected $table = 'attachment_tags';

    protected $guarded = [];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function attachmentType(): BelongsTo
    {
        return $this->belongsTo(AttachmentType::class, 'attachement_id');
    }
}