<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AutomationScheduledJob extends Model
{
    use HasBrand;

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'automation_scheduled_jobs';

    protected $fillable = [
        'uuid',
        'automation_id',
        'automation_run_id',
        'node_key',
        'due_at',
        'status',
        'payload',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (AutomationScheduledJob $job) {
            if (empty($job->uuid)) {
                $job->uuid = (string) Str::uuid();
            }
        });
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }
}
