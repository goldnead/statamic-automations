<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\StatamicAutomations\Casts\EncryptedJson;
use Goldnead\StatamicAutomations\Casts\MillisecondDateTime;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $uuid
 * @property int|null $automation_id
 * @property-read Automation|null $automation The definition this run belongs to,
 *                when it still exists — a run outlives the automation it came from.
 * @property string|null $automation_uuid The canonical reference — the only one
 *                                        a flat-file definition has.
 * @property string|null $trigger_node_key
 * @property string|null $trigger_type
 * @property string|null $subject_key Who this pass is about, normally a
 *                                    lower-cased address. Null for a trigger
 *                                    that names nobody. See Support\RestartPolicy.
 * @property string $status
 * @property array<string, mixed>|null $context
 * @property bool $is_test
 * @property string|null $error_message
 * @property Carbon|null $started_at Millisecond precision, see MillisecondDateTime.
 * @property Carbon|null $finished_at Millisecond precision, see MillisecondDateTime.
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 */
class AutomationRun extends Model
{
    use HasBrand;

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
        'automation_uuid',
        'trigger_node_key',
        'trigger_type',
        // Who this run is about — normally a lower-cased email address. Null
        // for a trigger that names nobody. See Support\RestartPolicy.
        'subject_key',
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
        // Millisecond precision — see MillisecondDateTime. The default
        // `datetime` cast writes `Y-m-d H:i:s` and drops the fraction before
        // it ever reaches the column.
        'started_at' => MillisecondDateTime::class,
        'finished_at' => MillisecondDateTime::class,
        'duration_ms' => 'integer',
        'is_test' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (AutomationRun $run) {
            if (empty($run->uuid)) {
                $run->uuid = (string) Str::uuid();
            }
        });
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /**
     * Resolve the run's automation through the active storage driver.
     *
     * In database mode this is the related row; in flat-file mode it loads
     * the definition by uuid from the repository (the Eloquent relation is
     * null there). Returns null if the definition no longer exists.
     */
    public function resolveAutomation(): ?Automation
    {
        $repository = app(AutomationRepository::class);

        if ($this->automation_uuid && ($found = $repository->find($this->automation_uuid))) {
            return $found;
        }

        return $this->automation_id ? $repository->find($this->automation_id) : null;
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
