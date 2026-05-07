<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Context\AutomationContext;

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
        // Single-token shortcut → preserve structured values.
        if (preg_match('/^\s*\{\{\s*([\w\.\-]+)\s*\}\}\s*$/', $value, $match)) {
            $resolved = $context->get($match[1]);

            return $resolved;
        }

        return preg_replace_callback(
            '/\{\{\s*([\w\.\-]+)\s*\}\}/',
            function ($match) use ($context) {
                $resolved = $context->get($match[1]);

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
