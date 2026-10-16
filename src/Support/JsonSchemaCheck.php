<?php

namespace Goldnead\StatamicAutomations\Support;

use stdClass;

/**
 * A deliberately small JSON-Schema subset for checking model output:
 * `type`, `properties`, `required`, `items` and `enum`. Anything else in a
 * schema is ignored rather than rejected, so a schema pasted from elsewhere
 * still works for the parts this understands.
 *
 * The value must be decoded WITHOUT the assoc flag (`json_decode($raw)`): only
 * then does a JSON object stay a stdClass and a JSON array a PHP list. With
 * assoc decoding `{}` and `[]` are both the PHP array [], and a nested empty
 * container would pass as either type.
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

        if (isset($schema['enum']) && is_array($schema['enum']) && ! self::inEnum($value, $schema['enum'])) {
            $errors[] = "{$path} must be one of: ".json_encode($schema['enum'], JSON_UNESCAPED_UNICODE);
        }

        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);

            foreach ((array) ($schema['required'] ?? []) as $field) {
                if (is_string($field) && ! array_key_exists($field, $fields)) {
                    $errors[] = "{$path}.{$field} is required";
                }
            }

            foreach ((array) ($schema['properties'] ?? []) as $field => $sub) {
                if (array_key_exists($field, $fields) && is_array($sub)) {
                    $errors = array_merge($errors, self::violations($fields[$field], $sub, "{$path}.{$field}"));
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
            'object' => $value instanceof stdClass,
            'array' => is_array($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * Enum members come from the (assoc-decoded) schema, the value from the
     * object-decoded reply, so compare their JSON forms.
     *
     * @param  array<int, mixed>  $members
     */
    protected static function inEnum(mixed $value, array $members): bool
    {
        $needle = json_encode($value);

        foreach ($members as $member) {
            if (json_encode($member) === $needle) {
                return true;
            }
        }

        return false;
    }
}
