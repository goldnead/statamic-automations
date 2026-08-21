<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * "Diese Person will von dieser Serie nichts mehr."
 *
 * Eine Zeile, eine Aussage — siehe die Migration fuer das Warum. Wichtig ist,
 * was hier NICHT steht: nichts ueber Listen, nichts ueber Einwilligung, nichts
 * ueber andere Automationen. Der Ausstieg aus einer Willkommensstrecke darf
 * den Newsletter nicht kosten, sonst ist er keine Wahl, sondern ein Ultimatum.
 *
 * @property string $uuid
 * @property string $automation_uuid
 * @property string $subject_key
 * @property \Illuminate\Support\Carbon $opted_out_at
 * @property string $source
 */
class AutomationOptOut extends Model
{
    use HasBrand;

    /** Ueber den Link im Fuss einer Mail. */
    public const SOURCE_MAIL_LINK = 'mail_link';

    /** Ueber die Selbstbedienungs-Seite. */
    public const SOURCE_PREFERENCE_CENTER = 'preference_center';

    /** Von Hand im Control Panel. */
    public const SOURCE_CP = 'cp';

    protected $table = 'automation_opt_outs';

    protected $fillable = [
        'uuid',
        'brand_id',
        'automation_uuid',
        'subject_key',
        'opted_out_at',
        'source',
    ];

    protected $casts = [
        'opted_out_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AutomationOptOut $optOut) {
            if (empty($optOut->uuid)) {
                $optOut->uuid = (string) Str::uuid();
            }

            if (empty($optOut->opted_out_at)) {
                $optOut->opted_out_at = now();
            }
        });
    }
}
