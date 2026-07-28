# Changelog

## 1.5.4 — 2026-07-28

### Fixed — the handle unique did not constrain anything without a brand

Since 1.5.0 the automation handle is unique per brand: `unique(brand_id, handle)`. The column it leads with was added nullable, and **a SQL unique does not constrain NULL** — on any engine. Two rows that differ only by a NULL in an indexed column are both accepted, and there is no limit to how many. So for every `automations` row without a brand_id, the one identifier this addon promises to keep unique was not constrained at all: the handle could repeat freely, and `Automation::where('handle', …)->first()` would return whichever row the engine happened to reach first.

The models stamp brand_id on create, which is why the hole never opened in normal use. It is reachable from everything that writes the table without going through Eloquent — an import, an upsert, a data fix from tinker — and this package's own test fixture did exactly that, inserting automations rows with no brand_id for a year without anything noticing. A constraint that holds only while every future writer remembers something is not a constraint.

**Why a green suite would never have found it.** Not because the assertion was missing, but because the thing to assert is invisible from the test's vantage point. The suite runs on in-memory SQLite, where the schema is never measured and NULL-permeability is not a property anything reports; the addon's own fixture inserted the NULL rows and the tests passed, since there were never two of them with the same handle. Nothing fails until a second row arrives, on a host, months later, and then it does not fail either — it resolves to the wrong automation. `statamic-notifications` v1.0.4 found the same shape in its preferences table, where an entire recipient type had been unconstrained since it shipped.

`automations.brand_id` is now NOT NULL. `2026_07_24_100002` tightens it where it creates it, which helps new installations only; `2026_07_28_000003_require_brand_id_on_automations_table` is for the ones already on 1.5.x. It is idempotent, a no-op on a fresh install, and it renames rather than deletes any duplicate handles it has to separate before the backfill — an automation is somebody's work, and a suffixed handle is visible and fixable where a deleted flow is neither. Renames are written to the log.

Only `automations` is tightened. The denormalized brand_id on the child tables stays nullable: none of them carries a unique, and changing a column's nullability on MySQL rebuilds the table with `ALGORITHM=COPY` — a fair price on `automations`, which holds one row per automation, and the wrong one on `automation_runs`, which grows without bound. Tenant separation is unchanged and asserted rather than assumed: two brands can still hold the same handle, and one brand still cannot hold it twice.

### Fixed — the handle validation was still global, three releases after the schema stopped being

The mirror image of the same question, found by asking of every unique whether it enforces what its name claims. `StoreAutomationRequest` and `UpdateAutomationRequest` still used `Rule::unique('automations', 'handle')`, which compiles to a query on the raw query builder that no Eloquent global scope ever reaches, and is therefore global.

Two consequences, both silent, both in the direction of the validator being stricter than the database. A brand could not create an automation with a handle another brand had already taken, although the schema has allowed exactly that since 1.5.0 and that was the entire point of the change. And the refusal named the reason: *"The handle has already been taken"* is a statement about rows the asking tenant is not permitted to see. Both rules now carry `->where('brand_id', …)`.

### Added — the suite can see MySQL's index rules

`tests/Unit/IndexKeyLengthTest.php`, ported from `statamic-notifications` v1.0.4 by way of `statamic-webhook-manager` v1.6.1, compiles this package's own migration files through Laravel's MySQL grammar in pretend mode and measures the DDL MySQL would have received — no server, no connection, nothing to install in CI. It reads the real migration files, so it cannot drift from them, and it needs the extended version: this schema is built across eleven migrations, and `brand_id` arrives by `alter table … add` long after the create migrations, together with the drop of the global handle unique and the per-brand one that replaces it.

It asserts three things: no index over InnoDB's 3072 bytes; no index over **half** of it, because an index that is under the limit by accident breaks on the next column added to it; and no unique covering a column that may be NULL — the check that failed above.

**What the measurement says about the width.** Sound, and sound by luck rather than by check until now. The widest index is **1028 bytes**, 33% of the limit, shared by `automations_brand_id_handle_unique`, `automation_nodes_automation_id_node_key_unique`, the two `automation_edges` node-key lookups and `automation_runs_status_created_at_index`. Nothing is near the wall. `statamic-notifications` v1.0.3 shipped a 3212-byte unique that had run hundreds of times locally and died on the production hub with *SQLSTATE 1071*, leaving two tables that never existed there — the arithmetic that rejects it is a MySQL mechanism and does not exist in SQLite to be tested.

`phpunit.mysql.xml` runs the identical suite against a real MySQL server (`vendor/bin/pest -c phpunit.mysql.xml`, `AUTOMATIONS_TEST_DB=mysql`), for the run that proves the compiled DDL and the engine agree.

### Added — a test level for the Control Panel's Vue code (Vitest)

