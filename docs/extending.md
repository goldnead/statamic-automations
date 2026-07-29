# Extending Statamic Automations

Custom **triggers**, **actions**, **logic nodes**, **option sources** and **event triggers** are first-class. Any service provider can register them from its `boot()` by calling the `Automations` facade — the exact same public API the addon's own built-ins are registered through.

```php
use Goldnead\StatamicAutomations\Facades\Automations;

public function boot(): void
{
    Automations::registerAction(MyCustomAction::class);        // handle read from ::handle()
    Automations::registerTrigger(MyCustomTrigger::class);
    Automations::registerLogicNode(MyRateLimitNode::class);
    Automations::registerOptionSource('shop.products', fn ($request) => [...]);
    Automations::registerEventTrigger(\App\Events\OrderShipped::class, [...]);
}
```

Every `register*` method has a handle-less form (the class carries its own `::handle()`) and a two-arg form `register*('handle', Class::class)` for back-compat. A malformed registration (missing class, wrong contract, empty handle) throws immediately via the defensive `Automations::describe()` gate — it never silently no-ops.

Server-registered nodes appear in the CP **Node Library** with **zero frontend build**: the library is served dynamically from the registry (`GET /cp/automations/api/nodes`). The `schema()` array becomes the config form automatically.

## Custom action

Goal: send the automation context to an internal API endpoint.

### 1. Implement the contract

```php
<?php

namespace Acme\\InvoiceFlow;

use Goldnead\\StatamicAutomations\\Context\\AutomationContext;
use Goldnead\\StatamicAutomations\\Contracts\\AutomationAction;
use Goldnead\\StatamicAutomations\\Support\\ActionResult;
use Illuminate\\Support\\Facades\\Http;

class SendToInternalApiAction implements AutomationAction
{
    public static function handle(): string { return 'acme.send_to_internal_api'; }
    public static function label(): string { return 'Send to internal API'; }
    public static function description(): ?string { return 'POSTs the current context to an internal endpoint.'; }
    public static function group(): string { return 'Acme'; }
    public static function supportsTestMode(): bool { return true; }

    public static function schema(): array
    {
        return [
            ['handle' => 'endpoint', 'type' => 'text', 'label' => 'Endpoint URL', 'required' => true],
            [
                'handle' => 'method', 'type' => 'select', 'label' => 'Method',
                'options' => ['POST', 'PUT'], 'default' => 'POST',
            ],
            ['handle' => 'token', 'type' => 'text', 'label' => 'Bearer token'],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $endpoint = $config['endpoint'] ?? null;
        if (empty($endpoint)) {
            return ActionResult::failed('Endpoint URL is required.');
        }

        $payload = $context->all();

        // Respect test mode — never produce real side-effects unless asked.
        if ($context->isTestMode()) {
            return ActionResult::success([
                'preview' => ['endpoint' => $endpoint, 'payload' => $payload],
                'note' => 'Test mode — request not sent.',
            ]);
        }

        try {
            $response = Http::withToken((string) ($config['token'] ?? ''))
                ->send(strtoupper($config['method'] ?? 'POST'), $endpoint, ['json' => $payload]);

            return ActionResult::success([
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\\Throwable $e) {
            return ActionResult::failed($e->getMessage());
        }
    }
}
```

### 2. Register it

Inside any service provider's `boot()`:

```php
use Goldnead\\StatamicAutomations\\Facades\\Automations;
use Acme\\InvoiceFlow\\SendToInternalApiAction;

public function boot(): void
{
    Automations::registerAction(SendToInternalApiAction::class);
}
```

The action shows up in the **Node Library → Acme** group automatically. The schema becomes the config form on the right-hand panel without any UI work. If the action returns structured output on its success path, declare it in a static `outputSchema()` so downstream nodes and the variable picker can reference `{{ node.<key> }}`.

## Custom trigger

Triggers map an external event onto the engine's `AutomationContext`.

