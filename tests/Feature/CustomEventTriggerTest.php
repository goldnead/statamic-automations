<?php

/**
 * Custom event triggers (A7): turning an arbitrary application event into an
 * automation trigger, both programmatically (Automations::registerEventTrigger)
 * and via config('automations.event_triggers'). The generic listener funnels
 * the event into the EXISTING TriggerDispatcher — no duplicated pipeline.
 */

use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

/**
 * @param  array<string, mixed>  $config
 */
function makeEventTriggerAutomation(string $triggerHandle, array $config = []): Automation
{
    $automation = Automation::create(['name' => 'On event', 'handle' => "on-{$triggerHandle}", 'enabled' => true]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 't',
        'type' => $triggerHandle,
        'config' => $config,
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 'log',
        'type' => 'add_log_entry',
        'config' => ['message' => 'shipped {{ order.id }}'],
    ]);
    AutomationEdge::create([
        'automation_id' => $automation->id,
        'from_node_key' => 't',
        'to_node_key' => 'log',
    ]);

    return $automation;
}

it('registers a programmatic event trigger and shows it in the node library', function (): void {
    $this->actingAsSuperUser();

    Automations::registerEventTrigger(OrderShippedTestEvent::class, [
        'handle' => 'order_shipped',
        'label' => 'Order Shipped',
        'group' => 'Shop',
        'payload' => 'order',
        'output_schema' => ['order' => ['id' => 'string', 'total' => 'number']],
    ]);

    $triggers = $this->getJson('/cp/automations/api/nodes')->assertOk()->json('data.triggers');
    $entry = collect($triggers)->firstWhere('handle', 'order_shipped');

    expect($entry)->not->toBeNull();
    expect($entry['label'])->toBe('Order Shipped');
    expect($entry['group'])->toBe('Shop');
    expect($entry['output_schema'])->toHaveKey('order');
});

it('fires the dispatcher when the registered event is dispatched', function (): void {
    Automations::registerEventTrigger(OrderShippedTestEvent::class, [
        'handle' => 'order_shipped',
        'label' => 'Order Shipped',
        'payload' => 'order',
    ]);

    $automation = makeEventTriggerAutomation('order_shipped');

    // Dispatching the real event must funnel through the bound listener →
    // the existing TriggerDispatcher → a persisted run.
    event(new OrderShippedTestEvent(['id' => '42', 'total' => 99]));

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('maps the event payload onto the automation context via a dot-path', function (): void {
    Automations::registerEventTrigger(OrderShippedTestEvent::class, [
        'handle' => 'order_shipped',
        'payload' => 'order',
    ]);

    $trigger = app(TriggerRegistry::class)->instance('order_shipped');
    $context = $trigger->buildContext(new OrderShippedTestEvent(['id' => '7', 'total' => 12]), []);

    expect($context->get('order'))->toBe(['id' => '7', 'total' => 12]);
});

it('respects a custom matcher predicate', function (): void {
    Automations::registerEventTrigger(OrderShippedTestEvent::class, [
        'handle' => 'big_order_shipped',
        'payload' => 'order',
        'matches' => fn ($event, $config) => (int) ($event->order['total'] ?? 0) >= 100,
    ]);

    $automation = makeEventTriggerAutomation('big_order_shipped');

    event(new OrderShippedTestEvent(['id' => '1', 'total' => 10]));   // below threshold
    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);

    event(new OrderShippedTestEvent(['id' => '2', 'total' => 250]));  // above threshold
    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('registers event triggers declared in config (no-code path)', function (): void {
    $this->actingAsSuperUser();

    config(['automations.event_triggers' => [
        OrderShippedTestEvent::class => [
            'handle' => 'order_shipped_cfg',
            'label' => 'Order Shipped (config)',
            'group' => 'Shop',
            'payload' => 'order',
        ],
    ]]);

    app('automations')->bootEventTriggersFromConfig();

    $triggers = $this->getJson('/cp/automations/api/nodes')->assertOk()->json('data.triggers');
    expect(collect($triggers)->firstWhere('handle', 'order_shipped_cfg'))->not->toBeNull();

    $automation = makeEventTriggerAutomation('order_shipped_cfg');
    event(new OrderShippedTestEvent(['id' => '9', 'total' => 5]));
    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

/**
 * A host-app event used as a trigger source.
 */
class OrderShippedTestEvent
{
    /**
     * @param  array<string, mixed>  $order
     */
    public function __construct(public array $order)
    {
    }
}