The package had two test levels and a gap between them. PHPUnit reaches the route, the FormRequest, the controller and the props it hands to Inertia; `tests/js/*.test.mjs` reaches the builder's pure functions. Neither could mount a component, and the builder keeps most of its state there.

Rolled out from `statamic-webhook-manager` v1.6.0: the `test` block lives in the existing `vite.config.js` (under `VITEST` the Statamic Vite plugin is swapped for the plain Vue plugin, because the former rewrites `vue` to `window.Vue` — correct for the CP bundle, fatal in a test process), `tests/js/setup.js` installs the `__STATAMIC__` global the `@statamic/cms/*` shims destructure at import time, and the new dependencies are `vitest`, `@vue/test-utils` and `jsdom`. Two additions to that setup were needed here: `__` is installed on `globalThis`, because this addon's components call the translator from `<script setup>` and not only from templates, and the stubs forward event listeners, without which a stubbed `<Button>` cannot be clicked and no interaction is testable. `npm test` runs the component suite; `npm run test:js` keeps running the pure-function one.

### Fixed — four node types had a setting the editor silently swallowed

`ConfigPanel` filtered `mode` out of every generic field form unconditionally, because for `filter`, `branch` and `wait_until` the `mode` field *is* the all/any selector that `ConditionBuilder` renders instead. But `ConditionBuilder` only mounts when the schema declares `conditions`, and four node types declare a `mode` and no conditions. Their setting was removed from the form and nothing put it back:

- **`add_user_to_group` and `assign_user_role`** — "Remove from group" and "Remove role" could not be configured at all. The panel offered the group or the role and nothing else.
- **`parallel` and `loop`** — the inline/automation switch was unreachable, and on `parallel` that setting decides the node's entire output set.

`defaultConfigForSchema` seeded the default (`add`, `inline`), so every affected node validated and looked complete. `mode` is now filtered only where there are conditions for it to combine.

### Fixed — an edge output handle could be stored as an empty string

`edges.*.from_output` is `['nullable', 'string']`, so `""` is valid input, and every write path normalised it with `$edge['from_output'] ?? 'default'` — which substitutes for a *missing* key, not for a present empty one. Stored, the edge is invisible on the canvas (Vue Flow cannot resolve `sourceHandle: ""` against a handle called `default`, and the source node still shows an unused "+" adder on the output it is already wired to) and dead at run time: `WorkflowRunner` selects outgoing edges with `$e->from_output === $output`, so the edge is never followed. The run reports success and stops one node early, with nothing to show for it.

A CP save was protected by accident — Laravel's `ConvertEmptyStringsToNull` turns the cleared field into null before the FormRequest sees it. An import reads JSON off disk, where `""` stays `""`. `AutomationEdge` now normalises both handles on write, so every path gets the same guarantee, including the ones added next.

### Fixed — `??` where `||` was meant, in the builder and the CP pages

Twenty-two sites, judged one at a time rather than replaced wholesale: a good half of this package's `??` is load-bearing and a `||` there would be the defect (`positions[key]?.x ?? cursor` must keep a legitimate `0`; `config[handle] ?? field.default ?? ''` must keep a stored `false` from a toggle and a `''` the user deliberately cleared; `is_test ?? null` must keep the `0` that means "non-test runs only"). The ones that were wrong:

- **`from_output ?? 'default'`** (nine sites) — the reading half of the defect above.
- **`data?.message ?? __('… failed.')`** (eleven sites) — a server `message: ""` rendered a blank error toast instead of the readable fallback. `Edit.vue` was already internally inconsistent about this: its validation branch used truthiness, its message branch did not.
- **`schema?.label ?? node.type`** — a node class whose `label()` returns `''` produced an empty name placeholder, while the heading directly above it fell back correctly.
- **`props.queue ?? 'default'`** — an empty `STATAMIC_AUTOMATIONS_QUEUE=` in `.env` rendered the Settings row blank, reading as if no queue were configured.

### Fixed — the config panel carried the previous node's state to the next one

`Edit.vue` mounts the panel with `v-if`, not `:key`, so selecting another node reuses the same component instance. Three defects followed from that, all of them invisible outside a browser:

- **The template picker wrote to the wrong field.** `emailFieldHandle` is the handle `onEmailTemplateSelected` calls `setField()` with; left pointing at the previous node's field, a template picked after a node switch landed on a handle the current node may not even have. The three modal refs now reset when the selected node changes.
- **Key-value rows leaked between nodes.** The field loop was keyed by `field.handle` alone, so two nodes with a `headers` field shared one `KeyValueField` — and that component keeps its rows locally, resyncing only when the incoming value differs from what it last emitted. Two empty maps serialise identically, so the resync was skipped: the previous node's half-typed rows stayed on screen and the next keystroke committed them onto the new node. The loop is now keyed by node *and* handle.
- **`KeyValueField` read its labels once.** `const keyLabel = props.keyLabel ?? __('Key')` was evaluated at setup, and ConfigPanel passes those labels conditionally, so the placeholders kept describing whichever field mounted the component first. Now computed.

