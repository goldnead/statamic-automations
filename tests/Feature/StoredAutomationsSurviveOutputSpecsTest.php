<?php

/**
 * The risk this release carries, checked against real data.
 *
 * Every automation that already exists has edges stored on output handle
 * *strings*. `from_output` is a plain column; the runner matches it exactly
 * (`WorkflowRunner::nextNode()`), and nothing reconciles it with anything.
 * So if moving a node's outputs into a declaration renamed one, reordered
 * one, or dropped one, the damage would not be an error — it would be an
 * automation that quietly stops after the node whose handle changed.
 *
 * `tests/Fixtures/stored-automations/hub-2026-07-29.json` is not test data.
 * It is the five automations in the running QA hub, exported from its
 * database on the day this was built: a five-step marketing nurture on
 * `default` edges, two delay flows, and the branch graph 1.5.5 was built
 * against, wired on `true`. If a stored `from_output` stopped resolving,
 * these are the graphs it would happen to.
 */

use Goldnead\StatamicAutomations\Engine\FlowValidator;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;

/** @return array<int, array<string, mixed>> */
function storedAutomations(): array
{
    return json_decode(
        file_get_contents(__DIR__.'/../Fixtures/stored-automations/hub-2026-07-29.json'),
        true,
    );
}

function restoreStored(array $data): Automation
{
    $automation = Automation::create([
        'name' => $data['name'],
        'handle' => $data['handle'],
        'enabled' => $data['enabled'],
    ]);

    foreach ($data['nodes'] as $node) {
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => $node['node_key'],
            'type' => $node['type'],
            'config' => $node['config'],
        ]);
    }

    foreach ($data['edges'] as $edge) {
        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => $edge['from_node_key'],
            'from_output' => $edge['from_output'],
            'to_node_key' => $edge['to_node_key'],
        ]);
    }

    return $automation->fresh(['nodes', 'edges']);
}

it('still resolves every output handle stored in the hub\'s automations', function (): void {
    $registry = app(NodeRegistry::class);
    $checked = 0;

    foreach (storedAutomations() as $data) {
        $configs = collect($data['nodes'])->keyBy('node_key');

        foreach ($data['edges'] as $edge) {
            $node = $configs[$edge['from_node_key']];

            // A type from an addon this suite does not install (the hub has
            // marketing's triggers) still has to resolve, because the canvas
            // and the validator both ask the same question about it.
            $declared = $registry->outputsFor($node['type'], (array) $node['config']);

            expect(in_array($edge['from_output'], $declared, true))->toBeTrue(
                "{$data['handle']}: {$node['type']} no longer declares '{$edge['from_output']}'",
            );
            $checked++;
        }
    }

    expect($checked)->toBe(18);
});

it('reports nothing about the outputs of any automation the hub already holds', function (): void {
    foreach (storedAutomations() as $data) {
        $issues = app(FlowValidator::class)->validate(restoreStored($data));

        $outputIssues = array_values(array_filter(
            $issues,
            fn (array $issue) => in_array($issue['code'], ['edge_unknown_output', 'branch_invalid_output'], true),
        ));

        expect($outputIssues)->toBe([], "{$data['handle']} gained an output issue");
    }
});

it('keeps the branch graph 1.5.5 was built against valid, edges and all', function (): void {
    // Automation 21 in the hub: manual → send_email → branch --true--> branch
    // --true--> send_email. Both branches are wired on `true` and neither on
    // `false`; that was valid before and has to stay valid.
    $data = collect(storedAutomations())->firstWhere('handle', 'qa-builder-155');

    expect($data)->not->toBeNull();

    $issues = app(FlowValidator::class)->validate(restoreStored($data));

    expect(array_column($issues, 'code'))->toBe([]);
});
