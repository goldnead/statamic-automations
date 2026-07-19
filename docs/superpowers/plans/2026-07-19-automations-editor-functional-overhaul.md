# Automations Editor Functional Overhaul — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Each task is dispatched to a fresh subagent that reads the real file contents, implements with TDD (backend) or build+browser-verify (frontend), then commits.

**Goal:** Make every Automations node actually work and be configurable through real, entity-wired pickers — fixing Loop/Switch runtime, empty dropdowns, narrow event coverage, and editor UX — then rebuild the welcome-series on branded templates.

**Architecture:** Backend engine (`src/Engine`) gains arbitrary-output routing + inline-loop subgraph execution; node config schemas declare `options_source`/`tokenable`/`depends_on`; a backend options endpoint serves entity lists; the Vue editor fetches those async, adds token insertion, dynamic multi-output handles, sidebar auto-hide and palette tabs. TDD for engine, build+browser verification for frontend.

**Tech Stack:** Laravel/Statamic 6 addon, PHP 8.2, Pest tests, Vue 3 + @vue-flow/core + @statamic/cms/ui, Vite (dist committed).

## Global Constraints

- Additiv + reversibel — keine bestehende Automation zerstören. `automation_nodes` + `automation_edges` (`from_node_key`/`from_output`/`to_node_key`) sind die Wahrheit.
- Keine neuen UI-Libs. Nur bestehendes Vue-Flow + `@statamic/cms/ui`. Alle Farben über Statamic-CP-Tokens / `--sa-color-*`.
- Nichts erfinden: Felder/Sektionen/Optionen nur zeigen, wenn echte Daten existieren.
- Copy: kein Spiegelstrich `–`/`—` in User-facing Strings; i18n konsistent (keine EN/DE-Mischung in einer View).
- Build nach jeder JS/Vue-Änderung: `npm run build` (im Addon-Repo). Testbench-Publish: `php artisan vendor:publish --tag=statamic-automations --force` bzw. der Testbench-Publish-Weg.
- Verify-Loop: Testbench `http://statamic-addon-testbench.test/cp` (`info@adriangoldner.com` / `password`) für schnelle Iteration; echte Staging-Site `staging.adriangoldner.com/cp` für Kollisions-/Realcheck.
- Arbeitsbranch im Addon-Repo: `feat/editor-functional-overhaul`. dist wird mitcommittet. UI-Vergleiche über Computed-Styles, nicht nur Screenshots.
- Deploy erst am Ende: neuer Tag `vX.Y.Z` + Staging-Bump im isolierten Worktree (siehe Handoff). Adrians uncommittete adriangoldner.com-WIP NIE anfassen.

---

## File Structure

**Backend (PHP)**
- `src/Engine/WorkflowRunner.php` — `walk()`, `nextNode()`: arbitrary-output routing + inline-loop subgraph driver.
- `src/Nodes/Logic/LoopNode.php` — inline `mode`, `loop`/`done` outputs, loop-context vars.
- `src/Nodes/Logic/SwitchNode.php` — `default` fallback output.
- `src/Nodes/Logic/ParallelNode.php` — declared branch outputs.
- `src/Http/Controllers/NodesController.php` — `options()`: all entity sources + params.
- `src/Nodes/**` — per-node `schema()`: `options_source`/`source_params`/`depends_on`/`tokenable`; `outputSchema()` on actions.
- `src/ServiceProvider.php` — `registerEventListeners()`: broader event map.
- `src/Listeners/*`, `src/Nodes/Triggers/*` — new trigger classes.
- `tests/**` — Pest tests for engine + dispatcher + options endpoint.

**Frontend (Vue)**
- `resources/js/composables/useAutoLayout.js` — `outputsFor()`: dynamic N outputs.
- `resources/js/components/builder/Canvas.vue` — `HANDLE_FRACTION` dynamic handle positioning.
- `resources/js/components/builder/ConfigPanel.vue` — async options, cascading, token inserter mount, real fieldtypes.
- `resources/js/components/builder/TokenInserter.vue` — **new**, `{{ }}` variable picker.
- `resources/js/composables/useNodeVariables.js` — **new**, upstream-variable computation via edge-walk.
- `resources/js/composables/useNodeOptions.js` — **new**, cached async options fetch.
- `resources/js/components/builder/NodeLibrary.vue` — tabs instead of accordion.
- `resources/js/pages/Automations/Edit.vue` — right sidebar auto-hide grid.

