<?php

use Goldnead\StatamicAutomations\Models\Automation;

/**
 * What the rules page hands the view, and — the harder half — what it leaves out.
 *
 * Every automation that is one trigger and one mail appears, editable or not.
 * An automation that sends nothing is not a rule; one that sends three is a
 * sequence and has the mail list as its screen. Listing either here would send
 * its editor to the wrong surface.
 */
beforeEach(function (): void {
    $this->actingAsSuperUser();

    $this->build = function (string $handle, array $nodes, array $edges): Automation {
        $automation = Automation::create(['name' => $handle, 'handle' => $handle, 'enabled' => true]);

        foreach ($nodes as [$key, $type, $config]) {
            $automation->nodes()->create([
                'node_key' => $key, 'type' => $type, 'position_x' => 0, 'position_y' => 0, 'config' => $config,
            ]);
        }

        foreach ($edges as [$from, $to]) {
            $automation->edges()->create(['from_node_key' => $from, 'to_node_key' => $to, 'from_output' => 'default']);
        }

        return $automation->fresh(['nodes', 'edges']);
    };

    $this->props = function (): array {
        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('statamic-automations.rules.index'));

        $response->assertOk();

        return json_decode($response->getContent(), true)['props'] ?? [];
    };
});

$mail = fn (string $subject) => ['subject' => $subject, 'to' => 'hallo@example.com', 'body' => 'x'];

it('hands the page one row per rule, with where to write it and where the canvas is', function () use ($mail): void {
    ($this->build)('contact-reply', [['t', 'manual', []], ['m', 'send_email', $mail('Thanks')]], [['t', 'm']]);

    $rules = ($this->props)()['rules'];

    expect($rules)->toHaveCount(1)
        ->and($rules[0]['trigger']['handle'])->toBe('manual')
        ->and($rules[0]['recipient'])->toBe('hallo@example.com')
        ->and($rules[0]['editable'])->toBeTrue()
        ->and($rules[0]['update_url'])->toContain('/rule')
        ->and($rules[0]['edit_url'])->toContain('/edit')
        ->and($rules[0])->toHaveKey('template_options');
});

it('lists a near-miss with its reason instead of hiding it', function () use ($mail): void {
    // A delay between the trigger and the mail is still one mail. The row is
    // the answer to "which mail goes out when this fires"; only the editing is
    // refused, and the reason says what is in the way.
    ($this->build)(
        'delayed',
        [['t', 'manual', []], ['d', 'delay', ['amount' => 1, 'unit' => 'days']], ['m', 'send_email', $mail('Later')]],
        [['t', 'd'], ['d', 'm']],
    );

    $rules = ($this->props)()['rules'];

    expect($rules)->toHaveCount(1)
        ->and($rules[0]['editable'])->toBeFalse()
        ->and($rules[0]['reasons'])->not->toBe([]);
});

it('leaves out what is not a rule at all', function () use ($mail): void {
    // Nothing to read as a rule: no mail.
    ($this->build)('no-mail', [['t', 'manual', []], ['l', 'add_log_entry', ['message' => 'hi']]], [['t', 'l']]);

    // A sequence. The mail list is its screen.
    ($this->build)(
        'sequence',
        [['t', 'manual', []], ['m1', 'send_email', $mail('One')], ['m2', 'send_email', $mail('Two')]],
        [['t', 'm1'], ['m1', 'm2']],
    );

    expect(($this->props)()['rules'])->toBe([]);
});

it('tells the page whether this user may write', function () use ($mail): void {
    ($this->build)('contact-reply', [['t', 'manual', []], ['m', 'send_email', $mail('Thanks')]], [['t', 'm']]);

    expect(($this->props)()['canEdit'])->toBeTrue();
});
