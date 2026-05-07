# API reference

All endpoints live under `/cp/automations/api` and require an authenticated CP user with the relevant permission.

> Auth: same session as the rest of the Statamic CP. Send the CSRF token in the `X-CSRF-TOKEN` header for write requests (the bundled `resources/js/api/client.js` does this automatically).

## Automations

### `GET /automations`

List automations. Supports query params: `search`, `enabled` (`1` / `0`), `per_page`.

Response: paginated `AutomationResource`. The `runs_count` field is included.

### `POST /automations`

Create an automation.

```json
{
  "name": "My Flow",
  "handle": "my-flow",
  "description": "optional",
  "nodes": [
    { "node_key": "t", "type": "manual", "config": {} },
    { "node_key": "log", "type": "add_log_entry", "config": { "message": "hi" } }
  ],
  "edges": [
    { "from_node_key": "t", "to_node_key": "log" }
  ]
}
```

Response: `201 Created` with the new `AutomationResource`.

### `GET /automations/{id}`

Returns the full automation including `nodes` and `edges`.

### `PATCH /automations/{id}`

Same shape as `POST /automations` but every field is optional. When `nodes` or `edges` are present they replace the current graph atomically.

### `DELETE /automations/{id}`

`{ "ok": true }`.

### `POST /automations/{id}/duplicate`

Creates a disabled copy with a `-copy-XXXX` suffix. Returns `201 Created`.

### `POST /automations/{id}/validate`

```json
{
  "valid": false,
  "issues": [
    { "level": "error", "code": "missing_trigger", "message": "Automation must have a trigger node." }
  ]
}
```

### `POST /automations/{id}/enable`

```json
{ "ok": true, "enabled": true }
```

Returns `422` with `issues` if the automation can't be enabled.

### `POST /automations/{id}/disable`

```json
{ "ok": true, "enabled": false }
```

### `POST /automations/{id}/test`

Runs the automation in test mode. Body:

```json
{ "context": { "form": { "email": "test@example.com" } } }
```

Response:

```json
{
  "run_id": 42,
  "status": "success",
  "duration_ms": 38,
  "node_runs": [
    { "node_key": "t", "node_type": "manual", "status": "success", "input": {…}, "output": {…} }
  ],
  "error_message": null
}
```

## Nodes

### `GET /nodes`

Returns the entire node library plus the integration snapshot:

```json
{
  "data": {
    "triggers": [ { "handle": "manual", "label": "Manual Trigger", "schema": [...], "output_schema": {...} } ],
    "logic": [...],
    "actions": [...]
  },
  "meta": {
    "integrations": { "webhook_manager": false, "leadhub": true }
  }
}
```

### `GET /triggers` / `GET /actions`

Filtered subsets of `GET /nodes`.

### `GET /nodes/{handle}`

Returns the schema for a single node.

### `GET /context-schema/{trigger_handle}`

Returns the trigger's `output_schema` — the data picker uses this.

### `GET /options/{source}`

Resolves dynamic select options. Built-in sources: `statamic.forms`, `statamic.collections`, `statamic.sites`, `leadhub.statuses`, `leadhub.tags`, `webhook_manager.destinations`.

## Runs

### `GET /runs`

Filters: `automation_id`, `status`, `trigger_type`, `is_test`, `from`, `to`, `per_page`.

Returns paginated `AutomationRunResource`.

### `GET /runs/{id}`

Returns the run with `node_runs` eager-loaded.

### `POST /runs/{id}/retry`

Re-queues a copy of the run with the original context.

```json
{ "ok": true, "run_id": 99, "queued": true }
```

### `POST /node-runs/{id}/retry`

Currently re-queues the entire automation. Partial-from-node retry is on the roadmap.

## Templates

### `GET /templates`

Returns the curated template catalog.

### `POST /templates/{handle}/install`

Copies the template into a new disabled automation. Returns `201 Created` with the new `AutomationResource`.

## Settings

### `GET /settings`

```json
{
  "data": {
    "queue": "default",
    "runs": { "store_context": true, "prune_after_days": 30, ... },
    "test_mode": { "send_real_webhooks": false, ... },
    "features": { "branch_nodes": true, ... },
    "security": { "redact_keys": [...] },
    "integrations": { "webhook_manager": false, "leadhub": true }
  }
}
```

## Export / Import

### `GET /automations/{id}/export`

Returns a JSON document with `Content-Disposition: attachment`:

```json
{
  "schema_version": 1,
  "exported_at": "2026-05-07T14:30:00Z",
  "automation": { "name": "...", "handle": "...", "description": "..." },
  "requires": ["leadhub"],
  "nodes": [...],
  "edges": [...]
}
```

### `POST /automations/import`

Two body shapes are accepted:

- JSON file upload as `file` (multipart/form-data)
- Inline payload: `{ "payload": { ... }, "handle_strategy": "auto" | "fail" }`

Response:

```json
{
  "data": { /* AutomationResource */ },
  "meta": {
    "warnings": ["..."],
    "missing_integrations": [],
    "missing_node_types": []
  }
}
```

Imports always create new automations and start disabled.

### `POST /automations/{id}/sync-to-file`

Writes the automation to `resources/automations/{handle}.json`.

```json
{ "ok": true, "path": "/path/to/resources/automations/my-flow.json" }
```

### `GET /automations/{id}/sync-status`

```json
{
  "data": {
    "file_exists": true,
    "in_sync": false,
    "file_path": "/path/to/resources/automations/my-flow.json"
  }
}
```

### `GET /automations/file-storage/list`

```json
{
  "data": [
    { "handle": "my-flow", "path": "...", "size": 1842 }
  ]
}
```
