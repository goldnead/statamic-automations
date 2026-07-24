<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
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

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}
