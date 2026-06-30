# Changelog

All notable changes to **Statamic Automations** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
