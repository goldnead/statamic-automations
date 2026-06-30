# Statamic Automations — Post-MVP Implementation Plan

This document is the engineering roadmap for everything beyond the current MVP.
It is opinionated, grounded in the existing architecture, and designed around the
**three-addon ecosystem**:

| Layer | Addon | Responsibility |
|---|---|---|
| **Orchestration** | **Statamic Automations** (this addon) | Triggers → conditions → multi-step actions across the whole Statamic surface |
| **Transport / IO** | **Webhook Manager** | HTTP in & out, auth (Bearer/Basic/HMAC), retry policies, delivery snapshots, replay |
| **Domain data** | **LeadHub** | Contacts, leads, timelines, follow-ups |

The guiding rule: **Automations does not re-implement what a sister addon already
owns.** Webhook/HTTP transport is delegated to Webhook Manager; lead data is
delegated to LeadHub. Automations is the brain, not the pipes.

---

## 1. Design principles

1. **Composition over duplication.** Anything that is HTTP transport, signing,
   delivery retry/logging belongs to Webhook Manager. Automations consumes it via
   `WebhookManagerAdapter` and surfaces references (delivery ids) in run logs.
2. **Registry-first.** Every new trigger/action/logic node is a class implementing
   `AutomationTrigger` / `AutomationAction` / `AutomationNode`, registered through
   the `automations` manager (`->trigger()`, `->action()`, `->node()`), exactly
   like the built-ins in `ServiceProvider::registerBuiltInNodes()`. No special-casing.
3. **Queue-first & idempotent.** Runs already dispatch through the queue
   (`RunAutomation`). Every new long-running primitive (loop, wait, schedule) must
   be resumable and safe to replay.
4. **Everything is testable with Pest.** Each node ships a unit test; HTTP surfaces
   are covered by `CpRoutesTest` / `ApiSmokeTest`; engine behaviour by feature tests.
5. **Pro-gating is declarative.** Pro features are gated through `LicenseManager`
   (`gates()`), never hard-coded.
6. **Backwards compatible.** New columns are nullable with sane defaults; existing
   automations keep working without migration intervention.

---

## 2. Current baseline (done — for reference)

- Triggers: `manual`, `form_submitted`, `entry_published`
- Logic: `filter`, `branch`, `delay`, `stop`
- Actions: `send_email`, `send_webhook` (simple fallback), `add_log_entry`
- Engine: `WorkflowRunner`, `NodeExecutor`, `ConditionEvaluator`, `TokenResolver`,
  `FlowValidator`, `RunLogger`
- Runs: per-node logging, **retry-from-node**, delay/resume via `AutomationScheduledJob`
- Integrations: LeadHub (5 triggers + 7 actions), Webhook Manager (send action)
- Export/Import (JSON) + flat-file sync; templates catalog; licensing; permissions
- CP: Inertia + Vue Flow builder, runs, templates, import, settings

---

## 3. Epics & features

Each feature lists: **Goal · Approach · Depends on · Effort (S/M/L) · Phase · Acceptance.**

### EPIC A — Trigger expansion ("the when")

#### A1. "Webhook received" trigger (Webhook Manager bridge) — *flagship*
- **Goal:** Start a flow when Webhook Manager receives a validated inbound request.
- **Approach:** Extend `WebhookManagerAdapter` to expose inbound endpoints; add
  `Nodes/Triggers/WebhookReceivedTrigger` registered only when
  `IntegrationDetector::hasWebhookManager()`. Subscribe to WM's inbound event
  (e.g. `Goldnead\WebhookManager\Events\WebhookReceived` — confirm exact FQCN) in
  `registerEventListeners()`; map the payload + headers into `AutomationContext`.
  Trigger config = which WM endpoint handle to listen to (options source
  `webhook_manager.inbound_endpoints`).
- **Depends on:** Webhook Manager installed; WM emitting an inbound event with a
  stable payload shape.
- **Effort:** M · **Phase:** 1
- **Acceptance:** Feature test fakes the WM inbound event → asserts a run is created
  with the payload in context; trigger is hidden when WM is absent.

#### A2. Scheduled / cron trigger
- **Goal:** Time-based automations (daily digest, cleanup, "every Monday 9am").
- **Approach:** `Nodes/Triggers/ScheduledTrigger` with cron/interval config. A
  `Console/Commands/RunScheduledAutomations` command registered on Laravel's
  scheduler (`$schedule->command(...)->everyMinute()`), which finds due automations
  (new `automation_schedules` table or a `schedule` column) and dispatches
  `RunAutomation`. Honour timezone + "catch up vs skip".
