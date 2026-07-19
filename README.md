<div align="center">

![Statamic Automations](art/cover.png)

# Statamic Automations

**A visual automation layer built specifically for Statamic websites.**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/goldnead/statamic-automations.svg?style=flat-square)](https://packagist.org/packages/goldnead/statamic-automations)
[![License](https://img.shields.io/badge/license-commercial-blue?style=flat-square)](LICENSE)
[![Statamic](https://img.shields.io/badge/statamic-6.x-orange?style=flat-square)](https://statamic.com)
[![Tests](https://github.com/goldnead/statamic-automations/actions/workflows/tests.yml/badge.svg)](https://github.com/goldnead/statamic-automations/actions/workflows/tests.yml)
[![Build](https://github.com/goldnead/statamic-automations/actions/workflows/build.yml/badge.svg)](https://github.com/goldnead/statamic-automations/actions/workflows/build.yml)
[![Lint](https://github.com/goldnead/statamic-automations/actions/workflows/lint.yml/badge.svg)](https://github.com/goldnead/statamic-automations/actions/workflows/lint.yml)

Build automations for Statamic forms, entries, leads and webhooks  
— with a familiar visual flow builder inside the Control Panel.

</div>

---

> Statamic Automations gives your site a lightweight visual workflow builder. Create automations from forms, content events, LeadHub contacts and webhooks — without writing a custom Laravel listener for every small process.
>
> Build flows with **Trigger**, **Filter**, **Branch** and **Action** nodes, test them with real sample data, and inspect every run with node-by-node logs.
>
> _It's not a full n8n replacement. It's the **missing automation layer** for Statamic websites._

![Visual flow builder](screenshots/builder.png)

<p align="center">
  <img src="screenshots/dashboard.png" width="49%" alt="Overview dashboard" />
  <img src="screenshots/runs.png" width="49%" alt="Run history" />
</p>

## Why this exists

Statamic is wonderful as a CMS and developer framework, but typical website automations still demand:

- custom Laravel events + listeners
- hand-rolled webhooks
- third-party tools like Zapier / Make / n8n
- opaque "what just happened?" lead and form pipelines

For most Statamic projects an external automation tool is overkill, and custom code for every small workflow is expensive to maintain. **Statamic Automations** sits exactly in that gap.

## Features

- 🎨 **Visual node-based flow builder** inside the Control Panel
- ⚡ **Triggers** for forms, entries, assets, users, leads and webhooks
- 🔀 **Filter** and **Branch** nodes for simple logic
- 🛠 **Actions** for emails, webhooks, LeadHub updates and Statamic changes
- 🪄 **Token picker** for using event data in actions (`{{ form.email }}`, `{{ lead.full_name }}` …)
- 🧪 **Test runs** with real sample data — no real side-effects in test mode
- 📋 **Node-by-node execution logs** with redacted sensitive payloads
- 🧩 Optional **Webhook Manager** + **LeadHub** integrations (auto-detected, never required)
- 📦 **Templates** that copy into user-owned automations
- 📤 **JSON export / import** for version control, starter kits and cross-environment moves
- 👨‍💻 Public **developer API** for custom triggers, actions and conditions

## Screenshots

> Screenshots will be added once a reference Statamic project is set up. Until then, the [Architecture overview](docs/architecture.md) gives you the big picture.

| Builder | Run log | Templates |
|---|---|---|
| _coming soon_ | _coming soon_ | _coming soon_ |

## Requirements

- PHP **8.2+**
- Laravel **11.x**, **12.x** or **13.x**
- Statamic **6.x**

## Installation

```bash
composer require goldnead/statamic-automations
php artisan migrate
```

That's it. The addon ships its compiled Control Panel assets (Inertia + Vue 3)
under `resources/dist/build/`, and Statamic publishes them to your site's
`public/vendor/statamic-automations/` automatically on install — **there is no
end-user build step.**

Optionally publish the config to customise defaults:

```bash
php artisan vendor:publish --tag=statamic-automations-config
```

> **Developing the addon?** The frontend is built with the official Statamic 6
> Vite convention (`@statamic/cms/vite-plugin`). From a clone, run
> `composer install && npm install && npm run build`, or use
> `scripts/setup-playground.sh` to spin up a full Statamic 6 playground.

Make sure your queue worker is running so automation runs are dispatched off the request thread:

```bash
php artisan queue:work --queue=default
```

## Quick start

1. Open the Statamic CP and navigate to **Automations**.
2. Click **New automation**.
3. Drag a **Trigger** (e.g. _Form Submitted_) onto the canvas from the Node Library.
4. Add **Filter** or **Branch** nodes if you need conditions.
5. Add **Action** nodes (e.g. _Send Email_).
6. Connect the nodes by dragging between handles.
7. Click **Validate** then **Test** with sample data.
8. Toggle **Enabled** when ready — the automation now runs against real events.

Or skip steps 1–6 and start from a **template**: most common patterns ship as one-click installs.

## Built-in nodes

### Triggers

| Trigger | Group | Source |
|---|---|---|
| Manual Trigger | Manual | For testing & ad-hoc runs |
| Form Submitted | Statamic | A Statamic form receives a submission |
| Entry Published | Statamic | An entry is published |
| Lead Created _(LeadHub)_ | LeadHub | A new lead is added |
| Lead Status Changed _(LeadHub)_ | LeadHub | A lead transitions between statuses |
| Lead Tag Added _(LeadHub)_ | LeadHub | A tag is added to a lead |
| Lead Note Added _(LeadHub)_ | LeadHub | A note is added to a lead |
| Lead Follow-up Due _(LeadHub)_ | LeadHub | A scheduled follow-up becomes due |

### Logic

| Node | Purpose |
|---|---|
| Filter | Stop the flow if conditions aren't met |
| Branch | Split into `true` / `false` paths |
| Stop | End the flow with status `stopped` |
| Delay | Wait for minutes / hours / days, then continue |

### Actions

| Action | Group | Notes |
|---|---|---|
| Send Email Notification | Notifications | Token-resolved subject + body |
| Send Webhook (Simple) | HTTP | Direct POST/PUT/PATCH |
| Send Webhook _(via Webhook Manager)_ | Webhook Manager | Inherits transport, signing, retry, logs |
| Add Log Entry | Utilities | Writes to your Laravel log channel |
| Create / Update Entry | Statamic | Create or edit an entry from token-resolved data |
| Publish / Unpublish / Delete Entry | Statamic | Change or remove an entry by id |
| Create Term | Statamic | Add a taxonomy term |
| Create / Update User | Statamic | Create or merge field data on a user |
| Assign User Role · Add User to Group | Statamic | Add/remove a role or group membership |
| Set Global Value | Statamic | Set a key on a global set (per site) |
| Stop Flow | Logic | Ends the flow intentionally |
| Create or Update Lead _(LeadHub)_ | LeadHub | Email-based upsert |
| Change Lead Status _(LeadHub)_ | LeadHub | |
| Add / Remove Lead Tag _(LeadHub)_ | LeadHub | |
| Add Lead Note _(LeadHub)_ | LeadHub | Token-resolved body |
| Create / Complete Follow-up _(LeadHub)_ | LeadHub | |

## Templates

Eight curated templates ship with the addon — each one is **copied** into a user-owned automation when installed, so updates to the addon never silently change your existing flows.

- **New Lead Notification** — email the admin when a LeadHub lead is created
- **Form Submission to Webhook** — forward submissions to an external URL
- **Qualified Lead to CRM** — push qualified leads + add note + schedule follow-up
- **Workshop Inquiry Flow** — capture, tag, notify, schedule follow-up
- **Lead Magnet Delivery** — send the file, create a tagged lead, log the delivery
- **Follow-up Reminder** — daily reminders for due follow-ups
- **Entry Published Notification** — webhook on collection publish (Slack-friendly)
- **Webhook Failure Alert** — admin email when a destination keeps failing

## Works with

Automations is the **orchestration layer** in a small family of addons. Each one owns a different concern:

- **statamic-automations** — orchestration: multi-step workflows (triggers → conditions → actions) built visually in the CP.
- **[statamic-webhook-manager](https://github.com/goldnead/statamic-webhook-manager)** — transport: reliable HTTP in and out (delivery, retries, auth, signing, logging). Automations can delegate its webhook delivery to it.
- **[statamic-leadhub](https://github.com/goldnead/statamic-leadhub)** — CRM: contacts, follow-ups and opportunities whose events become automation triggers.

Note that both Automations and Webhook Manager can react to the same Statamic events (e.g. an entry save). Pick **one place per concern**: if a save should just fire a webhook, configure it in Webhook Manager; if it should run a multi-step workflow, build it here — don't wire the same event in both.

## Optional integrations

Sister addons are detected automatically through `class_exists`. The package keeps working without them.

| Integration | Class | Adds |
|---|---|---|
| Webhook Manager | `Goldnead\WebhookManager\Facades\WebhookManager` | "Send Webhook (via Webhook Manager)" action with Webhook Manager destinations |
| LeadHub | `Goldnead\Leadhub\Facades\LeadHub` | 5 LeadHub triggers + 7 LeadHub actions |

Class names are configurable in `config/automations.php` under `integrations`, so you can swap implementations or use a fork.

## Extending Automations

The addon exposes a full public extensibility API. A third-party addon (or your host app) registers custom nodes and data sources from any service provider's `boot()` — the very same surface the built-ins are registered through. Server-registered nodes appear in the CP node library with **no frontend build**, and their `schema()` becomes the config form automatically.

```php
use Goldnead\StatamicAutomations\Facades\Automations;

public function boot(): void
{
    // Nodes — handle-less overload reads ::handle() from the class.
    Automations::registerAction(SendToInternalApiAction::class);
    Automations::registerTrigger(InvoicePaidTrigger::class);
    Automations::registerLogicNode(BusinessHoursGate::class);

    // Populate a custom <select> picker (options_source: 'shop.products').
    Automations::registerOptionSource('shop.products', fn ($request) =>
        \App\Models\Product::all()->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all()
    );

    // Turn any application event into a trigger — one call registers the node
    // AND subscribes a listener that funnels the event into the dispatcher.
    Automations::registerEventTrigger(\App\Events\OrderShipped::class, [
        'handle' => 'order_shipped',
        'label' => 'Order Shipped',
        'group' => 'Shop',
        'payload' => 'order',                                   // → {{ order.id }}
        'output_schema' => ['order' => ['id' => 'string', 'total' => 'number']],
    ]);
}
```

Custom **actions** implement `AutomationAction`, **triggers** `AutomationTrigger`, **logic nodes** `AutomationLogicNode` (all extend the shared `AutomationNode`). Event triggers can also be declared config-only in `config/automations.php` under `event_triggers`. A malformed registration throws immediately (`Automations::describe()`), never silently no-ops.

Full documentation — interface definitions, the schema-field vocabulary, option-source reference and worked copy-paste examples for every extension point — lives in [`docs/extending.md`](docs/extending.md).

## Export & Import

Every automation can be exported to a portable JSON file (schema-versioned), and re-imported in any environment:

- **Export**: `GET /cp/automations/api/automations/{id}/export` (or click _Export_ in the builder topbar)
- **Import**: drop a JSON file on `/cp/automations/import`
- **File sync**: optionally store automations in `resources/automations/{handle}.json` for Git-based versioning

Imports always create new automations (never silently overwrite), start disabled, and surface warnings for missing integrations or unknown node types.

## Configuration

See [`config/automations.php`](config/automations.php). Highlights:

- `queue` / `queue_connection` — dedicated queue for automation runs
- `runs.prune_after_days` — default 30, override or set to `null` to disable pruning
- `test_mode.*` — fine-grained switches for what runs during a test (default: nothing real)
- `security.redact_keys` — patterns redacted in run logs
- `integrations.*` — class names for sister addon detection
- `file_storage.path` — where exported JSON files are written

## Testing

```bash
composer test
```

The package ships with unit + feature tests for the engine, validators, integrations, exporter/importer, registries and the CP API.

## Documentation

| Document | Topic |
|---|---|
| [Getting started](docs/getting-started.md) | Install, build assets, first automation |
| [Architecture](docs/architecture.md) | Engine flow, data model, lifecycle |
| [Extending](docs/extending.md) | Custom triggers, actions, conditions |
| [API reference](docs/api.md) | The complete CP JSON API |
| [Templates](docs/templates.md) | Catalog of every built-in template |
| [File sync](docs/file-sync.md) | `resources/automations/` + `automations:sync` |
| [Licensing](docs/licensing.md) | Pro tier, license modes, status endpoint |
| [Autosave](docs/autosave.md) | Builder autosave behavior |
| [Changelog](CHANGELOG.md) | Versioned release notes |

## Roadmap

- [x] Phase A — Package skeleton
- [x] Phase B — Database + Eloquent models
- [x] Phase C — Registries + Contracts + Facade
- [x] Phase D — Execution engine (validator, runner, token resolver, conditions, logger)
- [x] Phase E — Built-in triggers, logic and actions
- [x] Phase F — Optional Webhook Manager + LeadHub integrations
- [x] Phase G — Full CP JSON API
- [x] Phase H — Vue Flow canvas + schema-driven config
- [x] Phase I — Templates + JSON export / import + file sync
- [x] Phase J — Polish (empty / loading / error states, toast feedback, marketplace docs)
- [x] **Sprint 4** — `automations:sync` + `automations:prune` Artisan commands, partial-from-node retry, encrypted run logs (`EncryptedJson` cast), license manager (`config` + `remote` modes), Vue Flow autosave, verified Statamic v6 events
- [x] **Sprint 5 (CI)** — GitHub Actions workflows green across the full matrix: PHP 8.2 / 8.3 / 8.4 × Laravel 11 / 12 (PHPUnit), Frontend (Vite + Vue 3), and Lint (PHP syntax + `composer validate`)
- [x] **Sprint 6 (Statamic 6 UI)** — CP frontend rewritten on Statamic 6's native Inertia.js + Vue 3 + Tailwind v4 stack with `@statamic/cms/ui` components. Dark-mode + command-palette + listing-presets all "for free" via the host's design system.
- [x] **Sprint 7 (test suite)** — full PHPUnit suite passes end-to-end against a real Statamic 6.18 install. 71 tests / 223 assertions / 0 skipped. Diagnosed and fixed the route-model-binding edge case that had blocked one HTTP feature test for several sprints.

The PRD-defined roadmap is now complete. Future iterations will focus
on community feedback, marketplace screenshots, and quality-of-life
improvements (loop detection in branches, parallel execution, code
nodes — all explicitly out of scope for v1 per the PRD non-goals).

## Editions

Statamic Automations ships in two editions:

- **Free** — the full visual builder, triggers, logic and core actions.
- **Pro** — premium features (e.g. the AI action and custom node registration),
  unlocked with a Pro license from the [Statamic Marketplace](https://statamic.com/addons).

The active edition is resolved natively through Statamic's licensing system —
the Control Panel's licensing utility shows your status.

## License

Commercial software, licensed (not sold) through the Statamic Marketplace.
See [LICENSE](LICENSE). © 2026 Adrian Goldner.