### Notes

- **The Vue review found no live instance of the other class this QA round looked for** — a string method on a value the backend can also send as an array or null. All 34 call sites were traced to their receiver; the ones reading node config, run output and error payloads are guarded by an explicit `typeof … === 'string'` or a `String()` coercion, and `error_message` is only ever interpolated. The nearest thing to a hole is `NodeLibrary`'s `item.label.toLowerCase()`, which would throw while typing in the filter box if a third party registered a node with no `label` in its meta — not reachable through any first-party path, and left alone rather than patched into a finding.
- **Known and unchanged:** `SubAutomationAndAlertsTest > failure alerter logs and throttles` fails under MySQL on a foreign-key violation (`automation_id=999`, which SQLite does not enforce). Reproducible at v1.5.3.
- **Still open, reported rather than fixed:** history records one snapshot per keystroke, so ~100 characters of typing evicts every structural undo from the 100-entry stack; `useHistory.reset()` is exported and never called, so undo reaches past a save; duplicating a `branch`, `loop` or `parallel` node appends the copy on a hard-coded `default` output that those node types do not have, which `FlowValidator` then rejects; and `newNodeKey()` has no collision check against `unique(automation_id, node_key)`. All four live in graph mutations inlined in `Edit.vue`; pinning them wants those extracted into a composable first, in the style of the ones already tested.
- Suite: **359 passed (1269 assertions)** on SQLite, baseline 343. Vitest **11 passed**, `node:test` unchanged at **73**.

## 1.5.3 — 2026-07-28

### Fixed — Delay nodes saved before 1.5.2 stayed invalid

1.5.2 fixed the *writing* side of the config panel: a Delay node created from
then on carries `{"amount":1,"unit":"minutes"}` instead of a default that was
only ever painted on screen. It left the rows that were already on disk alone,
and said so in its notes. That is the part this release finishes.

A Delay node saved before 1.5.2 has an `amount` and no `unit`. It runs —
`DelayNode::execute()` falls back to minutes — but the editor marks it red with
"This field is required." under a select that visibly reads "Minutes", and the
only way out was to open the node and re-save it. On an install with a few
dozen automations that is busywork with no decision in it, which is exactly
what a migration is for.

The new migration writes `unit: minutes` into Delay node configs that have
none. `minutes` is not a preference — it is the value the node has been
behaving as all along, and a test pins the migration's constant to
`DelayNode::execute()` rather than to a comment, so the two cannot drift apart.
The migration touches only `type = 'delay'`, only rows without a usable `unit`,
decodes and re-encodes the rest of the config unchanged, and leaves
`updated_at` alone: this is a repair, and that column should keep answering
"when did a human last change this node?".

It deliberately has no `down()`. Reversing it would mean deleting `unit` from
Delay configs, and the migration cannot tell the rows it wrote from the ones a
user has since set to "hours" or "days" — a rollback would strip real settings
to restore a state whose only property was being broken.

**Multi-brand:** the migration goes through the query builder, not the
`AutomationNode` model. The model carries the fail-closed `HasBrand` scope, and
a migration runs with no brand in context — via the model it would have matched
zero rows and silently skipped every tenant. Brand isolation is a request-time
boundary; a migration runs beneath it, across the whole table, once. `brand_id`
is neither read nor written, and each row is completed from its own config.

**Flat-file installs are not covered.** With
`automations.storage.driver = flatfile` the nodes live in YAML and there is no
table to migrate; those still need the one-time re-save from the 1.5.2 notes.

### Changed — run timestamps now keep their milliseconds

`started_at` and `finished_at` on runs and node runs were whole-second
`timestamp` columns. Two nodes that ran 40 ms apart came back with the same
stored instant, and the only surviving evidence of the difference was
`duration_ms` — enough to render a duration, not enough to sort or correlate by
point in time. 1.5.2 noted this as a known limit; it is now lifted.

The column change alone would not have done it. Eloquent serialises every
date-castable attribute with the *connection's* format, which is
`Y-m-d H:i:s` on every driver Laravel ships, so the fraction was being dropped
in the model before the column was ever consulted. The four attributes now cast
through `MillisecondDateTime`, which writes `Y-m-d H:i:s.v` and reads both
shapes — rows written before this release parse unchanged. The cast is scoped
to those four attributes on purpose: setting `$dateFormat` on the model would
have been shorter, but it also applies to `created_at` / `updated_at`, which
are whole-second columns that MySQL would then round by up to half a second.

