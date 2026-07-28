<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationEdge extends Model
{
    use HasBrand;

    protected $table = 'automation_edges';

    protected $fillable = [
        'uuid',
        'automation_id',
        'from_node_key',
        'from_output',
        'to_node_key',
        'to_input',
    ];

    protected static function booted(): void
    {
        static::creating(function (AutomationEdge $edge) {
            if (empty($edge->uuid)) {
                $edge->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    /**
     * An edge handle is never empty; absent means `default`.
     *
     * Every caller that builds an edge from request or file data wrote
     * `$edge['from_output'] ?? 'default'`, which only substitutes for a
     * *missing* key. An empty string is a present one, and `''` is what the
     * CP sends for a cleared field and what `['nullable', 'string']` happily
     * accepts. Stored, it is invisible on the canvas and fatal at run time:
     * `WorkflowRunner` matches outgoing edges with `$e->from_output === $output`,
     * so an edge on `''` is never followed and the automation stops there with
     * no error to show for it.
     *
     * Normalising in the model rather than at each of the five call sites means
     * every write path — CP save, import, template install, version revert,
     * repository sync — gets the same guarantee, including the ones added next.
     */
    protected function fromOutput(): Attribute
    {
        return Attribute::make(set: fn ($value) => $this->handleOrDefault($value));
    }

    protected function toInput(): Attribute
    {
        return Attribute::make(set: fn ($value) => $this->handleOrDefault($value));
    }

    private function handleOrDefault(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? 'default' : (string) $value;
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}
