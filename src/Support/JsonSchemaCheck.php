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
     * object-decoded reply, so compare their JSON forms, with the keys of every
     * object in sorted order: two objects are equal whatever order their keys
     * came in, while the order of a list still counts.
     *
     * @param  array<int, mixed>  $members
     */
    protected static function inEnum(mixed $value, array $members): bool
    {
        $needle = json_encode(self::canonical($value));

        foreach ($members as $member) {
            if (json_encode(self::canonical($member)) === $needle) {
                return true;
            }

            // A schema decoded as an array turns `{}` into `[]`: an empty
            // member stands for an empty object as well.
            if ($member === [] && $value instanceof stdClass && get_object_vars($value) === []) {
                return true;
            }
        }

        return false;
    }

    /** The value with the keys of every object (stdClass or associative array) sorted. */
    protected static function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            ksort($value);

            return (object) array_map(self::canonical(...), $value);
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);

                return (object) array_map(self::canonical(...), $value);
            }

            return array_map(self::canonical(...), $value);
        }

        return $value;
    }
}
