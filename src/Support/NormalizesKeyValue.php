<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * Shared normalisation for `key_value` config fields, which may arrive
 * either as an associative map or as a list of {key, value} pairs, or as
 * a JSON string. Always returns an associative array.
 */
trait NormalizesKeyValue
{
    /**
     * Static so it is also callable from static contexts (e.g. a node's
     * static `outputs()` introspection, which needs to derive dynamic
     * output handles from raw config without an instance). Existing
     * `$this->normalizeKeyValue(...)` call sites keep working unchanged —
     * PHP allows calling static methods through `->`.
     *
     * @return array<string, mixed>
     */
    protected static function normalizeKeyValue(mixed $raw): array
    {
        if (is_array($raw)) {
            if ($raw === [] || ! array_is_list($raw)) {
                return $raw;
            }

            $out = [];
            foreach ($raw as $pair) {
                if (is_array($pair) && isset($pair['key'])) {
                    $out[$pair['key']] = $pair['value'] ?? null;
                }
            }

            return $out;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