---

## Phase 1 — Runtime correctness (all control-flow nodes)

### Task 1.1: Arbitrary-output routing + inline-Loop subgraph (engine)

**Files:**
- Modify: `src/Engine/WorkflowRunner.php` (`walk()`, `nextNode()`)
- Modify: `src/Nodes/Logic/LoopNode.php`
- Test: `tests/Engine/LoopInlineTest.php` (create)

**Interfaces:**
- Consumes: `automation->edges` with `from_output`; `NodeExecutor::execute()` → `ActionResult{outputHandle, data}`.
- Produces: `LoopNode` emits outputs `loop` (body) and `done`; `WorkflowRunner` runs the `loop`-reachable subgraph once per item with loop-context vars, then continues from `done`. `LoopNode::outputs()` → `['loop','done']`. Loop context keys: `item`, `index`, `loop.count`, `loop.first`, `loop.last`.

- [ ] **Step 1: Read** `WorkflowRunner.php` (`walk()` ~264-333, `nextNode()` ~359-375) and `LoopNode.php` fully to learn the run-scope/token API and how `createRun`/`execute` currently work.
- [ ] **Step 2: Write failing test** `tests/Engine/LoopInlineTest.php`: build an automation `trigger(manual) → loop(items=[a,b,c], mode=inline) --loop--> add_log_entry({{item}}) ` with `loop --done--> add_log_entry("done")`. Run it. Assert the body log ran 3× with values a,b,c and the done log ran once, order preserved.
- [ ] **Step 3: Run** `vendor/bin/pest tests/Engine/LoopInlineTest.php` → expect FAIL (loop currently needs `config.automation`).
- [ ] **Step 4: Implement** in `LoopNode`: add `mode` (default `inline`), remove the hard `automation` requirement (keep `automation` branch only when `mode=automation`). In `WorkflowRunner::walk()`: when the current node is a loop in inline mode, resolve `items` (token → array; empty/non-array → straight to `done` output, log a notice), then for each item set loop-context vars in the run scope and drive the subgraph reachable from the `loop` edge to its natural end (a node with no outgoing edge for the taken output). After the last item, clear loop-context and route via the `done` output. Support nested loops/switches by scoping loop vars on a stack.
- [ ] **Step 5: Run** the test → expect PASS.
- [ ] **Step 6: Add nested test** `tests/Engine/LoopNestedTest.php`: loop over [x,y] whose body contains a switch; assert both routing + iteration scope correct. Run → PASS.
- [ ] **Step 7: Commit** `git add -A && git commit -m "feat(engine): inline loop subgraph execution + arbitrary output routing"`

### Task 1.2: Switch default fallback + Parallel fan-out (engine)

**Files:**
- Modify: `src/Nodes/Logic/SwitchNode.php`, `src/Nodes/Logic/ParallelNode.php`
- Modify: `src/Engine/WorkflowRunner.php` (if fan-out needs multi-next)
- Test: `tests/Engine/SwitchRoutingTest.php`, `tests/Engine/ParallelTest.php` (create)

**Interfaces:**
- Produces: `SwitchNode::outputs()` derives handles from `config.cases[].handle` plus a `default`. When no case matches → emit `default`. `ParallelNode::outputs()` → declared branch handles; runner fans out to ALL connected branch edges.

- [ ] **Step 1: Read** `SwitchNode.php`, `ParallelNode.php`, and `WorkflowRunner::nextNode()`.
- [ ] **Step 2: Write failing tests**: (a) switch with cases [`a`,`b`] + default, value not matching any → asserts `default` branch ran; value=`b` → `b` branch ran, others not. (b) parallel with 3 branches → all 3 ran.
- [ ] **Step 3: Run** both → expect FAIL.
- [ ] **Step 4: Implement** `default` fallback in `SwitchNode`; ensure `nextNode()` returns the `default` edge when no case-handle edge matches. Implement parallel fan-out (collect all edges whose `from_output` is in the parallel branch set, execute each).
- [ ] **Step 5: Run** → PASS.
- [ ] **Step 6: Commit** `git commit -am "feat(engine): switch default fallback + parallel fan-out"`

