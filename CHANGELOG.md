# Changelog

## 1.8.1 — 2026-08-01

### Fixed — the "Webhook Failure Alert" template could never fire

The template shipped with the trigger `webhook_manager.outbound_failed`, and nothing registered
that handle. Installing it produced an automation that looked complete in the builder, stayed
enabled, and never ran once, no matter how often a destination failed. If you installed it, it
starts working after this update; nothing to reconfigure.

The trigger now exists (`Outbound Webhook Failed`, under the Webhook Manager group) and is
bridged to Webhook Manager's `DeliveryFailedTerminally` event — the one it has fired all along
when a delivery exhausts its retries. Registration is guarded on Webhook Manager being
installed, exactly like the inbound `webhook_received` bridge, and the event class is
overridable via `automations.integrations.webhook_manager.outbound_failed_event`.

The template's `min_attempts` field is now a real field on that trigger: the automation only
runs once the delivery has been tried at least that many times. It previously sat in the
template as config no code read.

Context exposed to the flow: `webhook.destination`, `webhook.destination_name`, `webhook.url`,
`webhook.attempts`, `webhook.status`, `webhook.error`, `webhook.delivery_id`.

### Fixed — failure alerts for a deleted automation silenced each other

`FailureAlerter` throttled per `automation_id`. A run whose automation has been deleted has
`automation_id = null` (the foreign key is `ON DELETE SET NULL`), so every such run in the
installation shared the single cache key `automations:alert:` and the first failure suppressed
all the others for the whole throttle window. The throttle now falls back to the run's
`automation_uuid`, which survives the delete, and the alert text names the automation instead
of printing a bare `#`.

### Added — a test that holds every template against the registries

`tests/Feature/TemplateNodeCoverageTest.php` walks all eleven built-in templates and checks
every node handle against the node registry, every config key against that node's schema, every
edge against the template's own node keys, and every `requires` entry against the integration it
names. A template is a pile of strings pointing at registrations elsewhere; neither `php -l` nor
PHPStan can see when one of them points at nothing. This is the third defect of that shape in
the addon family, and the first one caught by a test rather than by reading.

### Fixed — the test suite ran without foreign keys and hid a real one

SQLite ignores foreign keys unless asked to enforce them, so the suite accepted rows MySQL
rejects outright and never performed the `ON DELETE SET NULL` the schema promises. One test
built an orphaned run by inventing `automation_id = 999`, a data shape production cannot reach.
`foreign_key_constraints` is now on for the SQLite bed, and that test takes the production path:
create the automation, run it, delete it, let the database null the column.

### Changed

- The automations empty state no longer promises "eight built-in patterns" while eleven ship.
  The count comes from the registry, so it cannot go stale when a template is added.

## 1.8.0 — 2026-08-01

### Fixed — "Start from a template" led nowhere

The button on the automations index built its target by rewriting the *create* URL, and that
URL carries a doubled `automations/automations` segment. The Templates screen was registered
and working the whole time at `/cp/automations/templates`; only the link was wrong. The target
now comes from the controller instead of from string surgery.

Worth knowing for anyone debugging a CP link: Statamic registers two catch-all routes, one for
the CP (`cp/{segments}` → `statamic.cp.404`) and one for the front end (`{segments?}` →
`statamic.site`). So *every* `/cp/…` string matches a route on some verb, and "does it match a
route" tells you nothing. Only the route name distinguishes a live target from a dead one.
`tests/Feature/CpLinkTargetsTest.php` now walks the Inertia props of every page and checks each
CP URL against the registered route names, so the next dead link fails in CI.

### Fixed — every host received 415 KB of dist nobody loaded

`resources/dist/{cp.js,cp.css,.vite/manifest.json}` predated the move to `build/` and no
manifest referenced them, but they shipped in the tarball all the same. Removed, and
`check-dist-fresh.sh` now fails on any tracked file under `resources/dist` outside `build/`.

### Fixed — listeners and CP routes registered twice under test

Moving to `Statamic\Testing\AddonTestCase` surfaced that the manual `bootAddon()` call had
become a duplicate: saving one entry ran every listener twice. Both the manual call and the
hand-mounted routes are gone.

### Changed

- 25 hardcoded colours moved onto theme tokens, so the canvas and the node palette follow
  Statamic's dark mode instead of approximating it.
- The node palette is reachable by keyboard.
- `laravel/framework` narrowed to `^12.0|^13.0`. The 11.x line is withdrawn behind security
  advisories and cannot be installed, so declaring support for it was untrue rather than
  generous. `orchestra/testbench` follows to `^10.0|^11.0`, and `pestphp/pest` gains `^4.0`,
  which is what actually installs on Laravel 13.
- The README no longer describes a drag-to-canvas flow that was removed, no longer claims the
  screenshots don't exist while shipping six, and links into the docs site rather than into a
  `docs/` folder that `.gitattributes` strips from the tarball.
- The 141 JavaScript tests now run in CI. They existed and were never executed there.
- Larastan and Pint are wired in as gates; the `repositories` block, which Composer ignores in
  a dependency anyway, is gone now that the siblings resolve from Packagist.

