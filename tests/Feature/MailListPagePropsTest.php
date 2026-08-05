<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Sequence\MailSteps;

/**
 * What the builder page hands the mail list view.
 *
 * The list itself, the funnel, and — the one that cannot be derived on the
 * client — which node types may be added AS a mail. An automation that sends
 * nothing yet is exactly the one that needs to add its first mail, and it is
 * the only screen that cannot read the answer off its own rows.
 */
beforeEach(function (): void {
    $this->actingAsSuperUser();

    $this->props = function (Automation $automation): array {
        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('statamic-automations.automations.edit', $automation));

        $response->assertOk();

        return json_decode($response->getContent(), true)['props'] ?? [];
    };
});

it('hands the page the mail list, the funnel and the mail types', function (): void {
    $automation = Automation::create(['name' => 'Welcome', 'handle' => 'welcome']);

    foreach ([['t', 'manual', []], ['m1', 'send_email', ['subject' => 'One', 'to' => 'a@b.c', 'body' => 'x']]] as [$key, $type, $config]) {
        $automation->nodes()->create([
            'node_key' => $key, 'type' => $type, 'position_x' => 0, 'position_y' => 0, 'config' => $config,
        ]);
    }

    $automation->edges()->create(['from_node_key' => 't', 'to_node_key' => 'm1', 'from_output' => 'default']);

    $props = ($this->props)($automation->fresh(['nodes', 'edges']));

    expect($props['mailList']['editable'])->toBeTrue()
        ->and(array_column($props['mailList']['mails'], 'label'))->toBe(['One'])
        ->and($props['mailListUrl'])->toContain('/mail-list')
        ->and($props['stats'])->toHaveKeys(['enrolled', 'in_progress', 'completed', 'exited', 'failed'])
        ->and(array_column($props['mailTypes'], 'handle'))->toContain('send_email');
});

it('offers the mail types to an automation that sends nothing yet', function (): void {
    // The whole reason the types come from the registry and not from the rows.
    $automation = Automation::create(['name' => 'Empty', 'handle' => 'empty']);

    $props = ($this->props)($automation);

    expect($props['mailList']['mails'])->toBe([])
        ->and(array_column($props['mailTypes'], 'handle'))->toContain('send_email');
});

it('names no node type that does not declare itself a mail', function (): void {
    $mails = app(MailSteps::class);

    expect($mails->isMailHandle('send_email'))->toBeTrue()
        ->and($mails->isMailHandle('delay'))->toBeFalse()
        ->and($mails->isMailHandle('branch'))->toBeFalse()
        // A handle nobody registered is not a mail, and is not a fatal either.
        ->and($mails->isMailHandle('nothing_of_the_sort'))->toBeFalse();
});