### Task 1.3: Dynamic N-output handles in editor (frontend)

**Files:**
- Modify: `resources/js/composables/useAutoLayout.js` (`outputsFor()` ~Z.39)
- Modify: `resources/js/components/builder/Canvas.vue` (`HANDLE_FRACTION` ~Z.115, handle rendering)
- Modify: `resources/js/components/builder/NodeCard.vue` (render N source handles + labels)

**Interfaces:**
- Consumes: node `config.cases`, node kind/handle.
- Produces: `outputsFor(node)` returns an ordered array of `{handle, label}`: `branch`→[true,false]; `switch`→cases + `default`; `loop`→[loop,done]; `parallel`→configured branches; else→[default]. Handles positioned evenly via a fraction helper `handleY(index, total)`.

- [ ] **Step 1: Read** `useAutoLayout.js`, `Canvas.vue`, `NodeCard.vue` to learn current handle rendering + edge-adder (`AdderNode`/`InsertableEdge`).
- [ ] **Step 2: Implement** `outputsFor()` to return the dynamic list above; replace `HANDLE_FRACTION` constant with `handleY(i,total) = (i+1)/(total+1)`; render one source `Handle` per output with its label pill; ensure `AdderNode`/`InsertableEdge` can start from any output handle (pass `from_output`).
- [ ] **Step 3: Build** `npm run build`; publish to testbench.
- [ ] **Step 4: Browser-verify** (claude-in-chrome): open an automation, add a `switch`, add 2 cases in its config, confirm 2 case handles + a `default` handle appear and each can connect to a downstream node; add a `loop`, confirm `loop`+`done` handles connect. Diff computed handle positions look even. Save + reload → edges persist (check `automation_edges.from_output`).
- [ ] **Step 5: Commit** `git commit -am "feat(editor): dynamic multi-output handles for switch/loop/parallel"`

### Task 1.4: Verify + fix remaining logic nodes

**Files:**
- Modify (as needed): `src/Nodes/Logic/DelayNode.php`, `WaitUntilNode.php`, `ThrottleNode.php`, `src/Nodes/Actions/SetVariableNode.php`, `CallAutomationNode.php`
- Test: `tests/Engine/LogicNodesSmokeTest.php` (create)

- [ ] **Step 1: Read** each of the 5 node classes; determine which actually implement behavior vs. stub.
- [ ] **Step 2: Write smoke tests** per node asserting its documented effect (delay schedules/marks a resume; wait_until blocks until condition; throttle limits; set_variable writes scope; call_automation invokes target). For any that are stubs, write the test as the spec of intended behavior.
- [ ] **Step 3: Run** → note which FAIL.
- [ ] **Step 4: Implement** fixes for failing nodes (minimal, matching existing patterns). If a node is intentionally deferred, mark it in the Node-Audit and log() the limitation rather than faking it.
- [ ] **Step 5: Run** → PASS (or documented-deferred).
- [ ] **Step 6: Commit** `git commit -am "fix(engine): verify+repair delay/wait_until/throttle/set_variable/call_automation"`

---

## Phase 2 — Config fields dynamically wired (all nodes)

### Task 2.1: Backend options endpoint — all entity sources

**Files:**
- Modify: `src/Http/Controllers/NodesController.php` (`options()`), `routes/cp.php:108`
- Test: `tests/Http/OptionsEndpointTest.php` (create)

**Interfaces:**
- Produces: `GET cp options/{source}` returns `[{value,label}]` for sources: `collections`, `entries` (param `collection`), `taxonomies`, `terms` (param `taxonomy`), `forms`, `users`, `roles`, `blueprints` (param `collection`), `assets` (param `container`), `asset_containers`, `sites`, `globals`, `automations`, `webhooks`. Unknown/absent addon (webhooks) → `[]` + `{note}` field, HTTP 200.