**SQLite is skipped by the migration, on purpose.** SQLite has no typed
datetime — Laravel maps `timestamp` and `timestamp(3)` both to `datetime`,
stored as text — so `->change()` there produces a column byte-for-byte
identical to the one it replaced. What it *does* do is rebuild the whole table:
create a temp table, copy every row, drop, rename, recreate the indexes. Paying
a full table copy on a growing table for a no-op is the wrong trade, so the
migration reports the skip instead of performing it. SQLite installs still get
millisecond timestamps; there the precision comes entirely from the string the
cast writes, which SQLite stores and returns verbatim.

**MySQL note for large installs.** Changing a `TIMESTAMP` column's
fractional-seconds precision cannot be done in place — MySQL rebuilds the table
with `ALGORITHM=COPY` and blocks writes for the duration. On a runs table of
any size, run this in a maintenance window or through
`pt-online-schema-change`. The 2038 limit of `TIMESTAMP` is unchanged; these
columns were already `timestamp` and stay that way.

### Testing

- The suite can now be pointed at a real MySQL server:
  `AUTOMATIONS_TEST_DB=mysql DB_HOST=… vendor/bin/pest`. Both new areas were
  verified on SQLite and on MySQL 8.4, because the two disagree about precisely
  the things this release touches — typed datetimes, fractional seconds, and
  what `->change()` does.
- One back-fill test is skipped on MySQL: a `json` column refuses to store
  malformed text, so the migration's "leave unparseable config alone" guard
  cannot be provoked there. It still matters on SQLite and on legacy text
  columns.

## 1.5.2 — 2026-07-27

### Fixed — a resumed run reported a negative duration

Three defects stacked into the CP showing `DURATION -652 ms` on every run with a delay:

- **The resume job restamped `started_at`.** `RunLogger::startRun()` is called again when `WorkflowRunner::resumeAfterNode()` picks a run back up after a Delay / Wait. It overwrote `started_at` with the resume moment, so the run's real origin — and with it the entire wait — was gone from the record. `started_at` is now stamped once, on the first start only.
- **`duration_ms` was computed backwards.** `$finished->diffInMilliseconds($started)` reads as "finish, then diff to start", and Carbon 3 returns a *signed* difference, so a well-ordered start/finish pair yielded a negative number. Carbon 2 returned the absolute value, which is why this only surfaced after the Carbon 3 bump. All duration arithmetic now goes through `RunLogger::elapsedMs()`, which computes in the natural direction and clamps at zero, so a stored duration can never be negative — not even when the wall clock steps backwards mid-run.
- **`duration_ms` is stored in an `unsignedInteger` column.** SQLite accepted the negative value and handed it straight back to the UI; MySQL in strict mode would have rejected the write instead. Either way the value was wrong at the source.

**What "duration" means for a waiting run:** `duration_ms` is **wall clock — first start to final finish, waiting time included.** A run that sits in a 3-day delay reports 3 days. This is the reading that matches what is stored (`finished_at - started_at`), and the one that answers the question the runs list is actually asked: "when did this finish relative to when it was triggered?" Pure compute time is not lost — it is the sum of the node durations, which the next item makes truthful for the first time.

### Fixed — every node run reported `0 ms`

`RunLogger::recordNodeRun()` set `started_at` and `finished_at` from two `now()` calls taken *after* the node had already executed, so the only interval it ever measured was the time to build the record itself. The answer to "is the node duration measured or just not displayed?" is that it was never measured. The runner now captures the start before executing the node and passes it in.

Note: `started_at` / `finished_at` are plain `timestamp` columns (whole-second precision), so a sub-second node still collapses to a single stored second. The millisecond value lives in `duration_ms`, which is computed before persisting — that is what the CP renders.

### Fixed — the Delay node was permanently "Invalid"

The `unit` field is required and declares a default of `minutes`. The config panel *rendered* that default (`config[handle] ?? field.default`), so "Minutes" was on screen, but a rendered fallback is not a model value: `config.unit` stayed undefined, inline validation flagged a missing required field, and the node stayed red under a visibly pre-filled select — reporting "This field is required." about a field that looked filled. Only re-picking the very same option wrote it into the model. Newly created nodes (and trigger replacements) now seed every schema-declared default into their config, so what the panel shows is what the model holds and what gets persisted.

Behaviour was never affected — `DelayNode::execute()` falls back to minutes — but the node is now green without user interaction, and `{"amount":1,"unit":"minutes"}` is what actually gets saved.

### Notes

- Existing saved nodes are not migrated. A Delay node saved before this release still has no `unit` in its config and will keep showing as invalid until it is opened and re-saved. It continues to run as minutes.
- `resources/dist/build` was already out of date with its source at 1.5.1, independently of these changes; this release ships a matching rebuild.

## 1.5.1 — 2026-07-27

### Fixed — `automations:run-scheduled` was left out of the 1.5.0 fix

Its `handle()` takes an injected service, and the transformation that added the `forEachBrand` call only matched parameter-less signatures. The command imported the trait and never called it — still blind under multi-brand.

