<div align="center">

# Statamic Automations

**A visual automation layer built specifically for Statamic websites.**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/goldnead/statamic-automations.svg?style=flat-square)](https://packagist.org/packages/goldnead/statamic-automations)
[![License](https://img.shields.io/github/license/goldnead/statamic-automations.svg?style=flat-square)](LICENSE)
[![Statamic](https://img.shields.io/badge/statamic-5.x%20%7C%206.x-orange?style=flat-square)](https://statamic.com)
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
- Laravel **11.x** or **12.x**
- Statamic **5.x** or **6.x**

## Installation

```bash
composer require goldnead/statamic-automations
```

Run the migrations:

```bash
php artisan vendor:publish --tag=statamic-automations-migrations
php artisan migrate
```

Optionally publish the config and frontend assets:

```bash
php artisan vendor:publish --tag=statamic-automations-config
php artisan vendor:publish --tag=statamic-automations-assets
```

The frontend assets are built into `resources/dist/` inside the package. To build them yourself:

```bash
cd vendor/goldnead/statamic-automations
npm install
npm run build
```

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

## Optional integrations

Sister addons are detected automatically through `class_exists`. The package keeps working without them.

| Integration | Class | Adds |
|---|---|---|
| Webhook Manager | `Goldnead\WebhookManager\Facades\WebhookManager` | "Send Webhook (via Webhook Manager)" action with Webhook Manager destinations |
| LeadHub | `Goldnead\LeadHub\Facades\LeadHub` | 5 LeadHub triggers + 7 LeadHub actions |

Class names are configurable in `config/automations.php` under `integrations`, so you can swap implementations or use a fork.

## Developer API

Register a custom action:

```php
use Goldnead\StatamicAutomations\Facades\Automations;

Automations::action('my_package.send_to_internal_api', SendToInternalApiAction::class);
```

Register a custom trigger:

```php
Automations::trigger('my_package.invoice_paid', InvoicePaidTrigger::class);
```

Full documentation including interface definitions, schema fields and worked examples lives in [`docs/extending.md`](docs/extending.md).

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

The PRD-defined roadmap is now complete. Future iterations will focus
on community feedback, marketplace screenshots, and quality-of-life
improvements (loop detection in branches, parallel execution, code
nodes — all explicitly out of scope for v1 per the PRD non-goals).

## License

[MIT](LICENSE)
