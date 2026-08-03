<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $uuid The node's stable identity across an export/import.
 * @property string $node_key The key edges refer to. Unique inside one
 *                            automation and stable across a save, which is why
 *                            the runner, the validator and the mail list all
 *                            address nodes by it rather than by row id.
 * @property string $type The registered node handle, e.g. `send_email`.
 * @property string|null $label What an editor called this node, if anything.
 * @property array<string, mixed>|null $config The node's settings, including
 *                                             the reserved `_`-prefixed keys
 *                                             (`_on_error`, `_retry_attempts`,
 *                                             `_restart_policy`).
 * @property int $position_x
 * @property int $position_y
 * @property bool $disabled
 */
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