- **Depends on:** host cron / `schedule:run`.
- **Effort:** M · **Phase:** 1
- **Acceptance:** Travelling time in tests → due automations dispatch exactly once.

#### A3. Additional Statamic event triggers
- **Goal:** React to the full content lifecycle.
- **Scope:** `entry_saved`, `entry_deleted`, `entry_unpublished`, `term_saved`,
  `term_deleted`, `asset_uploaded`, `asset_deleted`, `user_registered`,
  `user_saved`, `user_logged_in`, `global_saved`, `nav_saved`.
- **Approach:** One trigger class each (or a parametrised `EntryEventTrigger`),
  plus listeners in `registerEventListeners()` keyed by Statamic event FQCN
  (already string-guarded for version safety). Reuse `ContextBuilder` to shape
  each event's context; document the available tokens per trigger via
  `outputSchema()`.
- **Effort:** M (core set), L (full set) · **Phase:** 1 (core: entry_saved/deleted,
  user_registered), 3 (rest)
- **Acceptance:** Each trigger has a unit test firing the event → flow runs.

#### A4. Parametrised manual trigger + bulk run
- **Goal:** "Run with inputs" and "run on selected listing rows".
- **Approach:** Extend `ManualTrigger` with an input schema (fields rendered in the
  test/run modal). Add a CP action that posts selected entry/submission ids to a
  new `automations/{automation}/run` endpoint, dispatching one run per item.
- **Effort:** M · **Phase:** 2
- **Acceptance:** Posting N ids creates N runs each with the item in context.

---

### EPIC B — Action expansion ("the do")

#### B1. Content actions
- **Goal:** Mutate Statamic content from a flow.
- **Scope:** `create_entry`, `update_entry`, `delete_entry`,
  `update_triggering_record` (set fields on the entry/submission that started the
  flow), `create_user`, `update_user`, `set_term`, `manage_asset_tags`.
- **Approach:** One `AutomationAction` per operation; field values resolved through
  `TokenResolver`. Respect test mode via `automations.test_mode.persist_statamic_changes`
  (the flag already exists). Use Statamic facades (`Entry`, `User`, `Term`).
- **Effort:** L · **Phase:** 1 (create/update entry + update_triggering_record),
  2 (rest)
- **Acceptance:** Test asserts entry created/updated; in test mode nothing persists.

#### B2. Notification actions
- **Goal:** Notify humans.
- **Scope:** `cp_notification` (Statamic CP toast/inbox), `slack`, `discord`,
  `teams`, generic.
- **Approach:** Slack/Discord/Teams are HTTP → **prefer a Webhook Manager
  destination** + `webhook_manager.send`; ship them as **destination templates**
  rather than bespoke actions. `cp_notification` is native (Statamic notification).
- **Effort:** S (cp_notification), S each via WM destination · **Phase:** 1
  (cp_notification), 3 (chat connectors as WM templates)
- **Acceptance:** cp_notification surfaces in the CP; chat templates documented.

#### B3. Deepen `webhook_manager.send`
- **Goal:** Expose more WM power inside the node.
- **Approach:** Add config for auth scheme selection, success evaluator, and
  "re-render on replay"; map WM `delivery_id` → run log with a deep link.
- **Effort:** S · **Phase:** 1 (delivery link), 2 (auth/evaluator)
- **Acceptance:** Run detail links to the WM delivery; status reflected.

#### B4. Call sub-automation
- **Goal:** Reuse flows.
- **Approach:** `call_automation` action that dispatches another automation with a
  mapped context and (optionally) waits for completion. Guard against recursion
  (depth limit in `WorkflowRunner`).
- **Effort:** M · **Phase:** 2
- **Acceptance:** Parent run shows child run reference; recursion is blocked.

#### B5. AI / LLM action
- **Goal:** Generate/classify/summarise inside a flow (e.g. auto-tag a lead,
  summarise a submission, draft a reply).
- **Approach:** `ai_generate` action calling the **Claude API** (default to the
  latest Claude model). API key via secrets (G3). Outbound HTTP can go through
  Webhook Manager or a direct client; keep it behind Pro gating.
- **Effort:** M · **Phase:** 3
- **Acceptance:** Mocked API → action returns text into context; gated by license.

#### B6. Set-variable / transform action
- **Goal:** Compute and store intermediate values.
- **Approach:** `set_variable` action writing into a `vars` namespace on
  `AutomationContext`; values via the expression engine (D1).
