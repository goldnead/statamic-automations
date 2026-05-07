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

## 3. Publish the assets and config (optional)

```bash
php artisan vendor:publish --tag=statamic-automations-config
php artisan vendor:publish --tag=statamic-automations-assets
```

The config copy gives you per-environment overrides for the queue, run retention and test-mode behavior. The assets copy publishes the compiled `cp.js` + `automations.css` into `public/vendor/statamic-automations/` so the Statamic CP can serve them.

If you want to build the assets yourself (e.g. you've forked the package), run:

```bash
cd vendor/goldnead/statamic-automations
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

## 7. Try a template

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
| The builder is blank | Assets not published | `php artisan vendor:publish --tag=statamic-automations-assets` |
| "Permission denied" on save | Role missing CP permissions | Grant the Automations permissions in Users → Roles |
| The run errors with "Unknown node type" | Sister addon (LeadHub / Webhook Manager) was uninstalled after the automation was built | Re-install the addon, or replace the unknown node |
| Test mode emails / webhooks aren't being sent | This is intentional | Toggle the relevant flag in `config/automations.php` under `test_mode` |
