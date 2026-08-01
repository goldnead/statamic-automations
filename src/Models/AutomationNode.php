<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AutomationNode extends Model
{
    use HasBrand;

    protected $table = 'automation_nodes';

    protected $fillable = [
        'uuid',
        'automation_id',
        'node_key',
        'type',
        'label',
        'position_x',
        'position_y',
        'config',
        'disabled',
    ];

    protected $casts = [
        'config' => 'array',
        'disabled' => 'boolean',
        'position_x' => 'integer',
        'position_y' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (AutomationNode $node) {
            if (empty($node->uuid)) {
                $node->uuid = (string) Str::uuid();
            }
        });
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function isTrigger(): bool
    {
        $registry = app(NodeRegistry::class);
        $entry = $registry->get($this->type);

        return ($entry['kind'] ?? null) === 'trigger';
    }

    public function isAction(): bool
    {
        $registry = app(NodeRegistry::class);
        $entry = $registry->get($this->type);

        return ($entry['kind'] ?? null) === 'action';
    }

    public function isLogic(): bool
    {
        $registry = app(NodeRegistry::class);
        $entry = $registry->get($this->type);

        return ($entry['kind'] ?? null) === 'logic';
    }

    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config ?? [], $key, $default);
    }
}
