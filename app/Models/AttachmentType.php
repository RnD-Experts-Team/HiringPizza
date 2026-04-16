<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttachmentType extends Model
{
    protected $table = 'attachment_types';

    protected $guarded = [];

    public function employeeAttachments(): HasMany
    {
        return $this->hasMany(EmployeeAttachment::class, 'type_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'attachment_tags', 'attachement_id', 'tag_id');
    }
}