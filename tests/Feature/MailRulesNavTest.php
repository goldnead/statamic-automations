<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Sequence\MailRules;
use Goldnead\StatamicAutomations\Sequence\MailSteps;

/**
 * "Mail rules" is listed only while at least one automation is one.
 *
 * The screen edits automations shaped like a sentence — one trigger, one mail —
 * from the sentence itself. Where none exist it is an empty page whose only
 * link goes to the canvas, and it read to Adrian as a menu entry that does
 * nothing but redirect. It is the emptiness that misleads, not the feature, so
 * the entry goes away with the emptiness and comes back with the first rule.
 */
function rule(string $handle): Automation
{
    $automation = Automation::create(['name' => $handle, 'handle' => $handle, 'enabled' => true]);

    $automation->nodes()->create(['node_key' => 't', 'type' => 'manual', 'position_x' => 0, 'position_y' => 0, 'config' => []]);
    $automation->nodes()->create([
        'node_key' => 'm', 'type' => 'send_email', 'position_x' => 0, 'position_y' => 100,
        'config' => ['to' => 'hallo@example.com', 'subject' => 'Hi', 'body' => 'x'],
    ]);
    $automation->edges()->create(['from_node_key' => 't', 'to_node_key' => 'm', 'from_output' => 'default']);

    return $automation;
}

beforeEach(function (): void {
    $this->actingAsSuperUser();
});

it('leaves Mail rules out while no automation is a rule', function (): void {
    expect(app(MailRules::class)->any())->toBeFalse();
});

it('lists Mail rules once an automation is one trigger and one mail', function (): void {
    rule('contact-reply');

    expect(app(MailRules::class)->any())->toBeTrue();
});

it('leaves Mail rules out for an automation that sends nothing', function (): void {
    $automation = Automation::create(['name' => 'empty', 'handle' => 'empty', 'enabled' => true]);
    $automation->nodes()->create(['node_key' => 't', 'type' => 'manual', 'position_x' => 0, 'position_y' => 0, 'config' => []]);

    expect(app(MailRules::class)->any())->toBeFalse();
});

it('leaves Mail rules out for a sequence of several mails', function (): void {
    // Not a rule but a sequence: its screen is the mail list on its own page,
    // and listing it here would send its editor to the wrong surface.
    $automation = Automation::create(['name' => 'seq', 'handle' => 'seq', 'enabled' => true]);
    $automation->nodes()->create(['node_key' => 't', 'type' => 'manual', 'position_x' => 0, 'position_y' => 0, 'config' => []]);

    foreach (['m1', 'm2'] as $i => $key) {
        $automation->nodes()->create([
            'node_key' => $key, 'type' => 'send_email', 'position_x' => 0, 'position_y' => 100 * ($i + 1),
            'config' => ['to' => 'hallo@example.com', 'subject' => 'Hi', 'body' => 'x'],
        ]);
    }

    expect(app(MailRules::class)->any())->toBeFalse();
});

it('keeps the page reachable even while the entry is hidden', function (): void {
    // Hidden from the menu, not removed from the addon. A bookmark, a link from
    // elsewhere in the Control Panel and the empty state all still work.
    $this->get(cp_route('statamic-automations.rules.index'))->assertOk();
});

it('names every registered mail handle', function (): void {
    // The navigation asks the database `whereIn('type', $handles)` rather than
    // loading every automation to run the per-node check. That shortcut is only
    // correct while this list agrees with the per-node answer.
    $mails = app(MailSteps::class);

    foreach ($mails->handles() as $handle) {
        expect($mails->isMailHandle($handle))->toBeTrue();
    }

    expect($mails->handles())->toContain('send_email');
});
