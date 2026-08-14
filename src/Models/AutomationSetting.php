<?php

namespace Goldnead\StatamicAutomations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One setting an operator changed from the Control Panel.
 *
 * No brand trait, on purpose — see the migration for why these belong to the
 * installation rather than to a brand. Everything else in this addon is
 * brand-scoped, so the absence is stated here rather than left to be noticed.
 */
class AutomationSetting extends Model
{
    protected $table = 'automation_settings';

    protected $fillable = ['key', 'value'];

    protected $casts = [
        // `json`, not `array`: the values are not all lists. A boolean stored
        // through the `array` cast comes back wrapped, and `null` — which is a
        // real value here, meaning "same as the default retention" — would not
        // survive the round trip at all.
        'value' => 'json',
    ];
}
