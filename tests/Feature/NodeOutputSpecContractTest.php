<?php

/**
 * The output specs the registry ships, pinned as a file both sides read.
 *
 * A node's handles are declared once, in PHP, and resolved in two places: by
 * `NodeOutputs` on the server (what the validator holds an automation to) and
 * by `resources/js/composables/useNodeOutputs.js` in the browser (what the
 * user can wire). "Declared once" is only true if those two resolvers agree,
 * and nothing in either suite can see the other.
 *
 * So the specs are written to `tests/js/fixtures/node-output-specs.json` from
 * here and read from there by `tests/js/node-outputs.test.mjs`. This test
 * fails when the committed fixture stops matching what the registry produces;
 * the JS test fails when the JS resolver stops agreeing with the fixture.
 * Between them, a change to a built-in node's outputs that is not carried
 * into the canvas cannot pass.
 *
 * Regenerate with: UPDATE_NODE_OUTPUT_FIXTURE=1 vendor/bin/pest --filter=NodeOutputSpecContract
 */

use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\NodeOutputs;

const FIXTURE_HANDLES = ['manual', 'send_email', 'branch', 'switch', 'loop', 'parallel', 'stop'];

function fixturePath(): string
{
    return __DIR__ . '/../js/fixtures/node-output-specs.json';
}

it('ships a spec for every registered node, and the canvas fixture matches it', function (): void {
    $registry = app(NodeRegistry::class);

    $specs = [];
    foreach (FIXTURE_HANDLES as $handle) {
        expect($registry->has($handle))->toBeTrue("'{$handle}' is not registered");
        $specs[$handle] = $registry->outputSpec($handle);
    }

    $json = json_encode($specs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    if (getenv('UPDATE_NODE_OUTPUT_FIXTURE')) {
        @mkdir(dirname(fixturePath()), 0755, true);
        file_put_contents(fixturePath(), $json);
    }

    expect(file_exists(fixturePath()))->toBeTrue();
    expect(json_decode(file_get_contents(fixturePath()), true))->toEqual($specs);
});

it('gives every node in the library an output spec of a version the canvas can read', function (): void {
    $registry = app(NodeRegistry::class);

    foreach ($registry->all() as $description) {
        expect($description['outputs'] ?? null)->toBeArray("'{$description['handle']}' has no outputs in describe()");
        expect($description['outputs']['version'])->toBe(NodeOutputs::VERSION);
        expect($description['outputs']['clauses'])->toBeArray();
    }
});

it('resolves the built-in logic nodes to the handles their edges are stored on', function (): void {
    $registry = app(NodeRegistry::class);

    expect($registry->outputsFor('branch'))->toBe(['true', 'false']);
    expect($registry->outputsFor('loop'))->toBe(['loop', 'done']);
    expect($registry->outputsFor('stop'))->toBe([]);
    expect($registry->outputsFor('send_email'))->toBe(['default']);

    // Config-dependent, which is the reason the payload is a spec rather than
    // a list: the same node type answers differently per node.
    expect($registry->outputsFor('switch', ['cases' => ['de' => 'german', 'en' => 'english']]))
        ->toBe(['german', 'english', 'default']);
    expect($registry->outputsFor('switch', ['cases' => []]))->toBe(['default']);
    expect($registry->outputsFor('parallel', ['branches' => ['a' => 'Alpha', 'b' => 'Beta']]))
        ->toBe(['a', 'b']);
    expect($registry->outputsFor('parallel', ['mode' => 'automation', 'branches' => ['a' => 'x']]))
        ->toBe(['default']);
});

it('keeps the handles the pre-1.7.0 outputs() methods returned, in the same order', function (): void {
    // Stored edges name these strings. Rename or reorder one and every
    // automation wired to it breaks, silently — the edge stays in the
    // database and stops being followed.
    expect(\Goldnead\StatamicAutomations\Nodes\Logic\LoopNode::outputs())->toBe(['loop', 'done']);

    expect(\Goldnead\StatamicAutomations\Nodes\Logic\SwitchNode::outputs([
        'cases' => ['a' => 'case_a', 'b' => '', 'c' => 'default'],
    ]))->toBe(['case_a', 'default']);

    expect(\Goldnead\StatamicAutomations\Nodes\Logic\ParallelNode::outputs([
        'branches' => ['first' => 'First', 'second' => 'Second'],
    ]))->toBe(['first', 'second']);

    expect(\Goldnead\StatamicAutomations\Nodes\Logic\ParallelNode::outputs([
        'mode' => 'automation', 'branches' => ['first' => 'auto.one'],
    ]))->toBe(['default']);
});

it('falls back rather than guessing when it meets a spec from a newer contract', function (): void {
    // The mirror of the canvas's rule, for the other direction of the same
    // mismatch: an addon shipping a spec written against a later grammar.
    // Fields this version does not know could mean anything, so the node is
    // treated as one that declares nothing — which is what it would have been
    // before specs existed, and keeps every stored edge readable.
    $future = ['version' => NodeOutputs::VERSION + 1, 'clauses' => [['outputs' => ['a', 'b', 'c']]]];

    expect(NodeOutputs::handles($future))->toBe(['default']);
    expect(NodeOutputs::handles(['version' => NodeOutputs::VERSION, 'clauses' => [['outputs' => ['a', 'b']]]]))
        ->toBe(['a', 'b']);
});

it('names the loop\'s continuation and leaves a branch without one', function (): void {
    $registry = app(NodeRegistry::class);

    expect(NodeOutputs::continuation($registry->outputSpec('loop')))->toBe('done');
    expect(NodeOutputs::continuation($registry->outputSpec('branch')))->toBe('true');
    expect(NodeOutputs::continuation($registry->outputSpec('stop')))->toBeNull();
    expect(NodeOutputs::continuation($registry->outputSpec('send_email')))->toBe('default');
});