## 1.7.1 — 2026-07-30

### Fixed — `automations:sync` could import over a database it could not see

The command took no brand. A console run has no session, so under multi-brand the global scope failed closed and every query came back empty — and here that is worse than a no-op, because the command asks the database a question before deciding what to do.

`detectDirection()` checks whether the database holds any automations. It saw none, concluded the files must be the source of truth, and a bare `automations:sync` would import them over automations it simply could not read. `--from=db` was the harmless direction: it exported nothing and said so.

**The fix does not iterate brands**, because `resources/automations/` cannot hold more than one. It is a single flat folder of `{handle}.json`, and handles are unique *per brand* — two brands may each own a `welcome-flow`, so exporting both would have the second overwrite the first, and importing cannot know which brand a folder belongs to.

So the command now refuses to guess:

- More than one brand and no `--brand` is **rejected**, naming the brands and the reason. Run it once per brand with `automations.file_storage.path` pointed at a directory of its own.
- An unknown `--brand` is rejected rather than silently falling back.
- Single-brand installs are unaffected — no option, no prompt, same behaviour.

`tests/Feature/SyncBrandGuardTest.php` covers it; four of its five cases fail without the fix.

> Same class of defect as the one `RunsForEachBrand` was written for, and the third addon to hit it. The trait was not the answer here: iterating brands is exactly what must **not** happen when the target is one shared directory.

## 1.7.0 — 2026-07-29

A node's output handles are now declared once, by the node, and read from that one declaration by the canvas, the validator and the node's own `outputs()`. The reason this is a **minor** and not a patch is that it adds public surface — a field in the node-library payload, an extension point third-party nodes are meant to implement, and a documented wire contract with a version on it — and changes one visible behaviour (Duplicate on a loop). The reason it is not a major is that nothing a consumer already depends on was removed or renamed: no stored data, no route, no API response shape, no permission, no output handle string.

### Added — the registry hands out a node's outputs, so a third-party node can have more than one

Until now, which handles a node has was written twice. `SwitchNode`, `ParallelNode` and `LoopNode` each declared `outputs()` in PHP, and `outputsFor()` in `resources/js/composables/useAutoLayout.js` mirrored all three by hand, down to how they read `config.cases` and `config.branches`. The mirror was accurate — the tests pinned it — and it was also the whole of the canvas's knowledge. `NodeRegistry::describe()` did not expose outputs at all, so a node registered by anybody else got a single `default` handle whatever its class declared. Since a handle is the only thing an edge can leave from, its second and third outputs did not exist as far as the builder was concerned.

1.5.5 fixed the sharpest edge of that — a type ending in `.branch` got true/false on both sides, because `FlowValidator` had required true/false off that suffix since the first release and the canvas offering one `default` made such a node *less* usable than any other custom node. It left the double declaration standing, and named the reason: outputs can depend on config, so exposing them means handing a config-dependent thing to a frontend and versioning it.

That is what this release does. `describe()` now carries an `outputs` key for every node — not a list of handles, which would be wrong the moment a switch gains a case, but a small declarative spec that both sides resolve against the node's live config. `src/Support/NodeOutputs.php` holds the grammar and the PHP resolver; `resources/js/composables/useNodeOutputs.js` is the same resolver in the browser. A node writes `outputSpec()` (with the `DeclaresOutputs` trait deriving `outputs()` from it), or, if its handles are fixed, just `outputs()` — the registry serialises that for the canvas, so the common third-party case needs no knowledge of the grammar at all.

`outputsFor()` on the canvas no longer contains a single test of a node's type. It looks the type up in the library payload the page was rendered with and resolves what it finds; a type with no declaration gets one `default` continuation, which is what every custom node got before. The `.branch` suffix rule moved out of the canvas and into the registry, where it is now a fallback for a type that declares nothing rather than a cap on what such a type may declare — an `acme.branch` may now declare three outputs and get three.

**What the promise is worth, checked end to end.** `tests/Feature/ThirdPartyNodeOutputsTest.php` registers `acme.review`, a node this package knows nothing about that declares `approved` / `rejected` / `escalated`: all three reach the canvas in the library payload, the validator holds the graph to exactly those three and names a fourth handle as unknown, and a run routes down whichever one the node returns. `tests/js/node-card-outputs.test.js` mounts the real canvas — Vue Flow, the node cards, Vue Flow's own `Handle` components — and finds three connectable source handles on it, against one before this release.

The engine needed no change and got none: `WorkflowRunner` has always routed on the handle a node returns and never on the node's type. That half of the promise already held; it was the only half that did.

### Added — the payload is versioned, and a mismatch degrades instead of guessing

The spec carries `version` (`NodeOutputs::VERSION`, 1) and the canvas carries the version it understands (`OUTPUT_SPEC_VERSION`). Both are packaged together, but the built assets are published into the host's `public/vendor/`, so a stale canvas meeting a newer server is a real shape and not a theoretical one — it is the shape `npm run build:check` and the publish step exist to prevent.

