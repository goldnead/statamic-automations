<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\StatamicAutomations\Casts\EncryptedJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_WAITING = 'waiting';

    protected $table = 'automation_runs';

    protected $fillable = [
        'uuid',
        'automation_id',
        'trigger_node_key',
        'trigger_type',
        'status',
        'context',
        'started_at',
        'finished_at',
        'duration_ms',
        'error_message',
        'is_test',
    ];

    protected $casts = [
        'context' => EncryptedJson::class,
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'is_test' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (AutomationRun $run) {
            if (empty($run->uuid)) {
                $run->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function nodeRuns(): HasMany
    {
        return $this->hasMany(AutomationNodeRun::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_STOPPED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
