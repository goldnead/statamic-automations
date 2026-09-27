<?php

namespace Goldnead\StatamicAutomations\Integrations\Notion;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns Notion's page objects into values a flow can use directly.
 *
 * A Notion property is a typed envelope (`{"type": "rich_text",
 * "rich_text": [{"plain_text": "…"}, …]}`); a flow wants the text. Each
 * property becomes its plain value, keyed by the property's name:
 *
 *   - title, rich_text → string
 *   - number, checkbox, url, email, phone_number → the value
 *   - select, status → the option's name; multi_select → list of names
 *   - date → {start, end, time_zone, has_time, start_date, start_time,
 *     end_date, end_time} (see {@see date()})
 *   - relation → list of page IDs
 *   - rollup → list of flattened values for an array rollup (relation entries
 *     spread into their IDs), the number or date itself otherwise
 *   - formula → its value
 *   - people, created_by, last_edited_by → names; files → URLs;
 *     unique_id → "PREFIX-12"; created_time, last_edited_time → the timestamp
 *
 * Anything else becomes null rather than an envelope a template would print
 * as JSON.
 */
class NotionData
{
    /**
     * A page ID from an ID with or without dashes, or from a Notion URL
     * (the last 32 hex characters in it), in the dashed form. Null when
     * there is none.
     */
    public static function id(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));

        if (! preg_match_all('/[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $value, $matches)) {
            return null;
        }

        $hex = str_replace('-', '', (string) end($matches[0]));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    /**
     * A list of page IDs from a list, a JSON list, or text separated by
     * commas, spaces or line breaks. Unique, in order.
     *
     * @return list<string>
     */
    public static function ids(mixed $value): array
    {
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : $value;
        }

        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            $value = $value === null ? [] : [$value];
        }

        $ids = [];

        array_walk_recursive($value, function ($item) use (&$ids) {
            $id = self::id($item);

            if ($id !== null) {
                $ids[$id] = true;
            }
        });

        return array_keys($ids);
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    public static function page(array $page, string $timeZone): array
    {
        $properties = [];
        $title = '';

        foreach ((array) ($page['properties'] ?? []) as $name => $property) {
            if (! is_array($property)) {
                continue;
            }

            $properties[(string) $name] = self::property($property, $timeZone);

            if (($property['type'] ?? null) === 'title' && $title === '') {
                $title = trim((string) $properties[(string) $name]);
            }
        }

        return [
            'id' => (string) ($page['id'] ?? ''),
            'url' => $page['url'] ?? null,
            'title' => $title,
            'created_time' => $page['created_time'] ?? null,
            'last_edited_time' => $page['last_edited_time'] ?? null,
            'properties' => $properties,
        ];
    }

    /**
     * @param  array<string, mixed>  $property
     */
    public static function property(array $property, string $timeZone): mixed
    {
        $type = (string) ($property['type'] ?? '');
        $value = $property[$type] ?? null;

        return match ($type) {
            'title', 'rich_text' => self::text(is_array($value) ? $value : []),
            'number', 'checkbox', 'url', 'email', 'phone_number', 'created_time', 'last_edited_time' => $value,
            'select', 'status' => is_array($value) ? ($value['name'] ?? null) : null,
            'multi_select' => is_array($value) ? array_values(array_map(fn ($o) => $o['name'] ?? null, $value)) : [],
            'date' => is_array($value) ? self::date($value, $timeZone) : null,
            'relation' => is_array($value) ? array_values(array_filter(array_map(fn ($r) => is_array($r) ? ($r['id'] ?? null) : null, $value))) : [],
            'rollup' => is_array($value) ? self::rollup($value, $timeZone) : null,
            'formula' => is_array($value) ? self::formula($value, $timeZone) : null,
            'people' => is_array($value) ? array_values(array_map(fn ($p) => $p['name'] ?? ($p['id'] ?? null), $value)) : [],
            'created_by', 'last_edited_by' => is_array($value) ? ($value['name'] ?? ($value['id'] ?? null)) : null,
            'files' => is_array($value) ? array_values(array_map(fn ($f) => $f['file']['url'] ?? ($f['external']['url'] ?? ($f['name'] ?? null)), $value)) : [],
            'unique_id' => is_array($value) && isset($value['number']) ? ltrim(($value['prefix'] ?? '').'-'.$value['number'], '-') : null,
            'verification' => is_array($value) ? ($value['state'] ?? null) : null,
            default => null,
        };
    }

    /**
     * @param  array<int, mixed>  $richText
     */
    public static function text(array $richText): string
    {
        return implode('', array_map(fn ($part) => is_array($part) ? (string) ($part['plain_text'] ?? '') : '', $richText));
    }

    /**
     * A Notion date, with the time zone applied.
     *
     * Notion sends a date with a time in one of two forms: with an offset in
     * the string (`2026-10-03T13:00:00.000+00:00`), or, when the property
     * carries a `time_zone`, without one, meaning local time in that zone.
     * Read with a plain parser, the second form lands in the server's zone
     * and is off by the zone's offset; that is the trap of Notion's own
     * `Time` formulas too.
     *
     * `start` and `end` therefore come out as ISO 8601 with the offset
     * written in (`2026-10-03T15:00:00+02:00`), which every date filter and
     * modifier reads correctly. A date without a time stays `2026-10-03`.
     * `start_date`, `start_time`, `end_date` and `end_time` are the same
     * moments in `$timeZone`, the zone the reader of the text lives in.
     *
     * @param  array<string, mixed>  $date
     * @return array<string, mixed>
     */
    public static function date(array $date, string $timeZone): array
    {
        $zone = is_string($date['time_zone'] ?? null) && $date['time_zone'] !== '' ? $date['time_zone'] : null;
        $start = self::moment($date['start'] ?? null, $zone);
        $end = self::moment($date['end'] ?? null, $zone);
        $hasTime = $start !== null && $start['has_time'];

        return [
            'start' => $start['value'] ?? null,
            'end' => $end['value'] ?? null,
            'time_zone' => $zone,
            'has_time' => $hasTime,
            'start_date' => self::local($start, $timeZone, 'Y-m-d'),
            'start_time' => $hasTime ? self::local($start, $timeZone, 'H:i') : null,
            'end_date' => self::local($end, $timeZone, 'Y-m-d'),
            'end_time' => $end !== null && $end['has_time'] ? self::local($end, $timeZone, 'H:i') : null,
        ];
    }

    /**
     * @return array{value: string, has_time: bool, carbon: CarbonImmutable|null}|null
     */
    protected static function moment(mixed $value, ?string $zone): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (strlen($value) <= 10) {
            return ['value' => $value, 'has_time' => false, 'carbon' => null];
        }

        try {
            // A zone given here only counts when the string has no offset,
            // which is exactly the case Notion uses `time_zone` for.
            $carbon = CarbonImmutable::parse($value, $zone ?? 'UTC');

            if ($zone !== null) {
                $carbon = $carbon->setTimezone($zone);
            }

            return ['value' => $carbon->format('Y-m-d\TH:i:sP'), 'has_time' => true, 'carbon' => $carbon];
        } catch (Throwable) {
            return ['value' => $value, 'has_time' => true, 'carbon' => null];
        }
    }

    /**
     * @param  array{value: string, has_time: bool, carbon: CarbonImmutable|null}|null  $moment
     */
    protected static function local(?array $moment, string $timeZone, string $format): ?string
    {
        if ($moment === null) {
            return null;
        }

        if ($moment['carbon'] === null) {
            return $moment['has_time'] ? null : ($format === 'Y-m-d' ? $moment['value'] : null);
        }

        try {
            return $moment['carbon']->setTimezone($timeZone)->format($format);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $rollup
     */
    protected static function rollup(array $rollup, string $timeZone): mixed
    {
        $type = (string) ($rollup['type'] ?? '');

        if ($type === 'number') {
            return $rollup['number'] ?? null;
        }

        if ($type === 'date') {
            return is_array($rollup['date'] ?? null) ? self::date($rollup['date'], $timeZone) : null;
        }

        if ($type !== 'array') {
            return null;
        }

        $values = [];

        foreach ((array) ($rollup['array'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $value = self::property($entry, $timeZone);

            // A rollup of a relation is a list of lists of IDs; a flow wants
            // the IDs, to fetch the pages behind them.
            if (($entry['type'] ?? null) === 'relation' && is_array($value)) {
                array_push($values, ...$value);

                continue;
            }

            if ($value !== null && $value !== '' && $value !== []) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $formula
     */
    protected static function formula(array $formula, string $timeZone): mixed
    {
        $type = (string) ($formula['type'] ?? '');
        $value = $formula[$type] ?? null;

        return $type === 'date' && is_array($value) ? self::date($value, $timeZone) : $value;
    }
}
