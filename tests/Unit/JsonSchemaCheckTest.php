<?php

use Goldnead\StatamicAutomations\Support\JsonSchemaCheck;

// An enum member is decoded from the schema as an array, the checked value
// from the model's reply as a stdClass; the comparison must not depend on the
// order of an object's keys.

it('matches an enum member whose object keys come in another order', function () {
    $schema = ['enum' => [['a' => 1, 'b' => 2]]];

    expect(JsonSchemaCheck::violations(json_decode('{"b":2,"a":1}'), $schema))->toBe([]);
});

it('matches an enum member with differently ordered keys in a nested object', function () {
    $schema = ['enum' => [['outer' => ['x' => 1, 'y' => [1, 2]], 'k' => 'v']]];

    expect(JsonSchemaCheck::violations(json_decode('{"k":"v","outer":{"y":[1,2],"x":1}}'), $schema))->toBe([]);
});

it('still rejects an enum object with another value or another list order', function () {
    $schema = ['enum' => [['a' => 1, 'b' => [1, 2]]]];

    expect(JsonSchemaCheck::violations(json_decode('{"a":1,"b":[2,1]}'), $schema))->not->toBe([]);
    expect(JsonSchemaCheck::violations(json_decode('{"a":2,"b":[1,2]}'), $schema))->not->toBe([]);
    expect(JsonSchemaCheck::violations(json_decode('{"a":1}'), $schema))->not->toBe([]);
});

it('matches an empty object against an empty enum member', function () {
    $schema = ['enum' => [[]]];

    expect(JsonSchemaCheck::violations(json_decode('{}'), $schema))->toBe([]);
    expect(JsonSchemaCheck::violations(json_decode('[]'), $schema))->toBe([]);
    expect(JsonSchemaCheck::violations(json_decode('{"a":1}'), $schema))->not->toBe([]);
});

it('keeps matching scalar enum members', function () {
    $schema = ['enum' => ['red', 3, null, true]];

    expect(JsonSchemaCheck::violations('red', $schema))->toBe([]);
    expect(JsonSchemaCheck::violations(3, $schema))->toBe([]);
    expect(JsonSchemaCheck::violations(null, $schema))->toBe([]);
    expect(JsonSchemaCheck::violations('blue', $schema))->not->toBe([]);
});
