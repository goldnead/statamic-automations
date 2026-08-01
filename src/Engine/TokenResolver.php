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
            default => $value,
        };
    }

    protected function formatDate(mixed $value, string $format): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Carbon::parse($value)->format($format);
        } catch (\Throwable) {
            return $value;
        }
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
