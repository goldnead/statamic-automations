# Statamic Automations

> A visual automation layer built specifically for Statamic websites.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/goldnead/statamic-automations.svg?style=flat-square)](https://packagist.org/packages/goldnead/statamic-automations)
[![License](https://img.shields.io/github/license/goldnead/statamic-automations.svg?style=flat-square)](LICENSE)

Statamic Automations gives your Statamic site a lightweight visual workflow builder. Create simple automations from forms, content events, LeadHub contacts and webhooks — without writing a custom Laravel listener for every small process.

Build flows with **Trigger**, **Filter**, **Branch** and **Action** nodes, test them with real sample data, and inspect every run with node-by-node logs.

> It is not a full n8n replacement. It is the missing automation layer for Statamic websites.

## Features

- 🎨 Visual node-based flow builder inside the Control Panel
- ⚡ Trigger nodes for forms, entries, assets, users, leads and webhooks
- 🔀 Filter and Branch nodes for simple logic
- 🛠 Action nodes for emails, webhooks, LeadHub updates and Statamic changes
- 🪄 Dynamic token picker for using event data in actions
- 🧪 Test runs with sample data
- 📋 Node-by-node execution logs
- 🔌 Optional integration with [Webhook Manager](#) and [LeadHub](#)
- 👨‍💻 Developer API for custom triggers and actions

## Requirements

- PHP 8.2+
- Statamic 5.x or 6.x
- Laravel 11.x or 12.x

## Installation

```bash
composer require goldnead/statamic-automations
```

Then publish and run the migrations:

```bash
php artisan vendor:publish --tag=statamic-automations-migrations
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=statamic-automations-config
```

## Quick Start

1. Open the Control Panel and navigate to **Automations**.
2. Click **Create Automation**.
3. Drag a **Trigger** (e.g., Form Submitted) onto the canvas.
4. Add **Filter** or **Branch** nodes for conditions.
5. Add **Action** nodes (e.g., Send Email).
6. Connect the nodes with edges.
7. **Validate** the automation, then **Test** it with sample data.
8. **Enable** the automation when ready.

## Built-in Triggers

| Trigger | Group | Description |
|---|---|---|
| Manual Trigger | Manual | For testing and ad-hoc workflows |
| Form Submitted | Statamic | When a Statamic form receives a submission |
| Entry Published | Statamic | When an entry is published |

## Built-in Actions

| Action | Group | Description |
|---|---|---|
| Send Email Notification | Notifications | Send email with token-resolved fields |
| Send Webhook (Simple) | HTTP | POST a JSON payload to a URL |
| Add Log Entry | Utilities | Write to the Automation log |
| Stop Flow | Logic | End the flow intentionally |

## Logic Nodes

- **Filter** — stop the flow if conditions don't match
- **Branch** — split the flow into `true`/`false` paths
- **Stop** — end the flow with status `stopped`
- **Delay** — wait for minutes/hours/days before continuing

## Developer API

Register a custom action:

```php
use Goldnead\StatamicAutomations\Facades\Automations;

Automations::action('my_package.send_to_internal_api', SendToInternalApiAction::class);
```

Implement the contract:

```php
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Support\ActionResult;

class SendToInternalApiAction implements AutomationAction
{
    public static function handle(): string { return 'my_package.send_to_internal_api'; }
    public static function label(): string { return 'Send to internal API'; }
    public static function description(): ?string { return 'Sends the current context to an internal endpoint.'; }
    public static function group(): string { return 'Custom'; }
    public static function supportsTestMode(): bool { return true; }

    public static function schema(): array
    {
        return [
            ['handle' => 'endpoint', 'type' => 'text', 'label' => 'Endpoint URL', 'required' => true],
            ['handle' => 'method', 'type' => 'select', 'label' => 'Method', 'options' => ['POST', 'PUT'], 'default' => 'POST'],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        // your logic here
        return ActionResult::success(['response' => 'ok']);
    }
}
```

Register a custom trigger:

```php
Automations::trigger('my_package.invoice_paid', InvoicePaidTrigger::class);
```

## Configuration

See [`config/automations.php`](config/automations.php) for available options:

- Queue connection
- Run retention (default 30 days)
- Test mode behavior (no real side effects)
- Sensitive key redaction
- Feature flags

## Testing

```bash
composer test
```

## Roadmap

- [x] Phase A: Package Skeleton
- [x] Phase B: Database & Models
- [x] Phase C: Registries & Contracts
- [x] Phase D: Execution Engine
- [x] Phase E: Built-in Nodes (Manual, Form Submitted, Entry Published, Filter, Branch, Stop, Delay, Email, Webhook, Log)
- [x] Phase F: Optional Integrations (Webhook Manager + LeadHub adapters with conditional registration)
- [x] Phase G: CP API (Automations CRUD, Nodes/Triggers/Actions metadata, Runs, Templates, Settings)
- [x] Phase H: Canvas UI — Vue Flow builder, schema-driven config panel, token picker, condition builder, run log drawer
- [ ] Phase I: Templates + Export/Import (templates exist as a registry; export/import endpoints to come)
- [ ] Phase J: Polish + Marketplace

## Frontend build

```bash
npm install
npm run build
```

Then publish the assets:

```bash
php artisan vendor:publish --tag=statamic-automations-assets
```

## Optional integrations

| Integration | Detect | Adds |
|---|---|---|
| Webhook Manager | `Goldnead\WebhookManager\Facades\WebhookManager` | "Send Webhook (via Webhook Manager)" action |
| LeadHub | `Goldnead\LeadHub\Facades\LeadHub` | Lead Created / Status Changed / Tag Added / Note Added / Follow-up Due triggers + Create-or-Update / Status / Tags / Notes / Follow-up actions |

Class names can be overridden in `config/automations.php` under `integrations`.

## License

MIT — see [LICENSE](LICENSE).