## 1.5.0 — 2026-07-27

### Fixed — scheduled commands did nothing under multi-brand and reported success

- **`automations:run-due` never resumed a delayed step.** A scheduled run has no session and therefore no brand; the fail-closed scope hid every row, so the command reported "Dispatched 0" while a due job sat there indefinitely. Delays simply never continued. `automations:run-scheduled` and `automations:prune` had the same defect.
- All three now iterate the brands via `RunsForEachBrand` from `goldnead/statamic-brand-context` ^1.3, and each accepts `--brand=<handle|id>` to restrict. Single-brand installs are unaffected — the work runs once, in the ambient context.

### Notes

- Found in the hub QA run: `DB::table('automation_runs')->count()` returned 1 while `AutomationRun::count()` returned 0, with `multiBrand=true hasCurrent=false failMode=closed`.
- The silent shape of this failure is the dangerous part: nothing errors, nothing is logged, and the scheduler keeps reporting healthy runs forever.

All notable changes to **Statamic Automations** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.3] - 2026-07-02

### Fixed
- **LeadHub and Webhook Manager action nodes failed against real facades.**
  The adapters guarded every call with `method_exists()` on the configured
  facade class and then called it statically. Real Laravel facades (like
  `Goldnead\Leadhub\Facades\LeadHub`) proxy all calls through
  `__callStatic`, so `method_exists()` was always false and every action
  failed with e.g. "LeadHub facade does not implement createTask()" on real
  installs. The adapters now resolve the facade root instance via
  `getFacadeRoot()` when the configured class is a Laravel facade, and probe
  and call methods on that instance. Plain classes with real static methods
  and pre-built service objects keep working unchanged.

### Added
- Regression tests that exercise both adapters through a real
  `Illuminate\Support\Facades\Facade` subclass backed by a container-bound
  manager instance, alongside the historic plain-static-class fakes.

## [1.0.2] - 2026-07-02

### Fixed
- **CP list page row actions 404ed** (delete, enable/disable, duplicate,
  export JSON). The Automations index page passed the `…/api/automations`
  listing URL as `apiBase`, while the Vue page appends `/automations/{id}/…`
  itself — every row action therefore hit
  `…/api/automations/automations/{id}` and returned HTTP 404. The page now
  passes the API root (`…/api`), matching the builder (Edit) page. Affected
  both storage drivers; the `{automation}` route binding itself was fine and
  accepts ids and uuids for either driver.

### Added
- Regression tests that drive the index-page row actions exactly like the
  frontend does (render the Inertia page, build the URL from its real
  `apiBase`/`rows` props, send with XHR headers) for delete, enable/disable,
  duplicate and export, in both database and flat-file storage modes.

## [1.0.0] - 2026-06-30

First public release on the Statamic Marketplace.

### Editions & licensing
- **Free / Pro editions** declared via `extra.statamic.editions`. Pro features
  (the AI action, custom node registration) are gated through Statamic's native
  Marketplace licensing (`Addon::edition()`), with local `config`/`remote`
  modes kept as a self-hosted fallback.
- Commercial software license (replaces MIT).

### Added — feature set
- **Triggers:** manual, form submitted, entry published/saved/deleted, user
  registered, scheduled (cron via `automations:run-scheduled`), and a
  `webhook_received` bridge to Webhook Manager.
- **Actions:** send email, send webhook, add log entry, create/update entry,
  create user, set variable, call automation (sub-flows), and `ai_generate`
  (Anthropic Claude Messages API, **Pro**).
- **Control flow:** filter, branch, switch, stop, delay, wait-until, loop
  (for-each), parallel (fan-out/join), throttle/deduplicate.
- **Expressions:** `TokenResolver` pipe filters
  (`lower|upper|ucfirst|title|trim|slug|length|json|default|date`).
- **Reliability & ops:** overview dashboard (KPIs + trend + recent failures),
  throttled failure alerts, per-node retries + on-error-continue policy,
  node-by-node run logs with redaction.
- **Versioning** via Statamic Revisions (flat-file), with rollback; **audit
  log** with a native CP screen.
- **Storage drivers:** `database` (default) or `flat_file` (one YAML file per
  automation); runtime data always in the database.
- **Platform:** secrets store (`{{ secret.* }}`), i18n (English + German),
  per-node inline testing, importable template catalog.

### Changed — Marketplace-readiness pass (align with LeadHub)

Brought the addon in line with the sister LeadHub addon's launch-grade
conventions and fixed two Control-Panel launch blockers.

- **Statamic 6 Vite convention.** Switched `vite.config.js` to the official
  `@statamic/cms/vite-plugin` + `laravel-vite-plugin` (publicDirectory
  `resources/dist`), made `@statamic/cms` a `file:` npm dependency, and
  replaced the ServiceProvider's `$scripts`/`$stylesheets` with the `$vite`
  property. The compiled CP assets are now committed under
  `resources/dist/build/` and published automatically on install — no
  end-user build step.
