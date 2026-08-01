<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Automation extends Model
{
    use HasBrand;

    protected $table = 'automations';

    /**
     * Whether the automations schema has been migrated. Trigger listeners
     * guard on this so publishing an entry / submitting a form never crashes
     * when the addon is installed but its migrations haven't run yet (e.g.
     * during deploy-before-migrate, or in a host test suite that doesn't load
     * the addon migrations).
     */
    public static function schemaReady(): bool
    {
        return Schema::hasTable((new static)->getTable());
    }

    protected $fillable = [
        'uuid',
        'name',
        'handle',
        'description',
        'enabled',
        'version',
        'created_by',
        'last_run_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'version' => 'integer',
        'last_run_at' => 'datetime',
    ];

    /**
     * Boot a uuid for new models if not provided.
     */
    protected static function booted(): void
    {
        static::creating(function (Automation $automation) {
            if (empty($automation->uuid)) {
                $automation->uuid = (string) Str::uuid();
            }
        });
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(AutomationNode::class);
    }

    public function edges(): HasMany
    {
        return $this->hasMany(AutomationEdge::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function scheduledJobs(): HasMany
    {
        return $this->hasMany(AutomationScheduledJob::class);
    }

    public function triggerNode(): ?AutomationNode
    {
        return $this->nodes->first(fn (AutomationNode $node) => str_contains($node->type, 'trigger') || $node->isTrigger());
    }
}