- [ ] **Step 1: Read** current `options()` + route, and how Statamic facades are used elsewhere in the addon (Collection::all(), Form::all(), User::all(), Role::all(), etc.).
- [ ] **Step 2: Write failing tests** seeding a collection+entry, a form, a user, a role; assert each source returns non-empty `{value,label}` and `entries?collection=X` filters correctly; `webhooks` returns `[]` when webhook-manager absent.
- [ ] **Step 3: Run** → FAIL.
- [ ] **Step 4: Implement** each source via Statamic facades; params from query; guard optional addons.
- [ ] **Step 5: Run** → PASS.
- [ ] **Step 6: Commit** `git commit -am "feat(options): full entity source list for node config pickers"`

### Task 2.2: ConfigPanel async options + cascading (frontend)

**Files:**
- Create: `resources/js/composables/useNodeOptions.js`
- Modify: `resources/js/components/builder/ConfigPanel.vue` (`fieldComponent()` ~184, `fieldProps()` ~200)

**Interfaces:**
- Consumes: field defs `{type:'select', options_source, source_params, depends_on}`.
- Produces: `useNodeOptions(source, params)` → reactive `{options, loading, error}`, cached per `source+params`. Fields with `options_source` render a `Select` populated async; a field with `depends_on: 'collection'` refetches when the referenced field's value changes.

- [ ] **Step 1: Read** `ConfigPanel.vue` fully (field loop, fieldComponent, fieldProps).
- [ ] **Step 2: Implement** `useNodeOptions.js` (fetch `cp/.../options/{source}` with params, cache Map, loading state) and wire `fieldProps()` so `options_source` selects get async options + loading; implement `depends_on` cascading (watch the parent field value → new params → refetch).
- [ ] **Step 3: Build** + publish.
- [ ] **Step 4: Browser-verify**: open `form_submitted` trigger → Form picker lists forms; `entry_saved` → Collection picker lists collections; a `create_entry`/`update_entry` node → pick collection then entry cascades; `call_automation` → automation picker; `send_webhook` → webhook picker (or empty+note). No empty dropdowns where data exists.
- [ ] **Step 5: Commit** `git commit -am "feat(editor): async + cascading option pickers in ConfigPanel"`

### Task 2.3: Token insertion (frontend)

**Files:**
- Create: `resources/js/components/builder/TokenInserter.vue`
- Create: `resources/js/composables/useNodeVariables.js`
- Modify: `resources/js/components/builder/ConfigPanel.vue` (mount inserter on `tokenable` text/textarea fields)

**Interfaces:**
- Consumes: current node id, `automation.nodes`+`edges`, backend `outputSchema()` per node (exposed in node describe payload).
- Produces: `useNodeVariables(nodeId)` → array of `{token:'{{...}}', label, source}` from all upstream nodes (edge-walk backward). `TokenInserter` renders a `{{ }}` dropdown that inserts the token at the caret of the bound field.

- [ ] **Step 1: Read** how node describe/schema payload reaches the frontend (does it include `outputSchema`? if not, add it in the describe payload — coordinate with Task 2.4).
- [ ] **Step 2: Implement** `useNodeVariables` (backward edge-walk collecting upstream output schemas) + `TokenInserter.vue`; mount on fields flagged `tokenable`.
- [ ] **Step 3: Build** + publish.
- [ ] **Step 4: Browser-verify**: on a `send_email` node downstream of an `entry_saved` trigger, the inserter lists entry variables; inserting `{{entry.title}}` writes into the field at caret.
- [ ] **Step 5: Commit** `git commit -am "feat(editor): token insertion from upstream node variables"`

### Task 2.4: Per-node schema pass + action outputSchema (backend)

**Files:**
- Modify: every node under `src/Nodes/**` per the Node-Audit (spec §4)
- Test: `tests/Nodes/SchemaShapeTest.php` (create)

**Interfaces:**
- Produces: each node `schema()` uses `options_source`/`source_params`/`depends_on`/`tokenable` instead of raw text where an entity/variable is meant; each action defines `outputSchema()` describing the vars it exposes downstream; describe payload includes `outputSchema`.

