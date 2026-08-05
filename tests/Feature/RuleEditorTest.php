<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Sequence\RuleEditor;
use Goldnead\StatamicAutomations\Support\DispatchMode;
use Goldnead\StatamicAutomations\Support\RestartPolicy;

// Stand-in for the OPTIONAL email-templates addon (not vendored in this repo).
// Without it `send_email` declares no template field at all — and a rule row
// then rightly refuses to set one, which is the same refusal a mail node with
// no recipient of its own gets below.
require_once __DIR__.'/../Fixtures/EmailTemplatesStub.php';

/**
 * Writing a rule back from its row.
 *
 * The row shows one sentence — "when X happens, send Y to Z" — and this is the
 * only way that sentence is written back. Everything it refuses, it refuses
 * with the reason on it: a rule row that silently did something approximate to
 * a graph would be worse than one that sends the editor to the canvas.
 */
function makeEditableRule(array $triggerConfig = [], array $mailConfig = [], string $mailType = 'send_email'): Automation
{
    $automation = Automation::create([
        'name' => 'Contact reply',
        'handle' => 'contact-reply-'.bin2hex(random_bytes(4)),
        'enabled' => true,
    ]);

    $automation->nodes()->create([
        'node_key' => 't', 'type' => 'manual', 'position_x' => 0, 'position_y' => 0, 'config' => $triggerConfig,
    ]);

    $automation->nodes()->create([
        'node_key' => 'm',
        'type' => $mailType,
        'position_x' => 0,
        'position_y' => 0,
        'config' => $mailConfig ?: ['subject' => 'Thanks', 'to' => 'hallo@example.com', 'body' => 'x'],
    ]);

    $automation->edges()->create(['from_node_key' => 't', 'to_node_key' => 'm', 'from_output' => 'default']);

    return $automation->fresh(['nodes', 'edges']);
}

function editRule(Automation $automation, array $payload): Automation
{
    return app(RuleEditor::class)->update($automation, $payload);
}

function nodeConfig(Automation $automation, string $key): array
{
    $config = $automation->fresh('nodes')->nodes->firstWhere('node_key', $key)?->config;

    return is_array($config) ? $config : [];
}

it('writes the recipient onto the mail node', function () {
    $automation = editRule(makeEditableRule(), ['recipient' => 'team@example.com']);

    expect(nodeConfig($automation, 'm')['to'])->toBe('team@example.com');
});

it('writes the template onto the mail node', function () {
    $automation = editRule(makeEditableRule(), ['template' => 'welcome']);

    expect(nodeConfig($automation, 'm')['template'])->toBe('welcome');
});

it('clears the template when it is emptied', function () {
    // Clearing is a real edit, not a missing value: an empty template means
    // "send the plain body below", which is what the node does without one.
    $rule = makeEditableRule(mailConfig: ['subject' => 'Thanks', 'to' => 'x@example.com', 'body' => 'x', 'template' => 'welcome']);

    $automation = editRule($rule, ['template' => '']);

    expect(nodeConfig($automation, 'm'))->not->toHaveKey('template');
});

it('refuses an empty recipient rather than sending a mail nowhere', function () {
    expect(fn () => editRule(makeEditableRule(), ['recipient' => '']))
        ->toThrow(RuntimeException::class);
});

it('switches the automation on and off', function () {
    $automation = editRule(makeEditableRule(), ['enabled' => false]);

    expect($automation->fresh()->enabled)->toBeFalse();
});

it('writes the dispatch mode onto the trigger without touching its other settings', function () {
    $rule = makeEditableRule(triggerConfig: [RestartPolicy::CONFIG_KEY => RestartPolicy::Restart->value]);

    $automation = editRule($rule, ['dispatch_mode' => 'sync']);

    expect(nodeConfig($automation, 't'))
        ->toHaveKey(DispatchMode::CONFIG_KEY, 'sync')
        ->toHaveKey(RestartPolicy::CONFIG_KEY, RestartPolicy::Restart->value);
});

it('refuses an unknown dispatch mode instead of quietly falling back to async', function () {
    // The reader coerces ({@see DispatchMode::fromValue}), because a value it
    // cannot parse must not start running automations inside web requests. The
    // writer refuses, because somebody typed this one and would otherwise be
    // shown async while believing they had chosen something else.
    expect(fn () => editRule(makeEditableRule(), ['dispatch_mode' => 'vielleicht']))
        ->toThrow(RuntimeException::class);
});

it('leaves alone what the payload does not name', function () {
    $automation = editRule(makeEditableRule(), ['recipient' => 'team@example.com']);

    expect(nodeConfig($automation, 'm')['subject'])->toBe('Thanks')
        ->and(nodeConfig($automation, 'm')['body'])->toBe('x')
        ->and($automation->fresh()->enabled)->toBeTrue()
        ->and(nodeConfig($automation, 't'))->not->toHaveKey(DispatchMode::CONFIG_KEY);
});

it('refuses to write to a shape that is not a rule, and says why', function () {
    // The shape check is the authority, not the controller: every write path
    // has to hit the same refusal, so a second caller cannot route around it.
    $rule = makeEditableRule();

    $rule->nodes()->create([
        'node_key' => 'd', 'type' => 'delay', 'position_x' => 0, 'position_y' => 0,
        'config' => ['amount' => 1, 'unit' => 'days'],
    ]);
    $rule->edges()->delete();
    $rule->edges()->create(['from_node_key' => 't', 'to_node_key' => 'd', 'from_output' => 'default']);
    $rule->edges()->create(['from_node_key' => 'd', 'to_node_key' => 'm', 'from_output' => 'default']);

    expect(fn () => editRule($rule->fresh(['nodes', 'edges']), ['recipient' => 'team@example.com']))
        ->toThrow(RuntimeException::class, 'delay');
});

it('refuses a field the mail node does not have', function () {
    // A mail node that takes its recipients from a list has no recipient of
    // its own. Writing `to` onto it anyway would put a key in its config that
    // nothing reads — an edit that looks applied and does nothing.
    config()->set('automations.sequence.mail_nodes', ['simple_webhook']);

    $rule = makeEditableRule(mailConfig: ['url' => 'https://example.com'], mailType: 'simple_webhook');

    expect(fn () => editRule($rule, ['recipient' => 'team@example.com']))
        ->toThrow(RuntimeException::class, 'simple_webhook');
});
