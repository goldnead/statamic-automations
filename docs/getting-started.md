# Getting started

This guide walks you from a fresh Statamic install to a running automation in roughly five minutes.

## 1. Install the package

```bash
composer require goldnead/statamic-automations
```

The Laravel auto-discovery picks up the service provider automatically. If you've disabled discovery, register it manually:

```php
// config/app.php
'providers' => ServiceProvider::defaultProviders()->merge([
    Goldnead\StatamicAutomations\ServiceProvider::class,
])->toArray(),
```

## 2. Run the migrations

```bash
php artisan vendor:publish --tag=statamic-automations-migrations
php artisan migrate
```

Six tables are created: `automations`, `automation_nodes`, `automation_edges`, `automation_runs`, `automation_node_runs`, `automation_scheduled_jobs`.

## 3. Publish the config (optional)

```bash
php artisan vendor:publish --tag=statamic-automations-config
```

The config copy gives you per-environment overrides for the queue, run retention and test-mode behavior.

The compiled Control Panel assets ship with the package under `resources/dist/build/` and are published to `public/vendor/statamic-automations/` automatically on install (the Statamic 6 Vite convention) — there is no manual asset-publish or build step.

If you've forked the package and want to rebuild the assets, run (from the package root):

```bash
composer install
npm install
npm run build
```

## 4. Make sure a queue worker is running

Automation runs always go through the queue so they never block the request that produced the underlying event:

```bash
php artisan queue:work --queue=default
```

In production, run this as a supervised process (Supervisor, systemd, Forge, Vapor — your choice). Default queue: `default` (configurable via `STATAMIC_AUTOMATIONS_QUEUE`).

## 5. Configure permissions

The addon registers nine permissions under the **Automations** group. Assign them to the roles that should manage automations in **Users → Roles**:

- `view automations`
- `create automations`
- `edit automations`
- `delete automations`
- `enable automations`
- `run automation tests`
- `view automation runs`
- `retry automation runs`
- `manage automation settings`

## 6. Create your first automation

1. Open the Statamic CP and navigate to **Automations**.
2. Click **New automation**.
3. Drag a **Form Submitted** trigger from the Node Library onto the canvas.
4. In the right-hand config panel, pick the form you want to react to.
5. Add an **Add Lead Tag** action (if LeadHub is installed) or **Send Email Notification** otherwise.
6. Connect the trigger to the action by dragging from the source handle to the target handle.
7. Click **Save**.
8. Click **Validate** to make sure the flow is well-formed.
9. Click **Test** to run it with sample data — no real side-effects happen in test mode.
10. Toggle **Enabled** when you're happy.

That's it. The next form submission will dispatch a queued run, and you'll see it under **Automations → Runs**.

## 7. What a test run does (and does not) check

**Test** runs the whole chain with the context you supply — which, unless you paste one in, is empty. That shapes what a test run can tell you:

- **Configuration is checked.** A node with no tag, no task title, no target stage fails, and it should: that automation is broken and would be broken in production too.
- **Data references are not.** Fields that point at a record produced by the run itself (`{{ lead.id }}`, `{{ contact_id }}`, `{{ opportunity.id }}` — the ones labelled as a reference in the config panel) resolve to nothing when the context is empty. A test run previews them as empty and carries on, instead of failing every action that has one. Live runs still refuse to act on an unresolved reference, and say which one it was.
- **Nothing real happens.** Side effects are gated per category in `config/automations.php`:

  | Flag | Default | When `true` a test run… |
  |---|---|---|
  | `test_mode.send_real_emails` | `false` | actually sends mail |
  | `test_mode.send_real_webhooks` | `false` | actually delivers webhooks |
  | `test_mode.persist_statamic_changes` | `false` | actually writes entries, terms, users, globals |
  | `test_mode.persist_leadhub_changes` | `false` | actually writes to LeadHub (tags, notes, tasks, stages, scores) |
  | `test_mode.call_real_ai` | `false` | actually calls the AI provider (and bills you) |

  The same switches are editable under **Settings → Addon settings**, in the Statamic Automations section (permission: `manage automation settings`). Turning any of them on makes a test run behave like a live run for that category — including the data-reference check, which is then enforced again.

To exercise a reference end to end, hand the test run a context instead of turning a flag on: `POST /cp/automations/api/automations/{id}/test` accepts a `context` object, e.g. `{"lead": {"id": "abc"}}`.

## 8. Try a template

Skip steps 3–6 and start from a curated flow:

1. Go to **Automations → Templates**.
2. Pick a template (e.g. _Form Submission to Webhook_).
3. Click **Install template** — a new automation is created from a copy of the template.
4. Edit the new automation, fill in the URL / email / form handle, then save and enable.

Templates are copies, not references — you can edit them freely without affecting the template catalog.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Nothing happens after a form submission | No queue worker running | Start `php artisan queue:work` |
| The builder is blank | Compiled assets not published to `public/` | Re-run `php artisan vendor:publish --tag=statamic-automations --force` |
| "Permission denied" on save | Role missing CP permissions | Grant the Automations permissions in Users → Roles |
| The run errors with "Unknown node type" | Sister addon (LeadHub / Webhook Manager) was uninstalled after the automation was built | Re-install the addon, or replace the unknown node |
| Test mode emails / webhooks aren't being sent | This is intentional | Toggle the relevant flag in `config/automations.php` under `test_mode` |