```php
<?php

namespace Acme\\InvoiceFlow;

use Goldnead\\StatamicAutomations\\Context\\AutomationContext;
use Goldnead\\StatamicAutomations\\Contracts\\AutomationTrigger;

class InvoicePaidTrigger implements AutomationTrigger
{
    public static function handle(): string { return 'acme.invoice_paid'; }
    public static function label(): string { return 'Invoice Paid'; }
    public static function description(): ?string { return 'Triggered when an invoice is fully paid.'; }
    public static function group(): string { return 'Acme'; }
    public static function supportsTestMode(): bool { return true; }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'min_amount',
                'type' => 'number',
                'label' => 'Minimum amount',
                'help' => 'Only fire for invoices with at least this total.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'invoice' => [
                'id' => 'string',
                'number' => 'string',
                'amount' => 'number',
                'currency' => 'string',
                'paid_at' => 'datetime',
                'customer' => [
                    'name' => 'string',
                    'email' => 'string',
                ],
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $amount = is_array($event)
            ? ($event['invoice']['amount'] ?? 0)
            : ($event->invoice->amount ?? 0);

        return $amount >= (float) ($config['min_amount'] ?? 0);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $invoice = is_array($event) ? $event['invoice'] : $event->invoice;

        return AutomationContext::make([
            'invoice' => is_array($invoice) ? $invoice : (array) $invoice,
        ]);
    }
}
```

Register the trigger, then fire it from your own listener through the shared `TriggerDispatcher` (do not re-implement the dispatch loop):

```php
use Goldnead\\StatamicAutomations\\Facades\\Automations;
use Goldnead\\StatamicAutomations\\Engine\\TriggerDispatcher;

Automations::registerTrigger(InvoicePaidTrigger::class);

Event::listen(InvoicePaid::class, function (InvoicePaid $event) {
    app(TriggerDispatcher::class)->dispatch('acme.invoice_paid', $event);
});
```

The dispatcher finds every enabled automation whose start node is this trigger, runs your `matches()` predicate, builds the context via `buildContext()` and dispatches the run. For the common case (an event → a trigger), skip the trigger class entirely and use a **custom event trigger** (below) — one call does all of it.

## Custom event trigger

Turn any application event into a trigger without writing a trigger class. `registerEventTrigger()` builds a generic trigger, registers it in the library and subscribes **one** listener that funnels the event into the existing `TriggerDispatcher`.

```php
use Goldnead\\StatamicAutomations\\Facades\\Automations;

Automations::registerEventTrigger(\\App\\Events\\OrderShipped::class, [
    'handle' => 'order_shipped',
    'label' => 'Order Shipped',
    'group' => 'Shop',
    'description' => 'Fires when an order is marked shipped.',
    // payload mapping: a dot-path string, '*' (dump public props), a closure,
    // or an invokable/`map()` class-string.
    'payload' => 'order',                 // → {{ order.id }}, {{ order.total }}
    'output_schema' => ['order' => ['id' => 'string', 'total' => 'number']],
    // optional matcher — default matches everything.
    'matches' => fn ($event, $config) => $event->order['total'] >= ($config['min_total'] ?? 0),
]);
```