- **Effort:** S · **Phase:** 2
- **Acceptance:** Downstream nodes read `{{ vars.x }}`.

---

### EPIC C — Control flow

#### C1. Loop / iterator
- **Goal:** Iterate an array (submissions, tags, API rows) and run a sub-path per item.
- **Approach:** `loop` logic node with `over` (token to an array) and a body path;
  `NodeExecutor` executes the downstream sub-graph per item with the item in a
  `loop.item` scope. Cap iterations (config) and support aggregation.
- **Effort:** L · **Phase:** 2
- **Acceptance:** N items → body runs N times; node run log records each iteration.

#### C2. Wait-until / Wait-for-webhook
- **Goal:** Pause until a condition holds or an external callback arrives.
- **Approach:** Extend the existing delay/resume machinery
  (`AutomationScheduledJob` + `ResumeDelayedRun`): `wait_until` re-checks a condition
  on a schedule; `wait_for_webhook` parks the run and resumes when a WM inbound
  request with a correlation id arrives (ties into A1). Add a `paused` run status.
- **Effort:** L · **Phase:** 2
- **Acceptance:** Run parks then resumes on event/condition; timeout path covered.

#### C3. Switch (multi-branch)
- **Goal:** Route to one of many paths.
- **Approach:** `switch` node with N labelled outputs evaluated via
  `ConditionEvaluator`; `FlowValidator` validates output handles (mirrors the
  existing `branch` validation).
- **Effort:** M · **Phase:** 2
- **Acceptance:** Validator accepts valid outputs; correct branch taken.

#### C4. Parallel branches + join
- **Goal:** Fan-out then join.
- **Approach:** `parallel`/`join` nodes; `WorkflowRunner` tracks multiple active
  paths and a join barrier. Needs careful run-state modelling.
- **Effort:** L · **Phase:** 3
- **Acceptance:** Branches run independently; join waits for all.

#### C5. Throttle / debounce / dedupe
- **Goal:** Prevent duplicate or runaway runs.
- **Approach:** Idempotency key (token) on the trigger; a `dedupe_key` column on
  `automation_runs` + a short-lived lock (cache) so identical events within a
  window collapse to one run.
- **Effort:** M · **Phase:** 3
- **Acceptance:** Duplicate events within the window create a single run.

---

### EPIC D — Data & expressions

#### D1. Richer expression language
- **Goal:** Transformations beyond `{{ token }}` — dates, strings, math, JSONPath,
  conditionals.
- **Approach:** Extend `TokenResolver` with a pluggable filter/function registry
  (`{{ form.email | lower }}`, `{{ now | date('Y-m-d') }}`). **Ideally extract a
  shared package** so Webhook Manager and Automations speak one syntax.
- **Effort:** L · **Phase:** 2
- **Acceptance:** Filter unit tests; existing tokens unaffected.

#### D2. Variables & scoping
- **Goal:** Named, typed variables across a run.
- **Approach:** Formalise a `vars` scope in `AutomationContext` (set by B6, read by
  tokens), with run-log visibility.
- **Effort:** S · **Phase:** 2

#### D3. Payload mapping UI
- **Goal:** Visually map source fields → target payload.
- **Approach:** A `key_value`/mapping fieldtype in the config panel that emits the
  token-templated object consumed by HTTP/WM actions.
- **Effort:** M · **Phase:** 3

---

### EPIC E — Reliability & operations