- [ ] **Step 1:** Go node-by-node through the audit table (triggers, logic, actions). For each, read its `schema()` and set the correct field types/sources (Collection→`collections`, Form→`forms`, Entry→`entries`+`depends_on:collection`, Role→`roles`, Template→et_templates source, Webhook→`webhooks`, Automation→`automations`); flag freetext-with-tokens fields `tokenable`.
- [ ] **Step 2:** Add `outputSchema()` to actions lacking one (`send_email`, `create_entry`, `update_entry`, `create_user`, `ai_generate`, `send_webhook`).
- [ ] **Step 3: Write** `SchemaShapeTest` asserting: no node still declares a raw text field where the audit demands a picker; every trigger+action returns a non-empty `outputSchema()` where applicable.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Real-fieldtypes:** in `ConfigPanel.vue` map `key_value`→Statamic key-value field, `integer`→number input, `condition_list`→ConditionBuilder, `data_reference`→appropriate input (no more raw Textarea fallback). Build + browser-verify one of each.
- [ ] **Step 6: Commit** `git commit -am "feat(nodes): entity-wired schemas + action output schemas + real fieldtypes"`

---

## Phase 3 — Broader Statamic event coverage

### Task 3.1: Entry + term + global + nav triggers

**Files:**
- Modify: `src/ServiceProvider.php` (`registerEventListeners()` ~367-416)
- Create: trigger classes in `src/Nodes/Triggers/` (EntryCreated, EntrySaving, EntryUnpublished, TermSaved, TermDeleted, GlobalSaved, NavSaved) following existing `entry_saved` pattern
- Test: `tests/Triggers/EventCoverageTest.php` (create)

**Interfaces:**
- Produces: each trigger has `handle`, `matches()` (with optional collection/taxonomy constraint), `buildContext()`, `outputSchema()`. Event→handle entries added to the generic map; dispatched via `TriggerDispatcher::dispatch()`.

- [ ] **Step 1: Read** `HandleEntryPublished`, `entry_saved` trigger, and the generic map to copy the pattern.
- [ ] **Step 2: Write failing tests**: firing each Statamic event dispatches a matching enabled automation exactly once; constraint filters by collection/taxonomy.
- [ ] **Step 3: Run** → FAIL.
- [ ] **Step 4: Implement** trigger classes + map entries; register in `registerBuiltInNodes()`.
- [ ] **Step 5: Run** → PASS.
- [ ] **Step 6: Commit** `git commit -am "feat(triggers): entry/term/global/nav event coverage"`

### Task 3.2: User + form + asset triggers

**Files:**
- Modify: `src/ServiceProvider.php`
- Create: trigger classes (UserSaved, UserDeleted, SubmissionCreated, AssetUploaded, AssetSaved, AssetDeleted)
- Test: extend `tests/Triggers/EventCoverageTest.php`

- [ ] **Step 1: Write failing tests** for user/submission/asset events → dispatch.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** classes + map + registration (form_submitted already exists — add SubmissionCreated distinctly).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `git commit -am "feat(triggers): user/form/asset event coverage"`

---

## Phase 4 — Editor UX

### Task 4.1: Right sidebar auto-hide

**Files:**
- Modify: `resources/js/pages/Automations/Edit.vue` (grid), `resources/js/components/builder/ConfigPanel.vue`

**Interfaces:**
- Produces: when `selectedNode == null`, the right column collapses (grid `360px`→`0`, canvas fills); selecting a node opens it; an X in the panel deselects/closes.

- [ ] **Step 1: Read** `Edit.vue` grid + selection state.
- [ ] **Step 2: Implement** conditional grid template (`grid-cols-[max-content_1fr_360px]` ↔ `[max-content_1fr_0]`) + close (X) button emitting deselect. Smooth, no layout jump; unmount cleanly on route change (no `isolate` regression).
- [ ] **Step 3: Build** + publish.
- [ ] **Step 4: Browser-verify**: no selection → no right panel, canvas wide; click node → panel opens; X → closes. Light + dark.
- [ ] **Step 5: Commit** `git commit -am "feat(editor): auto-hide detail sidebar when no node selected"`

### Task 4.2: Left palette as tabs

