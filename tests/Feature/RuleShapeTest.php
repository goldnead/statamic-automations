<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Sequence\LinearityRule;
use Goldnead\StatamicAutomations\Sequence\RuleShape;

/**
 * When is an automation a rule?
 *
 * A rule is the two-node case: a trigger, then one mail, nothing in between.
 * That shape can be shown and edited as a single sentence — "when X happens,
 * send Y" — which is the whole point of the rule view. Anything else is still
 * shown as a row, but edited on the canvas.
 *
 * The rule builds on {@see LinearityRule}
 * rather than repeating its graph checks: every reason a list cannot be edited
 * is also a reason a rule cannot be, and re-deriving them here would mean two
 * places to keep in step.
 */
function makeRuleGraph(array $nodes, array $edges): Automation
{
    $automation = Automation::create([
        'name' => 'Rule',
        'handle' => 'rule-'.bin2hex(random_bytes(4)),
        'enabled' => true,
    ]);

    foreach ($nodes as [$key, $type, $config]) {
        $automation->nodes()->create([
            'node_key' => $key, 'type' => $type, 'position_x' => 0, 'position_y' => 0, 'config' => $config,
        ]);
    }

    foreach ($edges as [$from, $to]) {
        $automation->edges()->create(['from_node_key' => $from, 'to_node_key' => $to, 'from_output' => 'default']);
    }

    return $automation->fresh(['nodes', 'edges']);
}

function evaluateShape(Automation $automation): array
{
    return app(RuleShape::class)->evaluate($automation);
}

$mail = ['subject' => 'Hello', 'to' => 'a@b.c', 'body' => 'x'];

it('accepts a trigger and one mail', function () use ($mail) {
    $automation = makeRuleGraph(
        [['t', 'manual', []], ['m', 'send_email', $mail]],
        [['t', 'm']],
    );

    $shape = evaluateShape($automation);

    expect($shape['editable'])->toBeTrue()
        ->and($shape['reasons'])->toBe([])
        ->and($shape['trigger_node_key'])->toBe('t')
        ->and($shape['mail_node_key'])->toBe('m');
});

it('refuses a second mail and says so', function () use ($mail) {
    $automation = makeRuleGraph(
        [['t', 'manual', []], ['m1', 'send_email', $mail], ['m2', 'send_email', $mail]],
        [['t', 'm1'], ['m1', 'm2']],
    );

    $shape = evaluateShape($automation);

    expect($shape['editable'])->toBeFalse()
        ->and(implode(' ', $shape['reasons']))->toContain('2 mail');
});

it('refuses a node between the trigger and the mail', function () use ($mail) {
    $automation = makeRuleGraph(
        [['t', 'manual', []], ['d', 'delay', ['amount' => 2, 'unit' => 'days']], ['m', 'send_email', $mail]],
        [['t', 'd'], ['d', 'm']],
    );

    $shape = evaluateShape($automation);

    expect($shape['editable'])->toBeFalse()
        ->and(implode(' ', $shape['reasons']))->toContain('delay');
});

it('refuses an automation with no mail at all', function () {
    $automation = makeRuleGraph(
        [['t', 'manual', []], ['l', 'add_log_entry', ['message' => 'x']]],
        [['t', 'l']],
    );

    $shape = evaluateShape($automation);

    expect($shape['editable'])->toBeFalse()
        ->and(implode(' ', $shape['reasons']))->toContain('no mail');
});

it('refuses a trigger on its own', function () {
    $automation = makeRuleGraph([['t', 'manual', []]], []);

    $shape = evaluateShape($automation);

    expect($shape['editable'])->toBeFalse();
});

it('carries the linearity reasons through rather than inventing its own', function () use ($mail) {
    // Two triggers is a linearity failure. The rule view must say what the
    // list view would say, not a second wording of the same fact.
    $automation = makeRuleGraph(
        [['t1', 'manual', []], ['t2', 'manual', []], ['m', 'send_email', $mail]],
        [['t1', 'm']],
    );

    $shape = evaluateShape($automation);

    expect($shape['editable'])->toBeFalse()
        ->and(implode(' ', $shape['reasons']))->toContain('trigger nodes');
});

it('still names the trigger and the mail when the shape is refused', function () use ($mail) {
    // The row is shown either way, so it needs both keys even when locked.
    $automation = makeRuleGraph(
        [['t', 'manual', []], ['d', 'delay', ['amount' => 1, 'unit' => 'days']], ['m', 'send_email', $mail]],
        [['t', 'd'], ['d', 'm']],
    );

    $shape = evaluateShape($automation);

    expect($shape['trigger_node_key'])->toBe('t')
        ->and($shape['mail_node_key'])->toBe('m');
});