- **CP routing launch blocker.** Routes are now registered via
  `$routes = ['cp' => ...]`, so Statamic mounts them under `/cp` with the
  `statamic.cp.` name prefix and CP auth middleware. The controllers and nav
  already used `cp_route('statamic-automations.*')`; the previous manual
  `loadRoutesFrom` registered bare names, which made every `cp_route()` throw
  and 500 the page.
- **Controller bug fixes** (caught by new CP smoke tests):
  `NodeRegistry::describeAll()` → `all()`, and the Settings page's
  `LicenseManager::isValid()` → `status()` check.
- **composer.json:** Statamic `^6`, `laravel/framework ^11|^12|^13`,
  `inertiajs/inertia-laravel`, Pest dev dependencies; dropped Statamic 5
  (the CP is Inertia/Vue 3 / `@statamic/cms`, which is v6-only).

### Added

- **Pest test harness** mirroring LeadHub: `TestCase` registers the real
  Statamic service provider, forces `bootAddon()`, mounts the real CP routes,
  and uses `RefreshDatabase` + real Statamic super users (the NoopAuth /
  `TestServiceProvider` test hacks are gone).
- **`CpRoutesTest`** renders every Inertia CP page and **`ApiSmokeTest`**
  exercises every JSON endpoint the Vue builder calls. **91 tests pass.**
- **`scripts/setup-playground.sh`** — builds a persistent, runnable Statamic 6
  playground with the addon wired in as a path repo; `.devcontainer`
  delegates to it.
- **`MARKETPLACE.md`** listing copy and `.gitattributes` dist-export rules.

### Fixed — Sprint 7 (full PHPUnit suite green, no skips)

After running the test suite end-to-end inside a real PHP+Composer
sandbox (with `statamic/cms` v6.18.0 actually installed), the
previously skipped HTTP API test could finally be diagnosed:

- `withoutMiddleware()` in `AutomationsApiTest::setUp` was disabling
  every middleware including `Illuminate\Routing\Middleware\SubstituteBindings`,
  so implicit route-model binding for `{automation}` silently returned
  an empty Eloquent instance with `id = NULL` — and the
  `WorkflowRunner::createRun` insert hit the `automation_id` FK.
- Replaced the previous alias-to-noop with a proper
  **middleware group** that wires both a no-op auth shim AND
  `SubstituteBindings`. Route-model binding now resolves to the real
  Automation row inside HTTP feature tests, the test passes, and the
  `markTestSkipped` is removed.
- New build dependency: a real PHP CLI environment with `statamic/cms`
  installed. The CI matrix already provides this; for local runs see
  the `statamic-6-phpunit-sandbox` skill recipe.

**Result**: 71 tests / 223 assertions / 0 failures / 0 errors / 0 skipped.

### Changed — Sprint 6 (Statamic 6 CP UI Patterns)

