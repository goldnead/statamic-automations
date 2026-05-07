# Extending Statamic Automations

Custom **triggers**, **actions** and **logic nodes** are first-class. Any service provider can register them by calling the `Automations` facade.

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
    Automations::action(SendToInternalApiAction::handle(), SendToInternalApiAction::class);
}
```

The action shows up in the **Node Library → Acme** group automatically. The schema becomes the config form on the right-hand panel without any UI work.

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

Register the trigger and wire up your own listener that fires it:

```php
use Goldnead\\StatamicAutomations\\Facades\\Automations;
use Goldnead\\StatamicAutomations\\Models\\Automation;
use Goldnead\\StatamicAutomations\\Engine\\WorkflowRunner;
use Goldnead\\StatamicAutomations\\Jobs\\RunAutomation;

Automations::trigger(InvoicePaidTrigger::handle(), InvoicePaidTrigger::class);

Event::listen(InvoicePaid::class, function (InvoicePaid $event) {
    $trigger = app(\\Goldnead\\StatamicAutomations\\Registries\\TriggerRegistry::class)
        ->instance('acme.invoice_paid');

    $automations = Automation::query()
        ->where('enabled', true)
        ->whereHas('nodes', fn ($q) => $q->where('type', 'acme.invoice_paid'))
        ->with('nodes')
        ->get();

    foreach ($automations as $automation) {
        $node = $automation->nodes->first(fn ($n) => $n->type === 'acme.invoice_paid');
        if (! $trigger->matches($event, $node->config ?? [])) continue;

        $context = $trigger->buildContext($event, $node->config ?? []);
        $run = app(WorkflowRunner::class)->createRun($automation, $context, $node);
        RunAutomation::dispatch($run->id, $context->all(), false);
    }
});
```

## Custom logic node

Logic nodes are first-class engine constructs and have a slightly different shape — instead of `execute()`, they expose a static `evaluate()` that receives the engine's `ConditionEvaluator`. See `src/Nodes/Logic/FilterNode.php` for a reference implementation.

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

## Option sources

`options_source` values map to the `NodesController::options()` resolver:

| Source | Returns |
|---|---|
| `statamic.forms` | All Statamic forms |
| `statamic.collections` | All Statamic collections |
| `statamic.sites` | Configured Statamic sites |
| `leadhub.statuses` | LeadHub statuses (only when LeadHub is installed) |
| `leadhub.tags` | LeadHub tags |
| `webhook_manager.destinations` | Webhook Manager destinations |

To add your own source, override the controller in your application — or open a PR.

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
