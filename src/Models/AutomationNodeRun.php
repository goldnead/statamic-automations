<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\StatamicAutomations\Casts\EncryptedJson;
use Goldnead\StatamicAutomations\Casts\MillisecondDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationNodeRun extends Model
{
    use HasBrand;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_FAILED = 'failed';

    protected $table = 'automation_node_runs';

    protected $fillable = [
        'uuid',
        'automation_run_id',
        'node_key',
        'node_type',
        'status',
        'input',
        'output',
        'error_message',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected $casts = [
        'input' => EncryptedJson::class,
        'output' => EncryptedJson::class,
        // Millisecond precision — a node that runs in 40 ms must not collapse
        // onto the same stored instant as the one before it.
        'started_at' => MillisecondDateTime::class,
        'finished_at' => MillisecondDateTime::class,
        'duration_ms' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (AutomationNodeRun $run) {
            if (empty($run->uuid)) {
                $run->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }
}