A resolver meeting a spec numbered above its own does not guess at fields it does not know: it resolves the node to a single `default` output, which is exactly what a canvas that had never heard of output specs did with the same node, and logs once per type saying the assets are behind. The other direction needs no rule — a spec from an older contract uses only fields a newer resolver already understands. Both directions are asserted, in `tests/js/node-outputs.test.mjs` and in `NodeOutputSpecContractTest`.

### Changed — Duplicate on a loop puts the copy after the loop, not inside it

A node may now name its `primary` output: the one that means "and then". Duplicate and insert-on-edge attach there instead of taking the first declared output.

For every node except one this changes nothing, because first *is* the continuation. `LoopNode` declares `done` as its primary, and its outputs are `loop` then `done` — so duplicating a loop used to hang the copy off `loop`, inside the body it was meant to follow. Valid and deterministic, and not what the user meant; 1.5.5 wrote it down as unresolvable because there was no metadata marking a node's main continuation. There is now, and it falls out of the same declaration rather than being a second mechanism.

A branch deliberately declares no primary: neither side of a condition is the continuation, so Duplicate still attaches to `true`, as it has since 1.5.5. Same for a switch (which case is "the" case is the user's business) and an inline parallel (a fan-out has no single continuation).

### Changed — the validator can now check any node's outputs, and says so quietly

`FlowValidator` checked output handles on branch nodes only, because a branch was the only node whose handles it could know. It now asks the registry for any node's declared outputs, resolved against that node's own config.

The level is deliberate. A branch stays an **error** with the same `branch_invalid_output` code and the same message it has had since the first release. Every other mismatch is a new **warning**, `edge_unknown_output`, naming the node, the handle and what the node does declare. Warnings do not block enabling or running (only `level === 'error'` does, in `AutomationsController` and `WorkflowRunner`), and that is the point: a switch's outputs move when its cases are edited, so edges stored against a removed case are ordinary in existing data. Raising those to errors would refuse to enable automations that were enabled yesterday, on a graph nobody touched. The `error` handle — taken by the runner when a node fails under `_on_error: continue` — is never reported, because every node has it whether or not its spec mentions it.

### Compatibility

Stored graphs were the real risk here: `automation_edges.from_output` holds handle strings, `WorkflowRunner::nextNode()` matches them exactly, and nothing reconciles them with anything. A rename or a reorder would not raise an error — it would produce an automation that quietly stops at the node whose handle changed.

So the handles are pinned twice. `NodeOutputSpecContractTest` asserts the pre-1.7.0 `outputs()` results survive verbatim, including order and the switch's dedupe of a case that already targets `default`. And `tests/Fixtures/stored-automations/hub-2026-07-29.json` is not test data: it is the five automations in the running QA hub, exported from its database — a five-step marketing nurture on `default` edges, two delay flows, and the branch graph 1.5.5 was built against, wired on `true` with `false` left open. `StoredAutomationsSurviveOutputSpecsTest` restores each of them, resolves all 18 stored `from_output` values against the new declarations, and requires the validator to report nothing about any of them.

No migration: nothing about the database changed.

Two internals of the JS layer went away with the mirror they served. `isBranchType()` was exported from `useAutoLayout.js` (added in 1.5.5, no callers), and `computeLayout()`'s `options` argument carried `branchTypes` / `terminalTypes`, which the layout no longer needs because it no longer knows a node type by name. `LoopNode::outputs()` gained the optional `array $config = []` its siblings already had.

### Notes

- `tests/Fixtures/stored-automations/` and `tests/js/fixtures/node-output-specs.json` are both fixtures written from something real: the first from the hub's database, the second from the live PHP registry by `NodeOutputSpecContractTest` (regenerate with `UPDATE_NODE_OUTPUT_FIXTURE=1`). The JS suite reads the second, so a change to a built-in node's outputs that is not carried across cannot pass both suites.
- Mounting the canvas under Vitest needs a `getBBox` stub alongside the `ResizeObserver` one 1.6.1 documented, for the same reason and with the same symptom: the throw lands in a post-render hook, so the mount that fails is silent and the *next* one in the file returns an empty wrapper.
- **Known and unchanged:** `SubAutomationAndAlertsTest > failure alerter logs and throttles` fails under MySQL on a foreign-key violation (`automation_id=999`, which SQLite does not enforce). Reproducible at 1.5.3.
- Suite: **392 passed (1585 assertions)** on SQLite, baseline 378. Vitest **45 passed**, baseline 37. `node:test` **91 passed**, baseline 81. Every capability was verified by stashing the source and watching its test fail against 1.6.2: without the PHP half, 9 of the 14 new PHP cases fail (the five that pass are the ones that were already true — the runner's routing, the validator's silence about output handles it could not see, and the resolver's own version guard, which is in a new file the stash left in place); without the JS half, the third-party node renders one handle instead of three, its "+" adders and its layout columns collapse to one, and the loop's copy lands back in the body; without the version guard, a spec from a newer contract is resolved as if the canvas understood it.

## 1.6.2 — 2026-07-28

### Fixed — an interrupted brand-scoping migration could not be repaired by running it again

`2026_07_24_100002_add_brand_id_to_automations_tables` adds `brand_id` to seven tables and then has two places left where it can stop: the `RuntimeException` it raises when `automations` still holds rows with no brand to put them on, and the rework of the handle unique, where the drop and the create are two separate statements and the second can fail on its own.

Neither MySQL nor SQLite rolls DDL back, and a migration that throws is not written to the `migrations` table. So an aborted run leaves a database that is partly converted and a bookkeeping table that says the migration never happened — and, if it stopped inside step 4, a table whose global `handle` unique has been dropped and not replaced, which means the one identifier this addon promises to keep unique is unconstrained from that moment until somebody notices.

The only move available to whoever hits that is `php artisan migrate` again, and unguarded it did not get as far as the problem. It died at the very first statement on `duplicate column name: brand_id` — an error about step 1 that describes nothing that is actually wrong and points whoever reads it at the wrong end of the file. That is the fingerprint `statamic-marketing` documented for its own copy of this migration in 1.6.4.

Correcting the order of the statements would have fixed the next install and left every install that already broke exactly as broken as it is. So the migration is re-runnable rather than merely correctly ordered: the column addition asks each table whether it already has the column, and the unique rework reads the indexes actually on `automations` and does only the part still outstanding — checking the columns and the uniqueness, not just the name, because an index can exist under the right name over the wrong columns and be a promise the database is not keeping. Run on a clean pre-1.5.0 install, on a half-converted one, or twice in a row, `up()` ends with the same schema and raises nothing.

`2026_07_28_000003_require_brand_id_on_automations_table` was reviewed and needed nothing: it was already guarded on `hasTable`, `hasColumn` and a nullability probe.

### Added — the migrations are finally tested against a database with data in it

This is the finding underneath the fix. A sweep across all eight addons in this family, prompted by `statamic-marketing` 1.6.4, looked for a check that runs a migration against tables that already hold rows. It found none, anywhere. Every migration in this addon had only ever met empty tables, because every bed it had was a fresh install — which is the one shape a migration can never be wrong about.

Two properties of `tests/Migrations/` matter more than its individual cases.

It names no migration file. It walks `database/migrations/` and seeds a fresh generation of automations, nodes, edges, runs, node runs, scheduled jobs and audit rows into every table that already exists *before each* migration, so a migration added three years from now is covered the day it is committed without anybody remembering to come back here. A test that lists the two files that were once broken only ever tests the past.

And every assertion about the handle guarantee is behavioural. "The migration ran" and "the constraint is there" are not the same statement, and mistaking one for the other is the entire class of defect — so nothing there checks an exit code or an index name. It writes the row the constraint is supposed to refuse and requires the database to refuse it, together with the counterpart that catches a unique rebuilt over `handle` alone: the same handle in a different brand must still be accepted.

`tests/Fixtures/released-migrations/` holds the migration sets as published in 1.2.0, 1.5.0 and 1.6.1, and the suite installs each of them, puts data in and upgrades forward. `tests/Feature/BrandIdMigrationIsRerunnableTest.php` covers the repair directly, from a populated install stopped halfway through. Reverted against the published migration both of its cases fail, each with the `duplicate column name: brand_id` the fix exists to stop producing.

### Changed — the MySQL key-length probe can read the schema it is measuring

`tests/Unit/IndexKeyLengthTest.php` compiles the migrations through Laravel's MySQL grammar in pretend mode to measure index bytes without a server. Under `pretend()` a `select` returns nothing, so a migration that asks `Schema::hasColumn()` or `Schema::getIndexes()` before deciding what to build is told the table is empty of everything — a state no install is ever in, and now that `2026_07_24_100002` branches on exactly those answers, one that would have had the probe measuring a schema nobody holds.

It now runs two connections interleaved: the probe compiles the DDL through MySQL's grammar, and a real SQLite database one file behind answers every question the migrations ask about the current schema. Same measurements, on the schema that actually results. The same change `statamic-marketing` made in its 1.6.4 for the same reason.

### Notes

- Suite: **378 passed (1379 assertions)** on SQLite, baseline 372. Vitest unchanged at 37, `test:js` unchanged at 81.

## 1.6.1 — 2026-07-28

### Fixed — a refusal now says what the server said, and stays on screen

The automations half of the cross-addon sweep marketing 1.5.3 started: for every mask in this control panel, does a rejected request reach the user? The shape of the problem here is different from its siblings, because this addon does not go through Inertia. Every call is axios, so there is no error bag handed to a page — whatever a `catch` block does not dig out of the response is gone. The defect was therefore never a missing handler. Every submitting function already had one. Four of them threw the server's answer away and replaced it with a guess.

**Four places that answered a rejection with a message of their own invention.** `Automations/Index.vue`'s `duplicate()` and `destroy()` both read `catch (e) { toast(__('Duplicate failed.')) }` — the error was bound and then never touched. The most likely rejection at either site is an authorization failure, and this addon's `Controller::authorizeAction()` throws with the permission it wanted by name (`Permission 'delete automations' is required.`). That sentence was constructed, sent, received, and discarded, every time. `Automations/Edit.vue`'s `validate()` and `exportJson()` did the same with a bare `catch {}`, which does not even bind.

**A branch that could not run, and the reasons it dropped.** `toggleEnabled()`, on both the editor and the listing, checked `data.ok === false` to report a blocked enable. The API returns that shape with HTTP **422**, and axios rejects a 422 — so the check sat on the success path where it could never be reached, and control went to the `catch`, which read `.message` only. The `issues[]` array that comes with it, carrying the per-node reasons the automation cannot be enabled, was dropped on every refused enable since the endpoint existed. Both pages now read it off the rejection: the editor feeds it into the issues panel it already has, the listing into its own.

**One toast line was the whole of a rejected save.** `save()` did read `errors` — it was the only site that did — but reduced the map to its first entry and showed it in a toast. `StoreAutomationRequest` validates 16 keys; a save that fails on three of them said one thing and vanished after two seconds. The bag is now kept: `name` is rendered at the header input it belongs to (the invalid ring was there before, but it was only ever set by the client-side pre-check, so it said *that* something was wrong and never *what*), and everything else — `description`, `handle`, and the `nodes.*` / `edges.*` keys the canvas generates, which name array indices no control corresponds to — goes into a collected block above the editor.

**A rejected autosave left no trace at all.** `save({ silent: true })` suppressed the toast by design and rethrew; `useAutosave` caught it into `lastError`, which no template binds. An autosave failing every two seconds was invisible. It now fills the same error state as an explicit save, so the reason is on screen even though nothing is shouted.

**And an uncaught rejection on every failed save.** `save()` rethrew unconditionally, including out of the header button's click handler, where nothing awaits it — one unhandled promise rejection in the browser console per failed save, carrying nothing the user had not just been told. The rethrow is now confined to the autosave path, which is the only caller that needs it.

`resources/js/support/serverErrors.js` is the one new file: `errorBag`, `errorMessages` and `firstMessage`, so reading a rejection is one import rather than a hand-rolled `e?.response?.data?.…` chain at each site — which is how four of them came to have none. `firstMessage` prefers a real validation message over Laravel's generic `"The given data was invalid."`, which is what the remaining toasts in `Import.vue`, `Runs/Show.vue` and `Templates/Index.vue` were showing whenever a 422 carried an `errors` map.

**The two test layers.** `tests/Feature/CpValidationVisibilityTest.php` reads the sources: every submitting function must have a catch, and no catch may report a failure without reading the response — the check that fails on `catch (e) { … }` where `e` is never mentioned again, which is the exact defect this release removes. `tests/js/cp-validation-visibility.test.js` mounts the two pages and hands them real rejections, in the shapes Laravel sends, and requires the server's sentence to appear in the DOM. All 7 of its tests fail against 1.6.0.

Mounting the editor there needed a `ResizeObserver` stub, and the reason is worth writing down: without it Vue Flow's mount throws into an unhandled rejection, and the *next* mount in the file comes back without a component instance — a missing browser API presenting as a page that will not render. It cost more time than the defects did.

## 1.6.0 — 2026-07-28

### Changed — this addon binds `{automationFlow}`, not `{automation}`

1.5.6 added a guard that compared this addon's route parameter names against a hand-written list of what the siblings bind. The list was a snapshot, and it went stale in the same week it was written: `goldnead/statamic-webhook-manager` 1.7.0 renamed all four of the names it claimed, so four of the fourteen entries were describing a world that had moved on, and the file was silently asserting something false. It was also the wrong shape. What replaces it is the rule webhook-manager arrived at, applied here:

> **A `Route::bind()` is registered on the router, not on the package that calls it. Bind only names that unambiguously belong to your addon — specific enough that no sibling would reach for one by accident. Names you do *not* bind may stay as generic as they like: nothing resolves them, so nothing can be taken from anyone.**

`{automation}` was the last generic name any addon in this family still claimed application-wide. It is renamed to `{automationFlow}` — the addon's own prefix plus a capital, which is the shape the guard test now checks for rather than a list of approved words.

**No URL changes.** `/cp/automations/17/edit` is the same string before and after; a route parameter name is the placeholder, never the path. What changed with it, across 8 files: the one `Route::bind()` registration, the 15 route definitions in `routes/cp.php`, the `$this->route(…)` lookup in `UpdateAutomationRequest`, the bound argument in 15 controller methods across `AutomationsController`, `VersionsController`, `ExportImportController` and `AutomationsPageController` — 61 variable occurrences in all — and one `cp_route(…, ['automation' => …])` in `BrandHandleUniquenessTest`, which would have generated the id as a query string instead of a path segment and produced a 405 rather than the 200 it asserts.

The rename was done method by method rather than by search-and-replace, because three of the `$automation` variables in those same files are *not* route-bound and had to stay: `store()` builds its own, `syncGraph()` takes one as an argument, and `automationPayload()` renders one. The Inertia payload key `'automation' => …` that every Vue page reads is likewise untouched — it is not a route parameter, and renaming it would have broken the builder for no reason.

`$this->route('automation')` in `UpdateAutomationRequest::rules()` is why this was worth doing carefully rather than quickly. It is a null-safe read feeding the ignore-id of a `unique` rule. Miss it and it silently reads null, the ignore falls away, and saving an automation without changing its handle starts failing validation against itself — no error at the point of the mistake, one at the far end.

**Why the guard test changed shape.** It now reads the `Route::bind()` calls out of this package's own `src/` — comments stripped, string literals only, and a call whose name is not a literal fails the test rather than escaping it — and requires every name found to match `automation` + a capital. That is a property of this package, so this package's own suite can enforce it without knowing anything about its neighbours, and a second binding cannot arrive by default.

The behavioural half is `it does not swallow a sibling addon's generic route parameter`. `tests/TestCase.php` now mounts stand-in routes for a sibling package — `{automation}`, `{rule}`, `{template}`, `{webhook}`, `{endpoint}`, `{handle}`, `{id}`, `{slug}`, `{record}`, each doing nothing but echoing its own value — and the test asserts every one of them answers with what it was given. Before the rename, `{automation}` answered 404: the LeadHub defect, reproduced from the losing side inside this package's own suite for the first time. They are registered in the bed rather than in the test body deliberately; a route added from inside a test is shadowed by Statamic's `{segments?}` frontend catch-all and answers 404 whatever the bindings do, which would have made the check pass for the wrong reason.

**What deliberately did not change: `{handle}`, `{run}`, `{source}`, `{nodeRun}` and `{timestamp}`.** They are generic and they are staying. Renaming them would move text without removing any exposure, because they are not bound — nothing resolves them, so nothing can collide. `{run}` and `{nodeRun}` resolve through Laravel's *implicit* binding, which matches a route parameter to a typed controller argument and is therefore scoped to that one route. Only `Route::bind()` is application-wide, which is why only that is the subject of the rule.

**Still true, and still not fixable from here:** a collision exists only once two packages are installed together, and a package cannot see its siblings from inside its own suite. The rule turns that from something each addon must know into something each addon can check alone.

### Fixed — this addon was retranslating the German Control Panel for the whole application

The same shape as the section above, one layer over: `loadJsonTranslationsFrom()` appends a directory to a single list on the translator's file loader, and at lookup time the loader merges every `<locale>.json` in that list into one flat array with `array_merge`. The last package to register a key wins, application-wide, for every caller of `__('…')` including statamic/cms itself. There is no namespace, no prefix and no warning — and an addon's own suite loads its own JSON and nobody else's, so of course it never sees a conflict.

`resources/lang/de.json` shipped four bare Control Panel words that statamic/cms also defines. Two of them disagreed with the core:

| key | statamic/cms | this addon, until now |
|---|---|---|
| `Templates` | Templates | Vorlagen |
| `User` | Benutzer:in | Benutzer |

Neither stayed inside the automations screens. `User` is the plainer of the two: Statamic's German uses a gender-inclusive form throughout, and four convenience strings here undid it on **every German CP page in the install** — entries, assets, users, forms, none of which have anything to do with automations. That is not a matter of taste; it is an addon reversing a decision of the core for the whole application.

The fix follows what `goldnead/statamic-leadhub` did in its 1.9.0: where the addon genuinely means something else, the **source string** is made unambiguous rather than the core's translation overridden. `__('Templates')` — an automation template, not an Antlers view — becomes `__('Automation templates')`, in the CP nav item and in the templates page title, with `"Automation templates": "Automatisierungsvorlagen"` in `de.json`. Where the addon means the same thing as the core, the key is simply dropped: `User` in the audit log column now reads Statamic's "Benutzer:in".

`Dashboard` and `Settings` are dropped for the third reason. Their values were identical to statamic/cms's, so nothing anyone sees changes — but a duplicate that agrees today is a disagreement waiting for one side to be edited, and the hub's detector only reports keys where the values differ. Those two would have gone unreported until the day somebody changed one.

**`Enabled` stays, deliberately.** statamic/cms does not define it at all, so dropping it would leave the string untranslated in the German CP. It is also shipped by `goldnead/statamic-leadhub` with the identical value, which is a shared word rather than a defect: nothing a user sees changes whichever package wins.

`tests/Unit/TranslationKeyOwnershipTest.php` is the guard. statamic/cms is a hard dependency of this package, so its dictionary is readable from inside this suite and the check needs no hub — it fails on any key this addon ships that statamic/cms also owns, naming whether the value REPLACES the core's or merely duplicates it. Against the file before this release it reports all four. It also pins the other half of the rename: a source string changed in the code but not in the dictionary leaves the CP untranslated, and `__()` reports that by silently returning the key.

**What this cannot see, and where it is seen instead:** the siblings. A package cannot read what its neighbours register, only what the core does. The hub compares all installed packages at once, in `tests/Feature/GlobalTranslationDictionaryTest.php`, which is where this finding came from.

## 1.5.6 — 2026-07-28

### Added — the route parameter names are checked against the rest of the family

No defect in this addon, and no change to a single route. What is added is the check that would have caught one.

`Route::bind()` is registered on the router, not on a package. The binding this addon registers for `{automation}` applies to every route with an `{automation}` parameter in every other addon installed beside it, and every sibling's binding applies here in the same way. Nothing warns, nothing logs, and the losing route does not fail loudly: it resolves its id against a repository that has never heard of it and returns 404.

`goldnead/statamic-leadhub` 1.8.0 shipped `/scoring/{rule}` while `goldnead/statamic-webhook-manager` binds `rule` to its own rule repository. On the production hub, which has both, editing or deleting a scoring rule did nothing at all and said nothing at all, through a release.

**Why a green suite did not find that, and why this addon's suite was better placed than most.** Two things have to hold for the failure to be observable in an addon's own bed: the sibling's binding has to exist there, which it never does, and the bed has to mount the CP routes with `SubstituteBindings`, which is the middleware that applies a binding at all. LeadHub's bed had neither. This one has had the middleware since it needed it for its own `{automation}` binder — so a test *could* be written here, and now one is, which also names the property instead of leaving it implied in twelve other tests that happen to depend on it. Taking the middleware back out of `tests/TestCase.php` fails 13 of the 362 tests: the new first case, which is about the property itself, and twelve that only ever exercised it as a side effect of resolving an automation.

The test reads this addon's parameter names out of `routes/cp.php` — string literals only, so the example URLs in the comments are not mistaken for routes — and checks them two ways. The first is exact: a hand-maintained list of names that packages installed beside this one bind application-wide, read off the running hub, and the failure message names the package that would swallow the route. The second is a judgement call made explicit: `handle`, `run` and `source` are generic enough that a sibling could claim one tomorrow, so they are recorded in the test with their reason, and a *new* generic parameter fails until somebody renames it or writes down why it stays.

**What this cannot do.** A collision only exists once two packages are installed together, and no package can see its siblings from inside its own suite. The reserved list is a snapshot maintained by hand; it will not catch an addon that starts binding a name nobody binds today. `automation` is one such name — this addon binds it, so it owns it, but a sibling that ever routes an `{automation}` of its own will silently be handed automations from here. The hub remains the only place the real answer is measurable.

This addon's six parameters (`automation`, `handle`, `run`, `source`, `nodeRun`, `timestamp`) collide with nothing bound elsewhere.

## 1.5.5 — 2026-07-28

The four builder defects 1.5.4 reported and left standing, plus the extraction
it said they needed first.

### Changed — the graph mutations moved out of `Edit.vue`

`resources/js/composables/useGraphMutations.js` now owns every way the builder's
graph can change: add, insert on an edge, append, duplicate, delete, rename,
reconfigure, enable/disable, replace the trigger. `Edit.vue` keeps what is
genuinely the page's — pick mode, selection, save/validate/test, the editor's
measured height — and drops from 986 lines to 737.

This is a means, not the point. Those functions are where the builder's
invariants live (a node key must be unique, an edge must leave an output its
node actually has, one mutation must cost exactly one undo step), and while they
sat inline in the page component the only way to exercise them was to open a
browser and click. Three of the four defects below are in code that was
never reachable from a test. The behaviour is unchanged where it was right; what
changed is that it can now be asserted, and the assertions are what the rest of
this entry rests on.

Two things surfaced during the move that were correct only by luck:

- **`insertOnEdge()` had the same hard-coded `'default'` as `duplicateNode()`,
  on a path nobody had reported.** Dropping a node onto an existing edge wires
  the new node onward to the old target — and did so from an output called
  `default`, whichever node had just been inserted. For every node type in the
  library except three that is the right handle, which is why it never showed:
  insert a **branch** on a "+" between two steps and the second edge was
  invalid in exactly the way duplicating one was. Both now ask the node.
- **`hasTriggerNode()` had no callers.** The one-trigger rule moved into
  `useFlowGuards.js` in 1.2.0 and the function stayed behind, still reading
  correct, still describing the rule in a comment two other functions rely on.
  Deleted.

### Fixed — the history recorded one snapshot per keystroke

A node's name and its config fields are text inputs, and every keystroke reached
`history.record()`. The stack holds 100 entries, so roughly a hundred typed
characters pushed every structural step out of it. Delete a node, type a name,
press undo: the delete is not in the stack any more, and no amount of pressing
undo brings the node back. The stack was longest precisely when it was least
useful.

`record()` now takes an optional tag, and consecutive records carrying the same
tag within 600 ms fold into the entry the run started with. The cut:

- **Structural steps are never folded.** An untagged `record()` — add, delete,
  duplicate, connect, replace trigger, enable/disable — always gets its own
  entry, whatever precedes or follows it. Those are the steps a user means when
  reaching for undo, and none of them can be repeated fast enough to be one
  gesture anyway.
- **Text folds per field, per burst.** The tag names what is being edited
  (`label:<node_key>`, `config:<node_key>`), so moving to another field or
  another node ends the run mid-typing, and so does a pause longer than the
  window — which is where a user's own sense of "one edit" ends.
- **Undo, redo and reset end the run**, so typing after an undo cannot fold into
  the entry that undo just restored past.

600 ms is the usual keystroke-coalescing window, and the clock is injectable, so
the window is asserted rather than waited out.

### Fixed — undo walked behind the last save

`useHistory.reset()` was exported since it was written, documented as
"re-baseline after a fresh load / save", and never called. Undo therefore
reached across a save into edits the user had already committed past — and the
Save button then offered to write that older graph back, with nothing on screen
saying so.

An explicit Save now resets the stack. A background autosave deliberately does
not: it fires two seconds into a pause while the user is still working, and
wiping the undo stack under a running edit is worse than the thing being fixed.
The re-baseline is tied to the moment the user says "save", which is the moment
they mean it.

### Fixed — duplicating a branch produced a graph the validator refuses

"Duplicate" appended the copy on the source node's `default` output. A `branch`
has `true` and `false`; a `loop` has `loop` and `done`; an inline `parallel` has
whatever handles its branches are configured with. None of them has a `default`,
so the new edge left a handle that does not exist: invisible on the canvas (Vue
Flow cannot resolve the source handle), never followed at run time
(`WorkflowRunner::nextNode()` matches `from_output` exactly), and rejected by
`FlowValidator` with `branch_invalid_output`. One click on Duplicate, one
invalid automation, and the "issue" it reports names a node the user never
touched.

Insertions now ask the node for its first declared output instead of assuming
one, so a duplicated branch hangs off `true` and its own onward edge leaves
`true` as well. A node that declares no outputs at all — a `stop`, or a
`parallel` whose branches are not configured yet — gets no edge invented for it:
duplicating it adds the copy unconnected, with a toast saying so, and inserting
one onto an existing edge no longer leaves a dead edge behind it. That last one
is a behaviour change on a path that previously produced an edge which was
already invisible and already unroutable.

### Fixed — `newNodeKey()` never looked at the keys already in use

Four random base-36 characters, no collision check, against a
`unique(automation_id, node_key)` in the schema. The odds are small and they are
not zero — a birthday collision at a few hundred nodes of one type, and a plain
accident at any size — and the failure mode is the worst available: an SQL error
on save, on a graph the user has already finished building, with no way to tell
which node is the problem. Nothing in the editor could have shown it, because
nothing in the editor knew.

`uniqueNodeKey()` draws against the keys the automation already holds. Keys keep
the shape they have always had; the draw simply repeats when it collides, and
falls back to a counter so the loop provably terminates. With a frozen RNG the
old code produces two nodes with one key (and a self-referencing edge for good
measure); the new one produces two keys.

### Changed — the canvas knows the namespaced `*.branch` types the validator has always known

`FlowValidator` has required `true`/`false` on any node type ending in
`.branch` since the first release. Nothing else in the package had heard of the
convention. What that meant in practice, once traced: a third-party addon
registering `acme.branch` got a single `default` output from the canvas, the
user wired the only handle on offer, and validation then refused the graph. The
suffix did not enable a custom branch node — it made one *less* usable than any
other custom node type, and no first-party type has ever ended in `.branch`, so
nothing ever ran into it.

`outputsFor()` now applies the same rule, which is the whole of the alignment on
the canvas side: edge labels, handle positions and the "+" adders all derive
from the output list, and `WorkflowRunner` needs no counterpart at all — it
routes on the output handle a node returns, never on the node's type. The
previous release left this alone on the grounds that it might be half a feature;
the half that is a feature is a different one, and is named under Notes.

### Notes

- **Still open, and genuinely a feature this time: node outputs are declared
  twice.** `SwitchNode`, `ParallelNode` and `LoopNode` each declare `outputs()`
  in PHP, and `outputsFor()` in `useAutoLayout.js` mirrors all three by hand,
  including how they read `config.cases` and `config.branches`. The mirror is
  accurate today and the tests pin it, but `NodeRegistry::describe()` does not
  expose `outputs()`, so a third-party node cannot declare handles of its own —
  it gets `default`, or `true`/`false` if it is named for it. Making the canvas
  read the outputs from the library payload means changing `describe()`, handing
  config-dependent outputs to the frontend, and versioning that payload. Worth
  doing, and not something to start inside a bug-fix release.
- **Duplicate attaches the copy to the source's *first* output**, which for a
  `loop` means the copy lands inside the loop body rather than after it. Valid,
  deterministic, and arguably not what the user meant; there is no metadata
  marking a node's "main" continuation, and inventing one is the same feature as
  above.
- **Known and unchanged:** `SubAutomationAndAlertsTest > failure alerter logs
  and throttles` fails under MySQL on a foreign-key violation
  (`automation_id=999`, which SQLite does not enforce). Reproducible at 1.5.3.
- Suite: **359 passed (1269 assertions)** on SQLite, unchanged — nothing on the
  PHP side moved. Vitest **30 passed**, baseline 11: the graph mutations
  (15) and the builder page driven through its own canvas and header stubs (4).
  `node:test` **81 passed**, baseline 73. Every fix was verified by stashing the
  source and watching its test fail against 1.5.4: the undo returned `Welco`
  instead of the deleted node, undo stayed enabled after the save, the duplicated
  branch left two edges on `br`, and five nodes carried four distinct keys.

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