The CP frontend has been completely rewritten on top of **Statamic 6's
native Inertia.js + Vue 3 + Tailwind v4 stack**, following the official
[Statamic 6 CP UI Patterns](https://statamic.dev) skill. This is a UI
overhaul, not a feature change — the engine, public API, data model
and JSON endpoints are all unchanged.

#### Architecture

- **Inertia.js pages** registered through `Statamic.$inertia.register()`
  in `cp.js`. No more `data-automations-app` mounting — Statamic's
  Inertia plugin renders our pages inside the native CP layout.
- **All UI primitives** sourced from `@statamic/cms/ui`: `Header`,
  `Listing`, `PublishForm`, `Panel`, `Button`, `Switch`, `Badge`,
  `Alert`, `EmptyStateMenu`, `CodeEditor`, `Stack`, ... — no more
  custom `.sa-*` SCSS for buttons, cards, tables, toasts.
- **Tailwind v4** with the Statamic layer order
  (`base → addon-theme → addon-utilities → components → utilities → ui → ui-states`).
  Dark mode "for free" through Tailwind `dark:` variants.
- **`@statamic/cms/inertia`** for navigation: `<Link>`, `router.visit()`,
  `<Head>` (no more raw `<a href>` or `window.location`).
- **`@statamic/cms`-marked external in Vite** so the addon bundle
  doesn't ship a duplicate Statamic-UI library — uses whatever the
  host install ships with.

#### New CP page tree

| Page | Component |
|---|---|
| Automations list | `pages/Automations/Index.vue` (Listing) |
| Builder | `pages/Automations/Edit.vue` (Header + Vue Flow + Panel sidebars) |
| Runs list | `pages/Runs/Index.vue` (Listing + filters) |
| Run detail | `pages/Runs/Show.vue` (Panels + CodeEditor for context/IO) |
| Templates | `pages/Templates/Index.vue` (Panel cards) |
| Import | `pages/Import.vue` (drop zone + CodeEditor) |
| Settings | `pages/Settings/Show.vue` (read-only Panels) |

#### Backend changes

- **`Pages/*PageController`** classes: `AutomationsPageController`,
  `RunsPageController`, `TemplatesPageController`,
  `ImportPageController`, `SettingsPageController`. Each returns
  `Inertia::render('statamic-automations::Page', [...props])`.
- **GET routes** now hit Inertia controllers; the existing JSON CRUD
  / canvas / actions / runs / templates / settings routes remain
  under `/automations/api/*` and are consumed by the Vue pages via
  axios.
- **CP nav** uses the new route names (`statamic-automations.*`).
- **Asset loading** moved to Statamic's `protected $scripts` and
  `$stylesheets` properties on the AddonServiceProvider.

#### Removed

- `resources/views/cp/*.blade.php` — Inertia renders pages directly.
- All custom UI helper components: `EmptyState`, `LoadingSpinner`,
  `ErrorMessage`, `Toast`, `AutosaveIndicator`, custom `Field*`
  components, the old `useToast` composable, the axios `client.js`,
  `utils/uuid.js`. All replaced by `@statamic/cms/ui` equivalents.
- `resources/sass/automations.scss` — Tailwind v4 only now.

#### Vue Flow canvas

The canvas itself stays as a custom widget (no Statamic UI primitive
matches a node-graph builder). It's slimmed down and lives at
`resources/js/components/builder/` with five files: `Canvas`,
`NodeCard`, `NodeLibrary`, `ConfigPanel`, `ConditionBuilder`,
`RunLogPanel`. All wrapped by Statamic's `<Header>`, `<Panel>`,
`<Button>`, `<Switch>`, `<Stack>` for the surrounding chrome.

#### Migration impact for users

- Existing automations are unchanged; the data model is identical.
- After upgrading you must re-publish the assets:
  `php artisan vendor:publish --tag=statamic-automations-assets --force`.
- The `statamic-automations.*` route name prefix is new — anyone
  who reverse-route-resolved against the old `automations.*` names
  needs to update.

### Fixed — Sprint 5 (CI green-up)

After landing the GitHub Actions workflows the test matrix surfaced
several real bugs that the local sandbox couldn't catch (no PHP
available). Iteratively fixed:

- **Composer plugins blocked**: `pixelfear/composer-dist-plugin`
  (used by Statamic for its CP assets) and `php-http/discovery`
  weren't in the `allow-plugins` allowlist, so Composer 2.2+ refused
  to install them. Added explicit allow-plugins block.
- **Test bootstrap missed `bootAddon()`**: Statamic's
  `AddonServiceProvider` defers `bootAddon()` to a `Statamic::booted()`
  callback that Orchestra Testbench never fires. Introduced
  `tests/TestServiceProvider` that runs `bootAddon()` directly in
  `boot()` so registries / listeners / migrations are available in
  every test, including HTTP-dispatched ones.
- **`WorkflowRunner` resilience**: when callers passed the wrong node
  as the trigger (e.g. `$automation->nodes->first()` returning a
  non-trigger), the walker started from a non-trigger and ran in
  the wrong direction. The runner now verifies that the resolved
  start node is registered as `kind=trigger` and falls back to
  `findTriggerNode()` if not.
- **Pro gate in tests**: `features.custom_actions_requires_pro`
  defaulted to `true`, blocking tests that legitimately register
  custom triggers/actions. Disabled in `TestCase::defineEnvironment`.
- **`--prefer-lowest` matrix entry dropped**: the lowest-resolving
  Orchestra Testbench (9.0.1) is missing API-test plumbing
  (`$latestResponse` static property) that later 9.x releases added.
  Decision documented inline.
- **One HTTP API test parked**: `AutomationsApiTest::test_test_endpoint_runs_automation_in_test_mode`
  hits a route-model-binding edge case under Orchestra that doesn't
  exist in real Statamic. Marked `markTestSkipped` with TODO; the
  same engine path is fully exercised by the WorkflowRunnerTest and
  ManualTriggerTest feature test.

**End state**: 9/9 CI checks green — PHP 8.2 / 8.3 / 8.4 × Laravel
11 / 12 (PHPUnit), Frontend (Vite + Vue 3 build), Lint
(PHP syntax + composer validate).

### Added — CI / DX

- **GitHub Actions** workflows:
  - `tests.yml` runs PHPUnit across PHP 8.2 / 8.3 / 8.4 × Laravel 11 / 12
    plus a lowest-deps job (PHP 8.2 + Laravel 11 + `--prefer-lowest`).
  - `build.yml` runs `npm ci && npm run build`, verifies `resources/dist/cp.js`
    is non-empty, and uploads the built bundle as a 14-day artifact on
    pushes to `main`.
  - `lint.yml` checks PHP syntax across `src/`, `tests/`, `config/`,
    `database/`, `routes/` and validates `composer.json` strictly.