Zero-PHP alternative in `config/automations.php` (closures aren't config-serialisable, so `payload` is a dot-path/`*` and `matches` an invokable class-string here):

```php
'event_triggers' => [
    \\App\\Events\\OrderShipped::class => [
        'handle' => 'order_shipped',
        'label' => 'Order Shipped',
        'group' => 'Shop',
        'payload' => 'order',
        'output_schema' => ['order' => ['id' => 'string', 'total' => 'number']],
    ],
],
```

## Custom logic node

Logic nodes implement the `AutomationLogicNode` contract: the shared `AutomationNode` statics plus an instance `execute(AutomationContext $context, array $config): ActionResult`. They steer the flow — return `ActionResult::stopped()`, `::branch(true|false)`, `::success()` with an `outputHandle` (switch case / loop / parallel fan-out), etc.

```php
<?php

namespace Acme\\InvoiceFlow;

use Goldnead\\StatamicAutomations\\Context\\AutomationContext;
use Goldnead\\StatamicAutomations\\Contracts\\AutomationLogicNode;
use Goldnead\\StatamicAutomations\\Support\\ActionResult;

class BusinessHoursGate implements AutomationLogicNode
{
    public static function handle(): string { return 'acme.business_hours'; }
    public static function label(): string { return 'Business hours only'; }
    public static function description(): ?string { return 'Stops the flow outside business hours.'; }
    public static function group(): string { return 'Acme'; }
    public static function supportsTestMode(): bool { return true; }
    public static function schema(): array { return []; }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        return now()->isWeekday()
            ? ActionResult::success()
            : ActionResult::stopped('Outside business hours.');
    }
}
```

```php
Automations::registerLogicNode(BusinessHoursGate::class);
```

The built-in condition nodes (`FilterNode`, `BranchNode`, `WaitUntilNode`) additionally expose a static `evaluate(AutomationContext, array, ConditionEvaluator)` which the engine's `NodeExecutor` prefers when present; they satisfy the contract by delegating `execute()` to it. Your own logic node only needs `execute()`.

### Declaring output handles (1.7.0+)

A node with more than one way out has to say so, or the canvas gives it a single `default` handle and the user can never wire the rest. Add a static `outputs()` returning the handles, in the order they should appear left to right:

```php
use Goldnead\\StatamicAutomations\\Support\\DeclaresOutputs;

public static function outputs(array $config = []): array
{
    return ['approved', 'rejected', 'escalated'];
}
```

The handle you return here is the one `ActionResult::success($output, 'approved')` routes on, the one stored in `automation_edges.from_output`, and the one `FlowValidator` holds the graph to. `['approved' => 'Approved', …]` gives each handle a display label on the canvas; a plain list labels them by handle.

If your outputs depend on the node's config — a different set per configured case, per branch, per mode — return a declaration instead of a list, because the canvas has to resolve them while the user is typing and cannot ask the server per keystroke. Use `outputSpec()` with the `DeclaresOutputs` trait, which derives `outputs()` from it:

```php
use Goldnead\\StatamicAutomations\\Support\\DeclaresOutputs;
use Goldnead\\StatamicAutomations\\Support\\NodeOutputs;

class AcmeRouter implements AutomationLogicNode
{
    use DeclaresOutputs;

    public static function outputSpec(): array
    {
        return NodeOutputs::spec([
            // First clause that matches wins; the last should be unconditional.
            [
                'when' => ['field' => 'mode', 'default' => 'inline', 'not' => ['inline']],
                'outputs' => [['handle' => 'default', 'label' => '']],
            ],
            [
                // One output per row of a `key_value` config field.
                'from' => ['field' => 'routes', 'handle' => 'key', 'label' => 'value'],
                'append' => [['handle' => 'default', 'label' => 'Default']],
            ],
        ], primary: 'default');
    }
}
```

`primary` names the node's continuation — the output that means "and then". Duplicate and insert-on-edge attach there; without it they use the first declared output. A `loop` declares `done` (the copy belongs after the loop, not inside its body); a branch declares none, because neither side is the continuation.

`NodeOutputs::fixed([...], primary: …)` is the shorthand for a spec with no config-dependence. The full grammar is documented in `src/Support/NodeOutputs.php`; `resources/js/composables/useNodeOutputs.js` is the resolver the canvas runs, and the two are pinned against each other by `tests/Feature/NodeOutputSpecContractTest.php` and `tests/js/node-outputs.test.mjs`.

Two things to know about compatibility:

- The spec carries a `version`. A canvas older than the spec it meets ignores it and falls back to a single `default` output rather than misreading fields it does not know, so a stale `public/vendor/statamic-automations/build` degrades instead of mis-wiring. Re-publish the assets after upgrading.
- A type whose handle ends in `.branch` and declares nothing still gets `true`/`false`, which is what `FlowValidator` has required of that suffix since the first release. Declaring your own outputs overrides it.

## Option sources

Dynamic `<select>` pickers declare `options_source: '<handle>'` in a schema field; the CP config form fetches `GET /cp/automations/api/options/<handle>`, resolved through the **OptionSourceRegistry**. Register your own resolver — full parity with the built-ins:

```php
Automations::registerOptionSource('shop.products', fn (\\Illuminate\\Http\\Request $request) =>
    \\App\\Models\\Product::all()->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all()
);
```

A resolver returns `[['value' => ..., 'label' => ...], ...]`; a plain `value => label` map or a list of scalars is accepted and normalised. Errors and unknown sources resolve to an empty list, never a fatal. The resolver may also be an invokable class-string (or one exposing `resolve(Request)`).

Built-in sources (each also available under a bare handle, e.g. `collections` == `statamic.collections`):

| Source | Returns |
|---|---|
| `statamic.collections` | All collections |
| `statamic.entries` (`?collection=`) | Entries in a collection |
| `statamic.blueprints` (`?collection=`) | Blueprints |
| `statamic.taxonomies` / `statamic.terms` (`?taxonomy=`) | Taxonomies / terms |
| `statamic.sites` | Configured sites |
| `statamic.users` / `statamic.roles` / `statamic.groups` | Users / roles / user groups |
| `statamic.globals` | Global sets |
| `statamic.forms` | Statamic forms |
| `statamic.assets` (`?container=`) / `statamic.asset_containers` | Assets / containers |
| `automations` | Other automations (for sub-automation actions) |
| `leadhub.statuses` / `leadhub.tags` | LeadHub (when installed) |
| `webhook_manager.destinations` | Webhook Manager destinations (when installed) |

## Native Statamic action nodes

The addon ships these operation nodes (group **Statamic**), all registered through the public API above: `create_entry`, `update_entry`, `publish_entry`, `unpublish_entry`, `delete_entry`, `create_term`, `create_user`, `update_user`, `assign_user_role`, `add_user_to_group`, `set_global_value`, `send_email`. Each wraps the real Statamic API and gates its writes behind the `persist_statamic_changes` test-mode flag, so a test run previews without touching content.

## Schema field types

The dynamic config panel renders these field types out of the box:

| Type | Renders as |
|---|---|
| `text` | Single-line input + token picker |
| `textarea` | Multi-line input + token picker |
| `number` | Number input |
| `select` | Dropdown — provide `options` (array) or `options_source` (e.g. `leadhub.statuses`) |
| `key_value` | Repeating key / value rows |
| `tags` | Tag input (comma / enter separated) |
| `data_reference` | Reference to a context node (defaults to `{{ source.id }}`) |
| `condition_list` | Filter / Branch condition builder |

## Tokens

Tokens use dot notation against the `AutomationContext`:

```
{{ form.email }}
{{ lead.full_name }}
{{ entry.url }}
{{ webhook.payload.customer.email }}
{{ nodes.previous_action.delivery_id }}
```

Resolution rules:

- A single-token string returns the **structured** value (array, object).
- Multi-token strings always interpolate to strings.
- Missing tokens render as empty strings.

## Testing your custom nodes

The engine has full unit-test coverage. Mirror the existing tests when adding your own:

```php
use Goldnead\\StatamicAutomations\\Context\\AutomationContext;
use Acme\\InvoiceFlow\\SendToInternalApiAction;

it('skips real http in test mode', function () {
    $action = new SendToInternalApiAction();
    $context = AutomationContext::make([], testMode: true);

    $result = $action->execute($context, ['endpoint' => 'https://example.com']);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output)->toHaveKey('preview');
});
```
