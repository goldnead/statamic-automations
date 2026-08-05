<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Sequence\RuleProjection;
use Goldnead\StatamicAutomations\Support\DispatchMode;

/**
 * The rule, as one row.
 *
 * A row is produced for every automation, editable or not: somebody looking for
 * "which mail goes out when a form is submitted" needs an answer whether or not
 * the flow behind it grew a delay. The shape only decides whether the row can
 * be edited here.
 */
function makeProjectableRule(array $triggerConfig = [], array $extra = []): Automation
{
    $automation = Automation::create([
        'name' => 'Contact reply',
        'handle' => 'contact-reply-'.bin2hex(random_bytes(4)),
        'enabled' => true,
    ]);

    $nodes = [
        ['t', 'manual', $triggerConfig],
        ['m', 'send_email', ['subject' => 'Thanks', 'to' => 'hallo@example.com', 'body' => 'x']],
        ...$extra,
    ];

    foreach ($nodes as [$key, $type, $config]) {
        $automation->nodes()->create([
            'node_key' => $key, 'type' => $type, 'position_x' => 0, 'position_y' => 0, 'config' => $config,
        ]);
    }

    $automation->edges()->create(['from_node_key' => 't', 'to_node_key' => 'm', 'from_output' => 'default']);

    return $automation->fresh(['nodes', 'edges']);
}

function project(Automation $automation): array
{
    return app(RuleProjection::class)->forAutomation($automation);
}

it('reads the sentence off the two nodes', function () {
    $row = project(makeProjectableRule());

    expect($row['trigger']['handle'])->toBe('manual')
        ->and($row['trigger']['label'])->not->toBeNull()
        ->and($row['mail']['label'])->not->toBeNull()
        ->and($row['recipient'])->toBe('hallo@example.com')
        ->and($row['enabled'])->toBeTrue()
        ->and($row['editable'])->toBeTrue();
});

it('reports async when the trigger says nothing', function () {
    expect(project(makeProjectableRule())['dispatch_mode'])->toBe('async');
});

it('reports the dispatch mode the trigger carries', function () {
    $row = project(makeProjectableRule([DispatchMode::CONFIG_KEY => 'sync']));

    expect($row['dispatch_mode'])->toBe('sync');
});

it('still produces a row for a shape it cannot edit', function () {
    $automation = makeProjectableRule(extra: [['d', 'delay', ['amount' => 1, 'unit' => 'days']]]);

    $row = project($automation);

    expect($row['editable'])->toBeFalse()
        ->and($row['reasons'])->not->toBe([])
        ->and($row['trigger']['handle'])->toBe('manual')
        ->and($row['recipient'])->toBe('hallo@example.com');
});

it('carries the most recent runs, newest first', function () {
    $automation = makeProjectableRule();

    foreach ([AutomationRun::STATUS_SUCCESS, AutomationRun::STATUS_FAILED] as $status) {
        AutomationRun::create([
            'automation_id' => $automation->id,
            'automation_uuid' => $automation->uuid,
            'status' => $status,
        ]);
    }

    $runs = project($automation)['recent_runs'];

    expect($runs)->toHaveCount(2)
        ->and($runs[0]['status'])->toBe(AutomationRun::STATUS_FAILED);
});
