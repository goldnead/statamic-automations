<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * A deliberately small JSON-Schema subset for checking model output:
 * `type`, `properties`, `required`, `items` and `enum`. Anything else in a
 * schema is ignored rather than rejected, so a schema pasted from elsewhere
 * still works for the parts this understands.
 */
class JsonSchemaCheck
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> violations, empty when the value conforms
     */
    public static function violations(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];

        if (isset($schema['type']) && is_string($schema['type']) && ! self::isType($value, $schema['type'])) {
            return ["{$path} must be of type {$schema['type']}"];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path} must be one of: ".json_encode($schema['enum'], JSON_UNESCAPED_UNICODE);
        }

        if (is_array($value) && ! array_is_list($value)) {
            foreach ((array) ($schema['required'] ?? []) as $field) {
                if (is_string($field) && ! array_key_exists($field, $value)) {
                    $errors[] = "{$path}.{$field} is required";
                }
            }

            foreach ((array) ($schema['properties'] ?? []) as $field => $sub) {
                if (array_key_exists($field, $value) && is_array($sub)) {
                    $errors = array_merge($errors, self::violations($value[$field], $sub, "{$path}.{$field}"));
                }
            }
        }

        if (is_array($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $i => $item) {
                $errors = array_merge($errors, self::violations($item, $schema['items'], "{$path}[{$i}]"));
            }
        }

        return $errors;
    }

    protected static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && ($value === [] || array_is_list($value)),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }
}