- README now ships **Tests / Build / Lint** status badges next to the
  package metadata.

### Added — Sprint 4 (Roadmap futures)

- **File-backed automations**: `automations:sync` Artisan command with
  `--from=files|db|auto`, `--strategy=db_wins|file_wins`, `--dry-run`
  and `--watch`. Auto-detects sync direction when one side is empty.
- **Run pruning**: `automations:prune` command honors
  `runs.prune_after_days` and `runs.keep_failed_runs_days`.
- **Partial-from-node retry**: `WorkflowRunner::executeFromNode()`
  resumes a run from a specific node. The CP `POST /node-runs/{id}/retry`
  endpoint dispatches a new `RetryFromNode` job and replays prior
  successful node outputs into the new run's context. The Run Detail
  screen exposes a "Retry from here" button per node-run.
- **Encrypted run logs**: `EncryptedJson` cast wraps the encrypted
  payload in a `{ "_encrypted": "…" }` JSON envelope so existing JSON
  columns stay valid. Toggle via `automations.runs.encrypt_context`
  (default off). Legacy unencrypted rows continue to read transparently.
- **License Manager**: `LicenseManager` service supports `config` and
  `remote` modes with caching. Pro gating is opt-in via
  `automations.features.custom_actions_requires_pro`. Built-in nodes
  (including LeadHub + Webhook Manager) are never gated.
  New endpoint: `GET /cp/automations/api/license/status`.
- **Autosave**: `useAutosave` composable with debounced writes (2s),
  topbar toggle and inline status indicator. Skipped silently for
  unsaved automations to keep handle generation a deliberate action.
- **Verified Statamic v6 events**: Listener mapping references the
  documented v5/v6 event class names with explanatory comments.
- **Docs**: `docs/file-sync.md`, `docs/licensing.md`, `docs/autosave.md`.

### Added — Sprint 3 (Phase I + J)

- **Templates**: two new built-in templates — _Lead Magnet Delivery_ and _Follow-up Reminder_.
- **Export / Import**:
  - `AutomationExporter` produces schema-versioned JSON.
  - `AutomationImporter` validates payloads up-front, detects missing integrations and unknown node types, and resolves handle conflicts automatically.
  - `AutomationFileSync` writes / reads `resources/automations/{handle}.json` for Git-based versioning.
  - New endpoints: `GET /automations/{id}/export`, `POST /automations/import`, `POST /automations/{id}/sync-to-file`, `GET /automations/{id}/sync-status`, `GET /automations/file-storage/list`.
  - Frontend: drag-and-drop import page, "Export" button in the builder, "Export" / "Import JSON" buttons on the list screen.
- **Polish**:
  - `EmptyState`, `LoadingSpinner`, `ErrorMessage`, `Toast` components for consistent UX.
  - `useToast` composable for global feedback.
  - Empty / loading / error states on every list and detail screen.
  - Toast notifications on save, validate, test, enable / disable, duplicate, delete, export, retry.
- **Documentation**:
  - Comprehensive marketplace README.
  - `docs/getting-started.md`, `docs/architecture.md`, `docs/extending.md`, `docs/api.md`.
  - This `CHANGELOG.md`.

## [Sprint 2]

### Added

- **Optional integrations** with `IntegrationDetector`: Webhook Manager and LeadHub adapters with conditional registration.
- **5 LeadHub triggers** (Lead Created, Status Changed, Tag Added, Note Added, Follow-up Due).
- **7 LeadHub actions** (Create or Update Lead, Change Status, Add/Remove Tag, Add Note, Create/Complete Follow-up).
- **Webhook Manager** action that delegates to the sister addon's destinations / signing / retry.
- **CP JSON API** — Automations CRUD, validate, enable / disable, duplicate, test, runs, templates, settings, node metadata, dynamic option sources.
- **Vue Flow canvas UI** — schema-driven config panel, token picker, condition builder, run log drawer, validation drawer.
- **Frontend build pipeline** with Vite + Vue 3 + Vue Flow.

## [Sprint 1]

### Added

- Package skeleton (composer, ServiceProvider, config, routes, permissions, navigation).
- 6 migrations for the flow-based data model.
- `AutomationContext`, `TokenResolver`, `ConditionEvaluator`, `FlowValidator`, `WorkflowRunner`, `NodeExecutor`, `RunLogger`.
- 3 built-in triggers (Manual, Form Submitted, Entry Published), 4 logic nodes (Filter, Branch, Stop, Delay), 3 actions (Send Email, Simple Webhook, Add Log Entry).
- Public Facade (`Automations::trigger / ::action / ::node`).
- Unit tests for engine + integration + template registries; feature test for full manual run.
