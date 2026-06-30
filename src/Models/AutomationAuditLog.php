<?php

namespace Goldnead\StatamicAutomations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'automation_audit_logs';

    protected $fillable = [
        'automation_id',
        'action',
        'user_id',
        'user_label',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}
