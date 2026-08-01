<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Nodes\Actions\CreateEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\CreateUserAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SetVariableAction;
use Goldnead\StatamicAutomations\Nodes\Logic\SwitchNode;
use Goldnead\StatamicAutomations\Nodes\Triggers\EntrySavedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\ScheduledTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\WebhookReceivedTrigger;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;

it('registers the new built-in nodes', function () {
    $registry = app(NodeRegistry::class);

    foreach (['entry_saved', 'entry_deleted', 'user_registered', 'scheduled'] as $h) {
        expect($registry->has($h))->toBeTrue("trigger {$h} missing");
    }
    foreach (['switch', 'set_variable', 'wait_until', 'call_automation'] as $h) {
        expect($registry->has($h))->toBeTrue("logic {$h} missing");
    }
    foreach (['create_entry', 'update_entry', 'create_user'] as $h) {
        expect($registry->has($h))->toBeTrue("action {$h} missing");
    }
});

it('switch routes to the matching case output', function () {
    $node = new SwitchNode;
    $ctx = AutomationContext::make([]);

    $result = $node->execute($ctx, ['value' => 'qualified', 'cases' => ['new' => 'a', 'qualified' => 'b']]);

    expect($result->isSuccess())->toBeTrue();
    expect($result->outputHandle)->toBe('b');
});

it('switch falls through to default when nothing matches', function () {
    $node = new SwitchNode;
    $result = $node->execute(AutomationContext::make([]), ['value' => 'x', 'cases' => ['new' => 'a']]);

    expect($result->outputHandle)->toBe('default');
});

it('set_variable writes into the vars scope', function () {
    $node = new SetVariableAction;
    $ctx = AutomationContext::make([]);

    $node->execute($ctx, ['variables' => ['greeting' => 'hello', 'count' => 5]]);

    expect($ctx->get('vars.greeting'))->toBe('hello');
    expect($ctx->get('vars.count'))->toBe(5);
});

it('create_entry previews in test mode without persisting', function () {
    $node = new CreateEntryAction;
    $ctx = AutomationContext::make([], testMode: true);

    $result = $node->execute($ctx, ['collection' => 'pages', 'data' => ['title' => 'Hi']]);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output)->toHaveKey('preview');
    expect($result->output['preview']['collection'])->toBe('pages');
});

it('create_entry fails without a collection', function () {
    $result = (new CreateEntryAction)->execute(AutomationContext::make([]), []);
    expect($result->isFailed())->toBeTrue();
});

it('create_user previews in test mode', function () {
    $node = new CreateUserAction;
    $ctx = AutomationContext::make([], testMode: true);

    $result = $node->execute($ctx, ['email' => 'a@b.de', 'name' => 'A B']);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['preview']['email'])->toBe('a@b.de');
});

it('entry_saved trigger matches by collection scope', function () {
    $trigger = new EntrySavedTrigger;
    $event = ['entry' => ['id' => '1', 'collection' => 'blog']];

    expect($trigger->matches($event, ['collection' => 'blog']))->toBeTrue();
    expect($trigger->matches($event, ['collection' => 'pages']))->toBeFalse();
    expect($trigger->matches($event, []))->toBeTrue();
});

it('entry_saved trigger builds entry context', function () {
    $ctx = (new EntrySavedTrigger)->buildContext(['entry' => ['id' => '7', 'collection' => 'blog']], []);
    expect($ctx->get('entry.id'))->toBe('7');
});

it('scheduled trigger always matches and seeds context', function () {
    $trigger = new ScheduledTrigger;
    expect($trigger->matches([], []))->toBeTrue();
    expect($trigger->buildContext(['at' => 'now'], ['frequency' => 'daily'])->get('scheduled.frequency'))
        ->toBe('daily');
});

it('webhook_received trigger matches by endpoint and maps payload', function () {
    $trigger = new WebhookReceivedTrigger;
    $event = ['endpoint' => 'orders', 'payload' => ['x' => 1], 'headers' => ['h' => 'v']];

    expect($trigger->matches($event, ['endpoint' => 'orders']))->toBeTrue();
    expect($trigger->matches($event, ['endpoint' => 'other']))->toBeFalse();
    expect($trigger->matches($event, []))->toBeTrue();

    $ctx = $trigger->buildContext($event, []);
    expect($ctx->get('webhook.payload.x'))->toBe(1);
    expect($ctx->get('webhook.endpoint'))->toBe('orders');
});
