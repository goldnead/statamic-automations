<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Sequence\MailListProjection;
use Goldnead\StatamicAutomations\Sequence\MailSteps;

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
 * The shortening rule itself, without a graph around it.
 *
 * Every row here is a subject somebody could plausibly write. The ones that
 * matter most are the ones where the placeholder is NOT at the end: a German
 * subject that opens with the first name is ordinary, and the first version of
 * this fix cut at the first `{{` and threw the rest of the line away.
 */
it('removes every placeholder from a name and closes the seam', function (string $stored, string $expected): void {
    expect(MailSteps::withoutPlaceholders($stored))->toBe($expected);
})->with([
    // Nothing to do.
    ['Zahlung bestätigt', 'Zahlung bestätigt'],
    ['Schon fertig?', 'Schon fertig?'],
    ['', ''],

    // The reported case: the comma that held the placeholder onto the sentence
    // goes with it, because "Zahlung bestätigt," reads as a truncation.
    ['Zahlung bestätigt, {{ contact.first_name }}', 'Zahlung bestätigt'],

    // A placeholder in the middle keeps both sides.
    ['Hallo {{ name }}, willkommen', 'Hallo, willkommen'],
    ['Hallo {{ name }} Teil 2', 'Hallo Teil 2'],
    ['Tag {{ x }}: Teil 2', 'Tag: Teil 2'],
    ['Hi {{ a }} und {{ b }} tschuess', 'Hi und tschuess'],

    // A placeholder at the FRONT. Cutting at the first `{{` left nothing here.
    ['{{ contact.first_name }} — dein Platz im Kurs', 'dein Platz im Kurs'],

    // Antlers tags go, the text between them stays: that is what the reader sees
    // when the condition holds, and it needs no parser to be right about.
    ['Newsletter {{if foo}}Ja{{/if}} Ende', 'Newsletter Ja Ende'],

    // Closing marks are the author's and stay; only joiners and openers are
    // trimmed off the seam.
    ['„Zitat“ {{ x }}', '„Zitat“'],
    ['Betreff (für {{ x }})', 'Betreff (für)'],
    ['Ende. {{ x }}', 'Ende.'],

    // A bracket pair that held nothing but the placeholder is litter.
    ['Betreff ({{ campaign }})', 'Betreff'],

    // An unclosed `{{` is the whole defect wearing a typo — it must not reach
    // the screen with its braces showing.
    ['Hallo {{ name', 'Hallo'],

    // Nothing readable left: the caller falls back, this returns empty.
    ['{{ contact.first_name }}', ''],
]);

/**
 * A mail's stored name is a subject template, so it may carry Antlers
 * placeholders. The row shows the stored line; every sentence that quotes the
 * mail by name shows the short form, because there is no contact in the Control
 * Panel to resolve `{{ contact.first_name }}` against.
 */
it('carries a placeholder-free display name next to the stored one', function (): void {
    $automation = ($this->build)(
        [
            't' => ['type' => 'manual'],
            'plain' => ['type' => 'send_email', 'config' => ['subject' => 'Zahlung bestätigt']],
            'trailing' => ['type' => 'send_email', 'config' => ['subject' => 'Zahlung bestätigt, {{ contact.first_name }}']],
            'several' => ['type' => 'send_email', 'config' => ['subject' => 'Hallo {{ contact.first_name }} {{ contact.last_name }}, willkommen']],
            'only' => ['type' => 'send_email', 'config' => ['subject' => '{{ contact.first_name }}']],
            'named' => [
                'type' => 'send_email',
                'label' => 'Willkommensmail',
                'config' => ['subject' => '{{ contact.first_name }} {{ contact.last_name }}'],
            ],
            'nameless' => ['type' => 'send_email', 'config' => ['subject' => '{{ contact.first_name }} {{ contact.last_name }}']],
            'subject_wins' => [
                'type' => 'send_email',
                'label' => 'Willkommensmail',
                'config' => ['subject' => '{{ contact.first_name }}, willkommen im Kurs'],
            ],
            'empty' => ['type' => 'send_email', 'config' => ['subject' => '']],
        ],
        [
            ['t', 'plain'], ['plain', 'trailing'], ['trailing', 'several'], ['several', 'only'],
            ['only', 'named'], ['named', 'nameless'], ['nameless', 'subject_wins'], ['subject_wins', 'empty'],
        ],
    );

    $mails = collect($this->projection->forAutomation($automation)['mails'])->keyBy('node_key');

    // No placeholder: the display name is the stored name, punctuation and all.
    expect($mails['plain']['display_label'])->toBe('Zahlung bestätigt')
        ->and($mails['plain']['label'])->toBe('Zahlung bestätigt');

    expect($mails['trailing']['display_label'])->toBe('Zahlung bestätigt')
        // The stored line is untouched: the column shows what the mail carries.
        ->and($mails['trailing']['label'])->toBe('Zahlung bestätigt, {{ contact.first_name }}');

    // Both placeholders go, the words around them stay.
    expect($mails['several']['display_label'])->toBe('Hallo, willkommen');

    // Nothing but a placeholder and no name of its own: the node key stands in,
    // which a reader can at least match against the row they clicked.
    expect($mails['only']['display_label'])->toBe('only');

    // A subject made of nothing but placeholders leaves nothing — and then the
    // node's own name is the next-best answer, before the key. The add form
    // offers that field for exactly this ("Optional. Shown on the canvas and in
    // this list, as long as the step has no subject"), so spending it on a key
    // like `mail_e71qdm` would be throwing a perfectly good name away.
    expect($mails['named']['display_label'])->toBe('Willkommensmail')
        ->and($mails['named']['label'])->toBe('{{ contact.first_name }} {{ contact.last_name }}');

    // Its neighbour has the same subject and no name: that is what proves the
    // name did it, and not the subject.
    expect($mails['nameless']['display_label'])->toBe('nameless');

    // The other way round, and this is the precedence on purpose: as soon as the
    // subject still says something after the placeholders are gone, the subject
    // wins. It is the line the mail actually carries; the name is the stand-in.
    expect($mails['subject_wins']['display_label'])->toBe('willkommen im Kurs');

    // Empty was already handled before this change and stays handled.
    expect($mails['empty']['display_label'])->toBe('empty')
        ->and($mails['empty']['label'])->toBe('empty');

    // Not one of them may reach a sentence with braces still in it.
    foreach ($mails as $mail) {
        expect($mail['display_label'])->not->toContain('{{');
    }
});

/**
 * Two mails that differ only inside the placeholder still differ afterwards.
 *
 * The confirmation dialog names one mail because the reader may have opened the
 * row menu on the wrong row, and the name is the only thing that says so. A
 * shortening that collapses two names into one takes that away — which is what
 * cutting at the first `{{` did to exactly this pair.
 */
it('keeps two names apart that differ only after the placeholder', function (): void {
    $automation = ($this->build)(
        [
            't' => ['type' => 'manual'],
            'a' => ['type' => 'send_email', 'config' => ['subject' => 'Hallo {{ name }} Teil 2']],
            'b' => ['type' => 'send_email', 'config' => ['subject' => 'Hallo {{ name }}, willkommen']],
        ],
        [['t', 'a'], ['a', 'b']],
    );

    $names = array_column($this->projection->forAutomation($automation)['mails'], 'display_label');

    expect($names)->toBe(['Hallo Teil 2', 'Hallo, willkommen'])
        ->and(array_unique($names))->toHaveCount(2);
});
