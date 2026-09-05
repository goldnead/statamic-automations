<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Sequence\MailListProjection;

/**
 * The list of mails an automation sends.
 *
 * The property that decides the shape of this whole feature is the first one
 * below: **a branched automation still has a list.** The list is a list of the
 * e-mails, not a picture of the flow, so it is shown for everything and only
 * its editing is bound to the flow being a straight line.
 */
beforeEach(function (): void {
    $this->projection = app(MailListProjection::class);

    $this->build = function (array $nodes, array $edges): Automation {
        $automation = Automation::create(['name' => 'A', 'handle' => 'a_'.bin2hex(random_bytes(4))]);

        foreach ($nodes as $key => $node) {
            $automation->nodes()->create([
                'node_key' => $key,
                'type' => $node['type'],
                'label' => $node['label'] ?? null,
                'position_x' => 0,
                'position_y' => 0,
                'config' => $node['config'] ?? [],
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

    $this->welcomeSeries = fn (): Automation => ($this->build)(
        [
            't' => ['type' => 'manual'],
            'm1' => ['type' => 'send_email', 'config' => ['subject' => 'Welcome', 'to' => 'x@example.com', 'body' => 'hi']],
            'd1' => ['type' => 'delay', 'config' => ['amount' => 2, 'unit' => 'days']],
            'm2' => ['type' => 'send_email', 'config' => ['subject' => 'Day two', 'to' => 'x@example.com', 'body' => 'hi']],
            'd2' => ['type' => 'delay', 'config' => ['amount' => 5, 'unit' => 'days']],
            'm3' => ['type' => 'send_email', 'config' => ['subject' => 'Day seven', 'to' => 'x@example.com', 'body' => 'hi']],
        ],
        [['t', 'm1'], ['m1', 'd1'], ['d1', 'm2'], ['m2', 'd2'], ['d2', 'm3']],
    );
});

it('lists the mails of a linear sequence in order', function (): void {
    $list = $this->projection->forAutomation(($this->welcomeSeries)());

    expect(array_column($list['mails'], 'node_key'))->toBe(['m1', 'm2', 'm3'])
        ->and(array_column($list['mails'], 'label'))->toBe(['Welcome', 'Day two', 'Day seven'])
        ->and($list['editable'])->toBeTrue();
});

it('measures every gap from the mail before it, never from the start', function (): void {
    // The property the whole editing story rests on. "Day 7" would be an
    // absolute number that a reorder silently invalidates for every row below
    // the one that moved; "5 days after the previous mail" survives being
    // moved, because the delay travels with the mail it precedes.
    $list = $this->projection->forAutomation(($this->welcomeSeries)());

    expect(array_map(fn ($mail) => $mail['delay']['seconds'], $list['mails']))
        ->toBe([0, 2 * 86400, 5 * 86400]);
});

it('shows a branched automation, and marks the conditional mails as conditional', function (): void {
    // Incomplete as a structural picture, correct as a list of mails. Refusing
    // to show anything here would hide the readable part of every flow that
    // ever grew an "if they opened it" branch.
    $automation = ($this->build)(
        [
            't' => ['type' => 'manual'],
            'm1' => ['type' => 'send_email', 'config' => ['subject' => 'Welcome']],
            'b' => ['type' => 'branch', 'label' => 'Opened?'],
            'yes' => ['type' => 'send_email', 'config' => ['subject' => 'Thanks for reading']],
            'no' => ['type' => 'send_email', 'config' => ['subject' => 'Did you see this?']],
        ],
        [['t', 'm1'], ['m1', 'b'], ['b', 'yes', 'true'], ['b', 'no', 'false']],
    );

    $list = $this->projection->forAutomation($automation);

    expect(array_column($list['mails'], 'node_key'))->toBe(['m1', 'yes', 'no'])
        ->and(array_column($list['mails'], 'conditional'))->toBe([false, true, true])
        ->and($list['mails'][1]['condition'])->toContain('Opened?')
        // …and the list is read-only, which is the ONLY thing the branch costs.
        ->and($list['editable'])->toBeFalse()
        ->and($list['reasons'])->not->toBe([]);
});

it('marks the mails after a filter as conditional without locking the list', function (): void {
    $automation = ($this->build)(
        [
            't' => ['type' => 'manual'],
            'f' => ['type' => 'filter', 'label' => 'Still subscribed'],
            'm' => ['type' => 'send_email', 'config' => ['subject' => 'Only for some']],
        ],
        [['t', 'f'], ['f', 'm']],
    );

    $list = $this->projection->forAutomation($automation);

    expect($list['mails'][0]['conditional'])->toBeTrue()
        ->and($list['mails'][0]['condition'])->toContain('Still subscribed')
        // A filter narrows who continues; it does not make the order ambiguous.
        ->and($list['editable'])->toBeTrue();
});

it('names a wait-until as a gap without inventing a number of seconds', function (): void {
    // "Until next Tuesday at nine" is a real gap that no number of seconds
    // describes. Printing a made-up one would be worse than printing the rule.
    $automation = ($this->build)(
        [
            't' => ['type' => 'manual'],
            'w' => ['type' => 'wait_until', 'config' => ['mode' => 'condition']],
            'm' => ['type' => 'send_email', 'config' => ['subject' => 'Later']],
        ],
        [['t', 'w'], ['w', 'm']],
    );

    $list = $this->projection->forAutomation($automation);

    expect($list['mails'][0]['delay']['seconds'])->toBe(0)
        ->and($list['mails'][0]['delay']['sources'])->toBe(['w']);
});

it('says on the row when a step carries more than the mail', function (): void {
    // A reorder moves the whole step. Moving somebody's "tag them, then mail
    // them" without saying so would be a silent rewrite of what the flow does.
    $automation = ($this->build)(
        [
            't' => ['type' => 'manual'],
            'log' => ['type' => 'add_log_entry', 'config' => ['message' => 'about to mail']],
            'm' => ['type' => 'send_email', 'config' => ['subject' => 'Hello']],
        ],
        [['t', 'log'], ['log', 'm']],
    );

    $list = $this->projection->forAutomation($automation);

    expect($list['mails'][0]['also_runs'])->toBe([['node_key' => 'log', 'type' => 'add_log_entry']]);
});

it('counts nothing as a mail unless the node says it is one', function (): void {
    // The domain boundary. This addon never learns what a newsletter is; a
    // node opts in with a static mailStep(). An install can also name handles
    // in config, which is what this half proves.
    $automation = ($this->build)(
        [
            't' => ['type' => 'manual'],
            'w' => ['type' => 'simple_webhook', 'config' => ['url' => 'https://example.com']],
        ],
        [['t', 'w']],
    );

    expect($this->projection->forAutomation($automation)['mails'])->toBe([]);

    config()->set('automations.sequence.mail_nodes', ['simple_webhook']);

    expect($this->projection->forAutomation($automation->fresh(['nodes', 'edges']))['mails'])
        ->toHaveCount(1);
});

it('produces an empty list rather than looping for ever on a cyclic graph', function (): void {
    $automation = ($this->build)(
        [
            't' => ['type' => 'manual'],
            'm1' => ['type' => 'send_email', 'config' => ['subject' => 'One']],
            'm2' => ['type' => 'send_email', 'config' => ['subject' => 'Two']],
        ],
        [['t', 'm1'], ['m1', 'm2'], ['m2', 'm1']],
    );

    $list = $this->projection->forAutomation($automation);

    expect(count($list['mails']))->toBeLessThanOrEqual(2)
        ->and($list['editable'])->toBeFalse();
});

/**
 * A mail's stored name is a subject template, so it may carry Antlers
 * placeholders. The row shows the stored line; every sentence that quotes the
 * mail by name shows the short form, because there is no contact in the Control
 * Panel to resolve `{{ contact.first_name }}` against.
 *
 * Four shapes, and the middle two are the ones a fixed test string never sees:
 * a name with no placeholder at all, one that is nothing but a placeholder, one
 * with several, and an empty one.
 */
it('carries a placeholder-free display name next to the stored one', function (): void {
    $automation = ($this->build)(
        [
            't' => ['type' => 'manual'],
            'plain' => ['type' => 'send_email', 'config' => ['subject' => 'Zahlung bestätigt']],
            'trailing' => ['type' => 'send_email', 'config' => ['subject' => 'Zahlung bestätigt, {{ contact.first_name }}']],
            'several' => ['type' => 'send_email', 'config' => ['subject' => 'Hallo {{ contact.first_name }} {{ contact.last_name }}, willkommen']],
            'only' => ['type' => 'send_email', 'config' => ['subject' => '{{ contact.first_name }}']],
            'empty' => ['type' => 'send_email', 'config' => ['subject' => '']],
        ],
        [['t', 'plain'], ['plain', 'trailing'], ['trailing', 'several'], ['several', 'only'], ['only', 'empty']],
    );

    $mails = collect($this->projection->forAutomation($automation)['mails'])->keyBy('node_key');

    // No placeholder: the display name is the stored name, punctuation and all.
    expect($mails['plain']['display_label'])->toBe('Zahlung bestätigt')
        ->and($mails['plain']['label'])->toBe('Zahlung bestätigt');

    // The reported case. The comma that held the placeholder onto the sentence
    // goes with it — "Zahlung bestätigt," would read as a truncation.
    expect($mails['trailing']['display_label'])->toBe('Zahlung bestätigt')
        // The stored line is untouched: the column shows what the mail carries.
        ->and($mails['trailing']['label'])->toBe('Zahlung bestätigt, {{ contact.first_name }}');

    // Several: cut at the FIRST one, not at the last. Everything after the
    // first placeholder is written around a value nobody can see here.
    expect($mails['several']['display_label'])->toBe('Hallo');

    // Nothing but a placeholder leaves nothing to show, so the node key stands
    // in — a name a reader can at least match against the row they clicked.
    expect($mails['only']['display_label'])->toBe('only');

    // Empty was already handled before this change and stays handled.
    expect($mails['empty']['display_label'])->toBe('empty')
        ->and($mails['empty']['label'])->toBe('empty');

    // Not one of them may reach a sentence with braces still in it.
    foreach ($mails as $mail) {
        expect($mail['display_label'])->not->toContain('{{');
    }
});
