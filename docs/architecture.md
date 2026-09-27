# Architecture

A high-level walkthrough of how Statamic Automations is wired together.

## Layers

```
┌─────────────────────────────────────────────────────────────────────┐
│  Vue Flow Canvas + List screens (resources/js)                       │
│  ─ AutomationBuilder, NodeLibrary, ConfigPanel, TokenPicker          │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ axios JSON
┌──────────────────────────────▼──────────────────────────────────────┐
│  CP JSON API (routes/cp.php → src/Http/Controllers/*)                │
│  ─ Automations CRUD, Nodes metadata, Runs, Templates, Export/Import  │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────────────┐
│  Engine (src/Engine, src/Context)                                    │
│  ─ WorkflowRunner ─ NodeExecutor ─ ConditionEvaluator                │
│  ─ TokenResolver  ─ FlowValidator ─ RunLogger                        │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────────────┐
│  Registries (src/Registries)                                         │
│  ─ TriggerRegistry ─ ActionRegistry ─ NodeRegistry                   │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────────────┐
│  Eloquent models + 6 tables (src/Models, database/migrations)        │
│  ─ automations / nodes / edges / runs / node_runs / scheduled_jobs   │
└──────────────────────────────────────────────────────────────────────┘
```

## Data model

### `automations`

The flow itself: `name`, `handle`, `description`, `enabled`, `version`, `last_run_at`.

### `automation_nodes`

Each node carries its position on the canvas, a `type` (matches a registered handle, e.g. `form_submitted`), and a JSON `config` matching that node's schema.

### `automation_edges`

Directed connections between nodes. `from_output` is `default` for normal nodes and `true` / `false` for branch nodes — the runner uses this to decide which path to follow.

### `automation_runs`

A single execution attempt: status, full context (with redaction), timing, optional `is_test` flag.

### `automation_node_runs`

Per-node execution record stored as the runner walks the graph. Stores `input`, `output` (both redacted), error message and duration.

### `automation_scheduled_jobs`

Used by Delay nodes — when a delay node fires, the runner suspends the run and writes a scheduled job that resumes it once `due_at` passes.

## Lifecycle

```
Statamic event (form submission, entry published, etc.)
       │
       ▼
Listener (HandleFormSubmitted / HandleEntryPublished)
       │
       ▼
Find enabled automations whose trigger matches  ← TriggerRegistry::matches
       │
       ▼
Build initial AutomationContext from the event  ← Trigger::buildContext
       │
       ▼
Persist a queued AutomationRun                 ← WorkflowRunner::createRun
       │
       ▼
Dispatch RunAutomation queued job
       │
       ▼
WorkflowRunner::execute(run, context)
   ├─ Validate the automation
   ├─ Walk the graph along outgoing edges
   ├─ For each node:
   │     ├─ Resolve its config tokens     ← TokenResolver
   │     ├─ Execute it                     ← NodeExecutor
   │     └─ Persist a node run             ← RunLogger (with redaction)
   ├─ Branch nodes: pick `true` or `false` edge
   ├─ Filter nodes: stop the flow on no-match (inside a loop body: skip the item)
   ├─ Loop nodes: walk the `loop` output once per item, then `done`
   └─ Delay nodes: pause + write scheduled_job, return WAITING
       │
       ▼
Mark the run as success / failed / stopped / waiting
```

## Validation

`FlowValidator` runs both before activation and at the start of each execution. It enforces:

- exactly one trigger node
- the trigger has no incoming edges
- every required config field is present
- every node `type` is a registered handle
- edges only reference existing node keys
- branch nodes only have `true` / `false` outputs
- no cycles (DFS coloring)

Activation is blocked while any error-level issue is present. Warnings are surfaced in the UI but don't block execution.

## Conditions

`ConditionEvaluator` evaluates a condition list against the context. Each condition is shaped like `{ field, operator, value }`. The evaluator supports `equals`, `does_not_equal`, `contains`, `starts_with`, `ends_with`, `is_empty`, `is_not_empty`, numeric comparisons, date comparisons (`date_before`, `date_after`), `includes_tag`, plus convenience aliases (`status_is`, `form_is`, `collection_is`, `site_is`).

Condition mode is `all` or `any`. Filter nodes stop the flow when the conditions fail; branch nodes route to `true` / `false`.

## Loops and errors

An inline **Loop** runs the nodes on its *For each item* output once per item, with `{{ item }}`,
`{{ index }}` and `{{ loop.* }}` set, then continues on *After loop*. Nested loops shadow the
outer loop's variables and restore them afterwards.

Inside a loop body:

| What happens | Effect |
|---|---|
| A **Filter** does not match | Only this item ends; the next item runs. Outside a loop a Filter still stops the run. A Filter inside a nested loop ends the item of the loop it sits in. |
| A **Stop** node | Ends the whole run, as at the top level. |
| A **Delay / Wait** | Pauses the whole run, as at the top level. |
| A node **fails**, Loop `on_item_error: stop` (default) | The whole run fails. |
| A node **fails**, Loop `on_item_error: continue` | Only this item ends; the next item runs. |

With `on_item_error: continue` the failed node stays in the run log as failed, the loop's output
gains `failed_items` (count) and `failed` (`[{index, error}]`), readable after the loop as
`{{ nodes.<loop>.failed_items }}`, and the run finishes with its normal status (usually
`success`) but with an error message like `Loop 'loop': 2 of 5 items failed and were skipped
(index 1, 3). First error: …`. There is no separate "partial" status; the message is what shows the
failure on the run, the dashboard and the activity list. A failure alert is only sent for runs
that fail.

**Not the same as `_on_error: continue` on a node.** That reserved key keeps a failed node from
failing the run by moving on: down the node's `error` edge if it has one, otherwise down its
**default** edge, so the next step runs without the failed node's output (and may write
incomplete data). The Loop option instead ends the item's pass at the failed node. Use
`_on_error` when you wire an `error` edge that handles the failure, and the Loop option to skip
an item that cannot be processed.

## Tokens

`TokenResolver` walks strings and arrays and replaces `{{ dot.path }}` against the context. A few subtleties worth knowing:

- A string that is a single token (`"{{ data.list }}"`) returns the **structured** value (array, object), not its JSON string representation. This lets actions accept either a literal value or a token reference.
- Multi-token strings (`"Hello {{ name }}, welcome {{ company }}"`) always interpolate to strings.
- Missing tokens render as empty strings.
- Sensitive keys (configurable in `automations.security.redact_keys`) are redacted before being written to run logs.

## Testing surface

Run logs in test mode are persisted as real `AutomationRun` rows with `is_test = true`. Actions check `$context->isTestMode()` and either render a preview payload or skip execution. The defaults in `config/automations.php` ensure no real emails, webhooks or persisted lead changes happen during a test.

Each action declares `supportsTestMode(): bool`. If `false`, the executor skips the action during a test run rather than producing real side-effects.

## Optional integrations

Sister addons (Webhook Manager, LeadHub) are detected through `IntegrationDetector::class_exists`-style checks at boot time. If detected, additional triggers and actions are registered. The detection class names are configurable in `config/automations.php` under `integrations.*` so you can ship forks or stub implementations.

## Frontend

The Vue Flow canvas is intentionally **dumb**: it only renders nodes, lets you draw edges, and emits change events upward. All business logic — validation, schema interpretation, token resolution — lives on the PHP side. This keeps the canvas library replaceable and the engine independently testable.
