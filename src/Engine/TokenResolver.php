<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Support\SecretStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Replaces {{ token }} expressions in strings and arrays against a
 * given AutomationContext. Supports dot notation and an opt-in
 * redaction layer for sensitive keys.
 *
 * Tokens take the form: {{ lead.email }}, {{ form.message }}, {{ nodes.x.y }}.
 * Whitespace inside the braces is ignored.
 */
class TokenResolver
{
    /**
     * Resolve tokens in any value. Strings get pattern-matched, arrays
     * are walked recursively, scalars pass through unchanged.
     */
    public function resolve(mixed $value, AutomationContext $context): mixed
    {
        if (is_string($value)) {
            return $this->resolveString($value, $context);
        }

        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $inner) {
                $resolved[$key] = $this->resolve($inner, $context);
            }

            return $resolved;
        }

        return $value;
    }

    /**
     * Resolve every token in a string. If the string is a single token
     * (e.g. "{{ lead }}") and the resolved value is non-scalar (array,
     * object), the structured value is returned as-is so subsequent
     * code can use it directly.
     */
    public function resolveString(string $value, AutomationContext $context): mixed
    {
        $token = '([\w\.\-]+)\s*((?:\|\s*\w+(?::[^|}]*)?\s*)*)';

        // Single-token shortcut → preserve structured values when there are
        // no filters; otherwise apply the filter chain and return the result.
        if (preg_match('/^\s*\{\{\s*'.$token.'\}\}\s*$/', $value, $match)) {
            $resolved = $this->resolveToken($match[1], $context);
            $filters = trim($match[2]);

            return $filters === '' ? $resolved : $this->applyFilters($resolved, $filters);
        }

        return preg_replace_callback(
            '/\{\{\s*'.$token.'\}\}/',
            function ($match) use ($context) {
                $resolved = $this->resolveToken($match[1], $context);
                $filters = trim($match[2]);

                if ($filters !== '') {
                    $resolved = $this->applyFilters($resolved, $filters);
                }

                if ($resolved === null) {
                    return '';
                }

                if (is_scalar($resolved)) {
                    return (string) $resolved;
                }

                return json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            },
            $value,
        );
    }

    /**
     * Resolve a single token name to a value. Tokens beginning with
     * "secret." are pulled from the SecretStore so credentials never need
     * to live inside a node's stored config; everything else reads from the
     * run context via dot notation.
     */
    protected function resolveToken(string $name, AutomationContext $context): mixed
    {
        if (str_starts_with($name, 'secret.')) {
            return app(SecretStore::class)
                ->get(substr($name, strlen('secret.')));
        }

        return $context->get($name);
    }

    /**
     * Apply a pipe-separated filter chain to a value, e.g.
     * "| lower | date:Y-m-d | default:N/A".
     */
    public function applyFilters(mixed $value, string $chain): mixed
    {
        $filters = array_filter(array_map('trim', explode('|', $chain)));

        foreach ($filters as $filter) {
            [$name, $arg] = array_pad(explode(':', $filter, 2), 2, null);
            $value = $this->applyFilter(trim($name), $value, $arg !== null ? trim($arg) : null);
        }

        return $value;
    }

    protected function applyFilter(string $name, mixed $value, ?string $arg): mixed
    {
        return match ($name) {
            'lower' => is_scalar($value) ? mb_strtolower((string) $value) : $value,
            'upper' => is_scalar($value) ? mb_strtoupper((string) $value) : $value,
            'ucfirst' => is_scalar($value) ? ucfirst((string) $value) : $value,
            'title' => is_scalar($value) ? ucwords((string) $value) : $value,
            'trim' => is_scalar($value) ? trim((string) $value) : $value,
            'slug' => is_scalar($value) ? Str::slug((string) $value) : $value,
            'length' => is_array($value) ? count($value) : mb_strlen((string) $value),
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'default' => ($value === null || $value === '') ? $arg : $value,
            'date' => $this->formatDate($value, $arg ?: 'Y-m-d'),
            'join' => $this->join($value, $arg),
            'pluck' => $this->pluck($value, $arg),
            'first' => is_array($value) ? ($value === [] ? null : reset($value)) : $value,
            'last' => is_array($value) ? ($value === [] ? null : end($value)) : $value,
            'split' => $this->split($value, $arg),
            'replace' => $this->replace($value, $arg),
            'json_decode' => $this->jsonDecode($value),
            'where' => $this->where($value, $arg),
            default => $value,
        };
    }

    /**
     * `date:<format>` or `date:<format>,<time zone>`.
     *
     * The time zone is recognised as the part after the last comma when it
     * names a real zone, so a format with a comma in it (`D, d.m.`) keeps
     * working. A Notion date value (`{start, end, time_zone}`) formats its
     * start.
     */
    protected function formatDate(mixed $value, string $format): mixed
    {
        if (is_array($value) && array_key_exists('start', $value)) {
            $value = $value['start'];
        }

        if ($value === null || $value === '' || ! is_scalar($value)) {
            return $value;
        }

        $zone = null;
        $comma = strrpos($format, ',');

        if ($comma !== false) {
            $candidate = trim(substr($format, $comma + 1));

            if (in_array($candidate, \DateTimeZone::listIdentifiers(), true)) {
                $zone = $candidate;
                $format = rtrim(substr($format, 0, $comma));
            }
        }

        try {
            $date = Carbon::parse((string) $value);

            return ($zone !== null ? $date->setTimezone($zone) : $date)->format($format);
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * A filter argument as written, for the filters that need exact
     * whitespace: quotes keep what the trimming around them would take
     * (`join:", "`), and `\n` / `\t` stand for a line break and a tab.
     */
    protected function literal(?string $arg, string $default): string
    {
        if ($arg === null || $arg === '') {
            return $default;
        }

        if (strlen($arg) >= 2 && ($arg[0] === '"' || $arg[0] === "'") && substr($arg, -1) === $arg[0]) {
            $arg = substr($arg, 1, -1);
        }

        return strtr($arg, ['\\n' => "\n", '\\t' => "\t"]);
    }

    /**
     * Two arguments separated by the first comma, each a {@see literal()}.
     *
     * @return array{0: string, 1: string}
     */
    protected function pair(?string $arg): array
    {
        [$first, $second] = array_pad(explode(',', (string) $arg, 2), 2, '');

        return [$this->literal(trim($first), ''), $this->literal(trim($second), '')];
    }

    protected function join(mixed $value, ?string $arg): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $parts = array_map(
            fn ($item) => is_scalar($item) || $item === null ? (string) $item : json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            array_values($value),
        );

        return implode($this->literal($arg, ', '), $parts);
    }

    protected function pluck(mixed $value, ?string $arg): mixed
    {
        if (! is_array($value) || $arg === null || $arg === '') {
            return $value;
        }

        return array_map(fn ($item) => data_get($item, $arg), array_values($value));
    }

    protected function split(mixed $value, ?string $arg): mixed
    {
        if (! is_scalar($value)) {
            return $value;
        }

        $separator = $this->literal($arg, ',');

        if ((string) $value === '') {
            return [];
        }

        return array_map('trim', explode($separator, (string) $value));
    }

    protected function replace(mixed $value, ?string $arg): mixed
    {
        if (! is_scalar($value)) {
            return $value;
        }

        [$from, $to] = $this->pair($arg);

        return $from === '' ? (string) $value : str_replace($from, $to, (string) $value);
    }

    protected function jsonDecode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    /**
     * Keep the items whose `key` (dot notation) equals `value`, compared as
     * text, so `where:status,Confirmed` and `where:done,true` both read the
     * way they are written. The result is a list again.
     */
    protected function where(mixed $value, ?string $arg): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        [$key, $expected] = $this->pair($arg);

        if ($key === '') {
            return $value;
        }

        return array_values(array_filter($value, function ($item) use ($key, $expected) {
            $actual = data_get($item, $key);

            if (is_bool($actual)) {
                $actual = $actual ? 'true' : 'false';
            }

            return is_scalar($actual) && (string) $actual === $expected;
        }));
    }

    /**
     * Walk an array and redact values whose keys match the configured
     * sensitive patterns. Returns a new array; does not mutate input.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>|null  $redactKeys
     */
    public function redact(array $data, ?array $redactKeys = null): array
    {
        $patterns = $redactKeys ?? config('automations.security.redact_keys', []);

        if (empty($patterns)) {
            return $data;
        }

        $patterns = array_map('strtolower', $patterns);

        return $this->walkRedact($data, $patterns);
    }

    protected function walkRedact(array $data, array $patterns): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $keyLower = strtolower((string) $key);
            $shouldRedact = false;

            foreach ($patterns as $pattern) {
                if (str_contains($keyLower, $pattern)) {
                    $shouldRedact = true;
                    break;
                }
            }

            if ($shouldRedact) {
                $out[$key] = '***REDACTED***';

                continue;
            }

            if (is_array($value)) {
                $out[$key] = $this->walkRedact($value, $patterns);

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