#### E1. Flow-level retry policies + dead-letter
- **Goal:** Survive transient failures.
- **Approach:** Per-action retry config (max attempts, backoff) honoured by
  `NodeExecutor`; exhausted runs land in a dead-letter state for inspection/replay.
  (Distinct from WM's per-delivery retry — this is flow-level.)
- **Effort:** M · **Phase:** 3

#### E2. Failure alerts
- **Goal:** Tell someone when a run fails.
- **Approach:** Config-driven notifier (email + cp_notification + optional WM chat
  destination) fired by `RunLogger` on terminal failure; throttled per automation.
- **Effort:** S · **Phase:** 1
- **Acceptance:** Failing run sends exactly one alert (respecting throttle).

#### E3. Concurrency, timeouts, queue routing
- **Goal:** Control execution under load.
- **Approach:** Per-automation `queue`, `max_concurrency`, `timeout` settings;
  enforced via Laravel queue middleware (`WithoutOverlapping`) and job timeouts.
- **Effort:** M · **Phase:** 3

#### E4. Run observability dashboard
- **Goal:** See health at a glance.
- **Approach:** A dashboard page: success/failure rate, durations, throughput, top
  failing automations; **searchable/filterable runs** (by automation, status,
  date, dedupe key). Export run logs (CSV/JSON).
- **Effort:** L · **Phase:** 3

#### E5. Run ↔ Webhook Manager delivery linking
- **Goal:** Trace a flow end-to-end across both addons.
- **Approach:** Persist WM `delivery_id` (already captured) on the node run; render
  a deep link + live status in the run detail. Reverse link in WM optional.
- **Effort:** S · **Phase:** 1
- **Acceptance:** Run detail links to the WM delivery and shows its status.

#### E6. Versioning (draft/published + rollback)
- **Goal:** Safe edits, no silent breakage of live flows.
- **Approach:** `automation_versions` table (JSON snapshot of nodes/edges/config);
  `status` (draft|published) + `published_version` on `automations`. Runs execute
  the *published* version; the builder edits a draft. Rollback restores a version.
- **Effort:** L · **Phase:** 2
- **Acceptance:** Publishing snapshots; editing a draft doesn't affect live runs;
  rollback restores exactly.

#### E7. Audit log
- **Goal:** Who changed/enabled/ran what.
- **Approach:** `automation_audit` table; write on create/update/enable/disable/
  delete/run-retry with user + diff. Surface in a CP tab. Pro-gated.
- **Effort:** M · **Phase:** 2

---

### EPIC F — Testing & developer experience

#### F1. Test mode with real sample payloads
- **Goal:** Test against realistic data.
- **Approach:** Sample picker in the test modal pulling recent submissions/entries
  for the trigger type; build context via `ContextBuilder`.
- **Effort:** M · **Phase:** 1

#### F2. Inline per-node testing
- **Goal:** Run a single node with the current upstream context.
- **Approach:** `automations/{automation}/nodes/{key}/test` endpoint executing one
  node via `NodeExecutor` and returning its `ActionResult`.
- **Effort:** M · **Phase:** 2

#### F3. Dry-run path preview
- **Goal:** Visualise which path a sample payload would take without side effects.
- **Approach:** Reuse test mode with all side effects blocked; highlight the taken
  path on the canvas.
- **Effort:** M · **Phase:** 3

---

### EPIC G — Platform

#### G1. Internationalisation (i18n)
- **Goal:** Translatable CP (currently English-only labels).
- **Approach:** Register a `statamic-automations::` translation namespace; wrap all
  CP strings in `__()`; ship `resources/lang/en`. Mirror LeadHub's approach
  (force-register the namespace in `register()`).
- **Effort:** M · **Phase:** 1
- **Acceptance:** No raw `statamic-automations::` keys leak (extend `CpRoutesTest`).

#### G2. Multi-site awareness
- **Goal:** Site-scoped triggers/automations.
- **Approach:** Optional `site` filter on triggers; `site` context; site column on
  automations for scoping.
- **Effort:** M · **Phase:** 3

#### G3. Secrets / credentials management
- **Goal:** Store API keys/tokens safely.
- **Approach:** Encrypted credential store (reuse the `EncryptedJson` cast) with a
  CP screen; actions reference a credential handle, never a raw secret. Delegate
  outbound auth to Webhook Manager where possible. Add SSRF guards on any direct
  HTTP fallback.
- **Effort:** M · **Phase:** 3

#### G4. Human-in-the-loop approval
- **Goal:** Pause for manual approval.
- **Approach:** `approval` node parking the run (reuse C2 wait machinery) until an
  authorised user approves/rejects in the CP; notify approvers.
- **Effort:** M · **Phase:** 3

#### G5. Granular per-automation permissions
- **Goal:** Restrict who can edit/run specific automations.
- **Approach:** Extend `AutomationPolicy` with per-automation grants; CP UI to
  assign. Builds on the existing permission set.
- **Effort:** M · **Phase:** 3

---

### EPIC H — Ecosystem & commercial

#### H1. Connector templates as Webhook Manager destinations
- **Goal:** One-click "send to Slack/Mailchimp/CRM" without building connectors.
- **Approach:** Ship destination presets (URL/auth/template) installable into WM;
  Automations references them via `webhook_manager.send`.
- **Effort:** S each · **Phase:** ongoing

#### H2. Shared token/expression package
- **Goal:** One templating syntax across Automations + Webhook Manager.
- **Approach:** Extract `TokenResolver` + filters (D1) into a small shared Composer
  package both addons depend on.
- **Effort:** L · **Phase:** 2–3

#### H3. Cross-addon documentation
- **Goal:** Explain Automations × Webhook Manager × LeadHub.
- **Approach:** `docs/integrations.md` with the layering, the end-to-end inbound→
  logic→outbound flow, and "when to use which tool" guidance.
- **Effort:** S · **Phase:** 1

#### H4. Pro feature gating wiring + license server
- **Goal:** Monetise the Pro tier.
- **Approach:** Define the Pro feature set in config; gate via `LicenseManager`
  (`gates()`); finalise remote license verification (the `remote` mode already
  exists in `LicenseManager`).
- **Effort:** M · **Phase:** 2

#### H5. Expanded template catalog
- **Goal:** More one-click starting points (now including webhook-received and
  scheduled patterns).
- **Approach:** Add entries to `TemplateRegistry` once A1/A2 land.
- **Effort:** S · **Phase:** ongoing

---

## 4. Data model & migrations

New / changed (all additive, nullable defaults):

- `automations`: `+ status` (draft|published, default published), `+ published_version`,
  `+ queue`, `+ max_concurrency`, `+ timeout`, `+ site`, `+ last_error_at`.
- `automation_runs`: `+ dedupe_key` (indexed), `+ paused_at`, `+ correlation_id`.
- `automation_node_runs`: `+ delivery_id` (WM link), `+ attempt`.
- **new** `automation_versions` (id, automation_id, version, snapshot json, created_by, created_at).
- **new** `automation_audit` (id, automation_id, user_id, action, diff json, created_at).
- **new** `automation_schedules` *or* reuse `AutomationScheduledJob` with a `kind`
  column (cron|delay|wait).
- **new** `automation_credentials` (handle, label, encrypted payload) — G3.

---

## 5. Config additions (`config/automations.php`)

- `triggers.scheduled.timezone`, `triggers.scheduled.catch_up`
- `alerts` (channels, throttle window) — E2
- `retries` (default max attempts, backoff) — E1
- `concurrency` defaults — E3
- `integrations.webhook_manager.inbound_event` + `inbound_endpoints` source — A1
- `features` map for Pro gating — H4
- `ai` (model, key reference) — B5

---

## 6. Testing strategy

- **Unit (Pest):** each new trigger/action/logic node → behaviour + schema test.
- **Engine:** loop, wait/resume, switch, retries → feature tests travelling time
  and faking events/queues.
- **HTTP:** every new page/endpoint added to `CpRoutesTest` / `ApiSmokeTest`
  (render 200 + correct Inertia component / JSON shape).
- **i18n guard:** assert no raw `statamic-automations::` keys leak.
- **Integration:** WM bridge tests fake the WM facade/events; assert graceful
  degradation when WM/LeadHub absent (mirrors current optional-integration tests).
- Keep the matrix green on PHP 8.2/8.3/8.4 × Statamic 6.

---

## 7. Phased release plan

| Phase | Theme | Features |
|---|---|---|
| **v1.0 — Launch-complete** | Make it feel whole | A1 (webhook-received), A2 (scheduled), A3 (core events), B1 (create/update entry + update triggering record), B2 (cp_notification), E2 (failure alerts), E5 (run↔delivery), F1 (real sample payloads), G1 (i18n), H3 (cross-addon docs) |
| **v1.1 — Pro power** | Differentiation | A4, B3 (auth/evaluator), B4 (sub-automation), B6 (set-variable), C1 (loop), C2 (wait), C3 (switch), D1/D2 (expressions/vars), E6 (versioning), E7 (audit), F2 (inline test), H4 (Pro gating) |
| **v2.0 — Scale & ecosystem** | Enterprise/scale | A3 (full event set), B5 (AI), C4 (parallel), C5 (dedupe), D3 (mapping UI), E1 (flow retries), E3 (concurrency), E4 (dashboard), F3 (dry-run), G2/G3/G4/G5, H1/H2/H5 |

**Single highest-leverage starting point:** A1 (webhook-received trigger) + E5
(run↔delivery linking) — together they turn three good addons into one coherent
bidirectional integration platform, with minimal code (the adapter/trigger pattern
already exists).

---

## 8. Open questions / dependencies

- **WM inbound event contract:** exact FQCN + payload shape of the inbound event,
  and a public way to list inbound endpoint handles (needed for A1).
- **Shared token package:** worth extracting now (H2) or after D1 stabilises?
- **License server:** hosted endpoint + key format for `remote` mode (H4).
- **AI provider/key handling:** confirm Claude as default and the secret storage
  model (B5 + G3).
