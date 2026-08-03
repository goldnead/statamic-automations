<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Sequence\LinearityRule;

/**
 * The rule that decides whether a mail list may be EDITED.
 *
 * It is written out in prose on the class it belongs to; these cases are that
 * prose, one clause at a time, so a later change to the rule has to change a
 * test with a name that says what was given up.
 *
 * Nothing here is about displaying. Displaying is always allowed — that
 * property is held by MailListProjectionTest, which shows a branched flow
 * producing a correct list of mails while this file shows the same flow
 * refusing to be edited.
 */
beforeEach(function (): void {
    $this->rule = app(LinearityRule::class);

    $this->chain = function (array $nodes, array $edges): Automation {
        $automation = Automation::create(['name' => 'A', 'handle' => 'a_'.bin2hex(random_bytes(4))]);

        foreach ($nodes as $key => $type) {
            $automation->nodes()->create([
                'node_key' => $key,
                'type' => $type,
                'position_x' => 0,
                'position_y' => 0,
            ]);
        }

        foreach ($edges as $edge) {
            $automation->edges()->create([
                'from_node_key' => $edge[0],
                'to_node_key' => $edge[1],
                'from_output' => $edge[2] ?? 'default',
            ]);
        }

        return $automation->fresh(['nodes', 'edges']);
    };
});

it('calls a straight trigger → mail → delay → mail chain linear', function (): void {
    $automation = ($this->chain)(
        ['t' => 'manual', 'm1' => 'send_email', 'd' => 'delay', 'm2' => 'send_email'],
        [['t', 'm1'], ['m1', 'd'], ['d', 'm2']],
    );

    $verdict = $this->rule->evaluate($automation);

    expect($verdict['linear'])->toBeTrue()
        ->and($verdict['reasons'])->toBe([])
        ->and($verdict['trigger'])->toBe('t');
});

it('refuses an automation with no trigger', function (): void {
    $automation = ($this->chain)(['m1' => 'send_email'], []);

    expect($this->rule->evaluate($automation)['linear'])->toBeFalse();
});

it('refuses an automation with two triggers', function (): void {
    // Two entry points mean two orders in which the same mails can arrive, and
    // a list has one.
    $automation = ($this->chain)(
        ['t1' => 'manual', 't2' => 'entry_saved', 'm' => 'send_email'],
        [['t1', 'm'], ['t2', 'm']],
    );

    $verdict = $this->rule->evaluate($automation);

    expect($verdict['linear'])->toBeFalse()
        ->and(implode(' ', $verdict['reasons']))->toContain('trigger nodes');
});

it('refuses a node with two outgoing edges', function (): void {
    $automation = ($this->chain)(
        ['t' => 'manual', 'm1' => 'send_email', 'm2' => 'send_email', 'm3' => 'send_email'],
        [['t', 'm1'], ['m1', 'm2'], ['m1', 'm3']],
    );

    $verdict = $this->rule->evaluate($automation);

    expect($verdict['linear'])->toBeFalse()
        ->and(implode(' ', $verdict['reasons']))->toContain('outgoing edges');
});

it('refuses a node with two incoming edges', function (): void {
    // Two paths lead here, so "the mail before this one" has two answers — and
    // the gap on the row would be the gap after whichever one the walk happened
    // to take.
    $automation = ($this->chain)(
        ['t' => 'manual', 'a' => 'add_log_entry', 'b' => 'add_log_entry', 'm' => 'send_email'],
        [['t', 'a'], ['a', 'm'], ['b', 'm']],
    );

    $verdict = $this->rule->evaluate($automation);

    expect($verdict['linear'])->toBeFalse()
        ->and(implode(' ', $verdict['reasons']))->toContain('incoming edges');
});

it('refuses an edge that leaves through anything but the default output', function (): void {
    $automation = ($this->chain)(
        ['t' => 'manual', 'b' => 'branch', 'm' => 'send_email'],
        [['t', 'b'], ['b', 'm', 'true']],
    );

    $verdict = $this->rule->evaluate($automation);

    expect($verdict['linear'])->toBeFalse()
        ->and(implode(' ', $verdict['reasons']))->toContain("'true' output");
});

it('refuses a branching node even when only one of its outputs is wired', function (): void {
    // The case rule 4 alone would let through: one edge, on `default`, from a
    // node whose whole purpose is to fork. Its second output can be connected
    // from the canvas at any moment, and a list that had rewritten the graph in
    // the meantime would have moved a node the branch was about to point at.
    $automation = ($this->chain)(
        ['t' => 'manual', 'b' => 'branch', 'm' => 'send_email'],
        [['t', 'b'], ['b', 'm']],
    );

    $verdict = $this->rule->evaluate($automation);

    expect($verdict['linear'])->toBeFalse()
        ->and(implode(' ', $verdict['reasons']))->toContain('forks the flow by nature');
})->with([
    ['branch'],
    ['switch'],
    ['loop'],
    ['parallel'],
]);

it('allows a filter, which narrows without forking', function (): void {
    // Deliberately not in the branching list. A filter has one output and ends
    // the flow for the people it does not match, which changes WHO continues
    // and never the ORDER of what they get.
    $automation = ($this->chain)(
        ['t' => 'manual', 'f' => 'filter', 'm' => 'send_email'],
        [['t', 'f'], ['f', 'm']],
    );

    expect($this->rule->evaluate($automation)['linear'])->toBeTrue();
});

it('refuses an automation with a node the trigger cannot reach', function (): void {
    $automation = ($this->chain)(
        ['t' => 'manual', 'm1' => 'send_email', 'orphan' => 'send_email'],
        [['t', 'm1']],
    );

    $verdict = $this->rule->evaluate($automation);

    expect($verdict['linear'])->toBeFalse()
        ->and(implode(' ', $verdict['reasons']))->toContain('cannot be reached');
});

it('refuses a chain that loops back on itself', function (): void {
    $automation = ($this->chain)(
        ['t' => 'manual', 'm1' => 'send_email', 'm2' => 'send_email'],
        [['t', 'm1'], ['m1', 'm2'], ['m2', 'm1']],
    );

    $verdict = $this->rule->evaluate($automation);

    expect($verdict['linear'])->toBeFalse();
});

it('names every reason, not just the first', function (): void {
    // The reasons are shown to an editor deciding whether to unbranch the flow
    // or to go to the canvas. One reason at a time turns that into a guessing
    // game with a round trip per guess.
    $automation = ($this->chain)(
        ['t1' => 'manual', 't2' => 'manual', 'b' => 'branch', 'm' => 'send_email'],
        [['t1', 'b'], ['t2', 'b'], ['b', 'm', 'true']],
    );

    expect(count($this->rule->evaluate($automation)['reasons']))->toBeGreaterThan(1);
});
