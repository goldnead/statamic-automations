<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\StatamicAutomations\Casts\EncryptedJson;
use Goldnead\StatamicAutomations\Casts\MillisecondDateTime;
use Goldnead\StatamicAutomations\Engine\RunLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $automation_run_id
 * @property string|null $automation_uuid Copied down from the parent run, see below.
 * @property string $node_key
 * @property string|null $node_type
 * @property string $status
 * @property bool $is_test Copied down from the parent run, see below.
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property string|null $error_message
 * @property Carbon|null $started_at Millisecond precision, see MillisecondDateTime.
 * @property Carbon|null $finished_at Millisecond precision, see MillisecondDateTime.
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property-read AutomationRun|null $run
 */
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
        // Copied down from the parent run, see the 2026_08_15_000001 migration.
        // Both are decided when the run is created and never change, so the copy
        // cannot drift; what it buys is that "this automation's node runs, in
        // this window, tests excluded" is one index range on one table instead
        // of a join against every run the automation ever had.
        'automation_uuid',
        'node_key',
        'node_type',
        'status',
        'is_test',
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
        'is_test' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (AutomationNodeRun $run) {
            if (empty($run->uuid)) {
                $run->uuid = (string) Str::uuid();
            }

            $run->inheritFromRun();
        });
    }

    /**
     * Take `automation_uuid` and `is_test` from the parent run when the caller
     * did not supply them.
     *
     * {@see RunLogger} supplies both — it
     * has the run in hand and this then costs nothing. The fallback is here so
     * the invariant belongs to the model rather than to one call site: a row
     * written from anywhere else (a future writer, a test building a fixture)
     * still lands with a correct automation, instead of being invisible to every
     * activity query with no error to say so.
     */
    protected function inheritFromRun(): void
    {
        $uuidMissing = $this->automation_uuid === null;
        // Asked of the attribute bag, not of the accessor: `is_test` carries a
        // database default of false, so an unset attribute and an explicit
        // `false` read the same through the model and only the bag can tell
        // "nobody said" from "somebody said no".
        $testMissing = ! array_key_exists('is_test', $this->attributes);

        if ((! $uuidMissing && ! $testMissing) || empty($this->automation_run_id)) {
            return;
        }

        $parent = $this->relationLoaded('run') ? $this->getRelation('run') : $this->run()->first();

        if (! $parent instanceof AutomationRun) {
            return;
        }

        if ($uuidMissing) {
            $this->automation_uuid = $parent->automation_uuid;
        }

        if ($testMissing) {
            $this->is_test = (bool) $parent->is_test;
        }
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }
}
