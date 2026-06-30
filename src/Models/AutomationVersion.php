<?php

namespace Goldnead\StatamicAutomations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationVersion extends Model
{
    protected $table = 'automation_versions';

    protected $fillable = [
        'automation_id',
        'version',
        'label',
        'snapshot',
        'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'snapshot' => 'array',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}
