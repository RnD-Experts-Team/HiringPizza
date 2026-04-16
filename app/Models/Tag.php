<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $table = 'tags';

    public $timestamps = false;

    protected $guarded = [];

    public function attachmentTypes(): BelongsToMany
    {
        return $this->belongsToMany(AttachmentType::class, 'attachement_tag', 'tag_id', 'attachement_id');
    }
}