**Files:**
- Modify: `resources/js/components/builder/NodeLibrary.vue`

**Interfaces:**
- Produces: tabs `Triggers | Logic | Actions` (using `@statamic/cms/ui` tab/segmented control) replacing accordion; one search input filters across the active/all tabs; per-tab node list.

- [ ] **Step 1: Read** `NodeLibrary.vue` (accordion `open`/`toggle`, groups, search).
- [ ] **Step 2: Implement** tabbed layout; keep search (filters visible list); remove accordion state.
- [ ] **Step 3: Build** + publish.
- [ ] **Step 4: Browser-verify**: three tabs switch node lists; search filters; add-node still works from each tab. Light + dark.
- [ ] **Step 5: Commit** `git commit -am "feat(editor): node palette as tabs instead of accordion"`

---

## Phase 5 — welcome-series rebuild (staging)

### Task 5.1: Convert welcome-series to branded et_templates + Addon node

**Files:**
- Data on staging DB only (no addon code): `automation_nodes`/`automation_edges` for `welcome-series`; `et_templates` entries.

- [ ] **Step 1: Inspect** the live `welcome-series` on staging via tinker (heredoc): dump its nodes/edges + each "Send Branded Email" node's referenced host `email_templates` (subject/content/action_text/action_url/outro_lines/label).
- [ ] **Step 2: Create** one branded `et_templates` entry per welcome mail (Bard body from the host template content; branding via resolver `emails.layout`). Reversible — do not delete host templates.
- [ ] **Step 3: Rewire** each node from host `SendTemplateEmailAction` ("Send Branded Email") to Addon `SendEmailAction` ("Send Email Notification") pointing at the new `et_templates` entry. Keep original node keys/order; keep edges. Do NOT blind-delete originals — duplicate+swap, verify, then remove old.
- [ ] **Step 4: Browser-verify** on staging: open welcome-series in editor → nodes show et_templates picker filled; trigger a test run → received mail is branded (header/logo/footer from `company` global). Confirm timings/order unchanged unless Adrian specified.
- [ ] **Step 5:** Document the change in the handoff; leave a rollback note (original host-template node config captured in Step 1 dump).

---

## Deploy (after all phases green on testbench + verified on staging)

- [ ] Bump addon version, `npm run build`, commit dist.
- [ ] Push `feat/editor-functional-overhaul` → open PR → merge to `main`.
- [ ] Tag: `gh api repos/goldnead/statamic-automations/git/refs -f ref="refs/tags/vX.Y.Z" -f sha=$(gh api repos/goldnead/statamic-automations/commits/main --jq .sha)`.
- [ ] Staging bump in isolated worktree (per Handoff §3): `composer update goldnead/statamic-automations` in `/tmp/agdc-x` off `origin/staging`, commit lock, push `origin/staging`, remove worktree. NEVER touch Adrian's adriangoldner.com WIP.
- [ ] Deploy check: `ssh root@157.90.224.18 'git -C /opt/adriangoldner-staging rev-parse --short HEAD; systemctl is-active agdc-staging-deploy.service'`.
- [ ] Final browser pass on staging: switch routes, loop iterates, pickers populated, tabs + sidebar, welcome-series branded.

---

## Self-Review (done at write time)

- **Spec coverage:** Phase 1↔runtime (loop/switch/parallel/rest), Phase 2↔config wiring+tokens+fieldtypes+options endpoint, Phase 3↔events, Phase 4↔sidebar+tabs, Phase 5↔welcome-series. Node-Audit (spec §4) executed in Tasks 1.4/2.2/2.4. All spec sections mapped.
- **Type/name consistency:** `outputsFor()` returns `{handle,label}` (Task 1.3) consumed by Canvas/NodeCard; `useNodeOptions(source,params)` (2.2) matches options endpoint sources (2.1); `outputSchema()` produced in 2.4 consumed by `useNodeVariables` (2.3); loop outputs `loop`/`done` consistent between 1.1 and 1.3.
- **Placeholders:** none — each task names exact files/methods, test intent, and browser-verify criteria. Executor reads real file contents (cost-saving orchestration) rather than inline full-file dumps.
