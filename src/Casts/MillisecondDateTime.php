<?php

namespace Goldnead\StatamicAutomations\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Carbon;

/**
 * A datetime attribute that keeps its milliseconds on the way into the database.
 *
 * Eloquent serialises every date-castable attribute with the *connection's*
 * date format, which is `Y-m-d H:i:s` for every driver Laravel ships. That
 * truncation happens in the model, before the column is ever consulted — so
 * widening `started_at` / `finished_at` to `timestamp(3)` alone changes
 * nothing: the value handed to the driver has already lost its fraction.
 *
 * This cast overrides the format for the attributes that need sub-second
 * resolution, and only for those. Setting `$dateFormat` on the model would
 * have been the shorter route, but it also applies to `created_at` /
 * `updated_at`, which are whole-second columns — MySQL rounds a fractional
 * value written into them, shifting those stamps by up to half a second.
 *
 * Reading stays lenient on purpose: rows written before this release carry no
 * fraction, and `Carbon::parse()` handles both shapes.
 */
class MillisecondDateTime implements CastsAttributes
{
    /** Millisecond precision — matches the `timestamp(3)` columns. */
    public const FORMAT = 'Y-m-d H:i:s.v';

    public function get($model, string $key, $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        return is_numeric($value)
            ? Carbon::createFromTimestamp($value)
            : Carbon::parse($value);
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = match (true) {
            $value instanceof DateTimeInterface => Carbon::instance($value),
            is_numeric($value) => Carbon::createFromTimestamp($value),
            default => Carbon::parse($value),
        };

        return $date->format(self::FORMAT);
    }
}
