<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;

/**
 * An edge handle is never empty; absent means `default`.
 *
 * Every write path normalised with `$edge['from_output'] ?? 'default'`, which
 * substitutes for a missing key and not for a present empty one — and `''` is
 * both what a cleared CP field sends and what `['nullable', 'string']` accepts.
 *
 * The damage is entirely silent. `WorkflowRunner` selects the outgoing edges of
 * a node with `$e->from_output === $output`, so an edge stored on `''` is never
 * followed: the run reports success and simply stops one node early. On the
 * canvas the same row draws no line (Vue Flow cannot resolve `sourceHandle: ''`
 * against a handle called `default`) while the source node still shows an
 * unused "+" adder on the output it is in fact already wired to.
 */

it('stores an empty output handle as default', function () {
    $automation = Automation::create(['name' => 'Flow', 'handle' => 'flow']);

    $edge = AutomationEdge::create([
        'automation_id' => $automation->id,
        'from_node_key' => 'trigger_1',
        'from_output' => '',
        'to_node_key' => 'email_1',
        'to_input' => '',
    ]);

    expect($edge->fresh()->from_output)->toBe('default')
        ->and($edge->fresh()->to_input)->toBe('default');
});

it('stores a missing output handle as default', function () {
    $automation = Automation::create(['name' => 'Flow', 'handle' => 'flow']);

    $edge = AutomationEdge::create([
        'automation_id' => $automation->id,
        'from_node_key' => 'trigger_1',
        'to_node_key' => 'email_1',
    ]);

    expect($edge->fresh()->from_output)->toBe('default')
        ->and($edge->fresh()->to_input)->toBe('default');
});

it('leaves a real output handle alone', function () {
    $automation = Automation::create(['name' => 'Flow', 'handle' => 'flow']);

    $edge = AutomationEdge::create([
        'automation_id' => $automation->id,
        'from_node_key' => 'branch_1',
        'from_output' => 'false',
        'to_node_key' => 'email_1',
        'to_input' => 'default',
    ]);

    expect($edge->fresh()->from_output)->toBe('false');
});

it('keeps an imported edge reachable at run time', function () {
    // The reachable path. A CP save is protected by chance — Laravel's
    // ConvertEmptyStringsToNull turns the cleared field into null before the
    // FormRequest ever sees it, and `?? 'default'` then catches it. An import
    // reads JSON straight off disk, where `""` stays `""`.
    $result = app(\Goldnead\StatamicAutomations\Export\AutomationImporter::class)->import([
        'schema_version' => 1,
        'automation' => ['name' => 'Imported Flow', 'handle' => 'imported-flow'],
        'requires' => [],
        'nodes' => [
            ['node_key' => 't', 'type' => 'manual', 'config' => []],
            ['node_key' => 'log', 'type' => 'add_log_entry', 'config' => ['message' => 'hi']],
        ],
        'edges' => [
            ['from_node_key' => 't', 'from_output' => '', 'to_node_key' => 'log', 'to_input' => ''],
        ],
    ]);

    $edge = $result['automation']->edges()->firstOrFail();

    expect($edge->from_output)->toBe('default')
        ->and($edge->to_input)->toBe('default');
});
