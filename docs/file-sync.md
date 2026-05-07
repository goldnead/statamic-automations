# File-backed automations

Automations live in the database at runtime, but you can optionally
mirror them to JSON files so they can be tracked in Git, included in
starter kits or moved between environments.

## TL;DR

```bash
# Export every DB automation to resources/automations/{handle}.json
php artisan automations:sync --from=db

# Import every file into the DB
php artisan automations:sync --from=files

# Watch for changes during development (re-runs every 2s)
php artisan automations:sync --from=db --watch

# Show what would happen, change nothing
php artisan automations:sync --from=db --dry-run
```

## How it works

- The package writes a portable, schema-versioned JSON document per
  automation to the path configured in
  `automations.file_storage.path` (default: `resources/automations/`).
- The DB remains the runtime source of truth; files are a portable
  representation suited for version control.
- Imports always create new automations and start them disabled.
- Handle conflicts are resolved automatically by appending a short
  random suffix unless you pass `--strategy=fail`.

## Conflict strategies

When a file matches an existing DB automation by handle:

- `--strategy=db_wins` (default) — keep the DB row, ignore the file
- `--strategy=file_wins` — delete the DB row, recreate from the file

`file_wins` is destructive and bypasses the handle suffixing logic.
Use it on a fresh deploy, never on a production environment with
runtime changes.

## CI / Deploy hook

A common pattern is to commit `resources/automations/*.json` and run
the sync as the last step of a deploy:

```bash
# composer.json
"scripts": {
    "post-deploy": [
        "@php artisan automations:sync --from=files --strategy=file_wins"
    ]
}
```

This makes the Git repository the source of truth — every deploy
guarantees the automations match the committed JSON.

## Sync status from the API

For each automation the CP exposes a sync status:

```
GET /cp/automations/api/automations/{id}/sync-status
```

```json
{
  "data": {
    "file_exists": true,
    "in_sync": false,
    "file_path": "/.../resources/automations/my-flow.json"
  }
}
```

Use this to surface a "needs sync" indicator in custom UIs or to gate
deploys on a green sync state.

## Manual export from the UI

The Builder ships an **Export** button that downloads the JSON
representation directly. The list screen has a per-row Export action
for the same purpose. The Import page (`/cp/automations/import`)
accepts both file uploads and pasted JSON.
