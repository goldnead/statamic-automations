# SDD Progress — automations-editor-functional-overhaul

Branch: feat/editor-functional-overhaul
Plan: docs/superpowers/plans/2026-07-19-automations-editor-functional-overhaul.md

## Ledger
Task 1.1: complete (commits 5915cf6..36a192f, review clean after 2 Important fixes: bare index token + exception-safe loop scope). 200+ suite green.
Task 1.2: complete (commits ..066b2f4, review clean after 1 Important fix: parallel default→inline symmetric with loop; switch default fallback verified). 200 suite green.
  NOTE for frontend tasks: Loop outputs=loop/done; Switch outputs=cases+default; Parallel outputs=branches (default mode=inline). New editor nodes work out-of-box.

Task 1.3: complete (commit 104727d, review Spec✅/Quality Approved; browser-verified: switch with 3 cases renders paid/trial/churned/Default outputs, each with its own adder). Loop/parallel same verified machinery.

Task 1.4: complete. Delay/Throttle/SetVariable/CallAutomation were already correctly implemented (had unit coverage; added engine-level smoke coverage). Real bug found + fixed: WaitUntil's resume path was wired identically to Delay's (`WorkflowRunner::resumeAfterNode` always skipped re-executing the paused node), so once a wait_until parked, its scheduled recheck blindly advanced the flow regardless of whether the condition was actually met — the "re-check on an interval" behavior the node's own docblock promised was never wired up. Fixed via an opt-in `reexecuteOnResume(): bool` static hook nodes can declare (WaitUntilNode uses it; Delay unaffected, still advances). New `tests/Engine/LogicNodesSmokeTest.php` (6 tests) proves all 5 nodes end-to-end through WorkflowRunner. Full suite 200→206 green.

VERIFY ENV NOTE: testbench = Herd at http://statamic-addon-testbench.test (login info@adriangoldner.com/password). Chrome-in-chrome extension FAILS on .test (error page) — use Playwright MCP instead. Had to run `php artisan migrate --force` on testbench (automation_uuid migration was pending) + `vendor:publish --force --tag=statamic-automations` after build.

Task 1.4: complete (commit e02afae + phpunit fix 2459903, review Spec✅/Quality Approved). WaitUntil resume-recheck bug fixed. PHASE 1 DONE. Full default suite now 216 passed (added tests/Engine to phpunit.xml — was silently excluded, so 1.1/1.2/1.4 engine tests weren't in CI before).

Task 2.2: complete (commit 7f364ff, review Spec✅/Quality Approved; browser-verified: entry_saved Collection picker loads real collections from options endpoint — empty dropdowns fixed). 2 cosmetic minors in roll-up.
Task 2.4: complete (commit ca5d105, review Spec✅/Quality Approved — all options_source strings cross-checked vs endpoint, handles preserved, outputSchema real). 249 green.
Task 2.3: complete-pending-review (commit 34bb052, build clean; browser-verified: add_log_entry Message field shows {{ }} inserter listing entry_saved's Entry›Id/Title/Slug/Collection/Site/Url/Data, inserting {{ entry.title }} works + shows on node card). Review dispatched.
JS-TEST FIX (commit e88d13f): Task 1.3's outputsFor {handle,label} shape broke 4 tests/js/auto-layout contract tests (only run via `npm run test:js`, NOT default gate — same gap class as phpunit). Updated expectations; all 22 JS tests green. ROLL-UP: npm run test:js is not in any CI gate.
PHASE 2 core done (empty dropdowns fixed, entity pickers + cascading + token insertion all browser-verified).
Task 2.3: review Spec✅/Quality Approved (backward BFS transitive+cycle-safe, non-destructive caret insert). The id-prop ⚠️ resolved by controller browser test.
Task 3 (events): complete-pending-review (commit 3fcfe07, 269 green). +11 triggers: entry_created/entry_saving/term_saved/term_deleted/user_saved/user_deleted/asset_uploaded/asset_saved/asset_deleted/global_set_saved/nav_saved. Skipped entry_unpublished (no event class), submission_created (==form_submitted). Review dispatched. PHASE 3 DONE.
  ROLL-UP: nav_saved has no filter (no statamic.navs options source).

## FOLLOW-UP / decisions for Adrian (not blocking)
- webhook_received trigger uses source `webhook_manager.inbound_endpoints` which has NO arm in options endpoint (Task 2.1) → its endpoint picker is empty. Add the arm in a follow-up (needs webhook-manager installed).
- WaitUntil deeper limitation: its condition rechecks a FROZEN context snapshot (taken at pause time); nothing in the engine refreshes context before recheck, so in production an unmet condition can't become met without external mutation. Wiring is fixed; the context-refresh is a pre-existing architectural gap. Decide later whether to build context-refresh-on-recheck.

Task 2.1: complete (commit 4824078, review Spec✅/Quality Approved). Options endpoint /cp/automations/api/options/{source} serves collections/entries/taxonomies/terms/forms/users/roles/blueprints/assets/asset_containers/sites/globals/automations/webhooks. Convention: existing schemas use statamic.-PREFIXED names; new sources accept bare+prefixed. class_exists guards → [] for absent addons. 231→ suite green.

## Minor findings roll-up (triage at final review)
- 1.4 Minor: `src/Jobs/ResumeDelayedRun.php` docblock "the delay node itself is NOT re-executed" now false for WaitUntil (stale).
- 2.1 Minor: options sources `entries`/`assets`/`users` are unbounded (no pagination/limit) — fine for small sites, could be heavy on the real adriangoldner.com (many entries). Consider a searchable/paginated picker in a follow-up.
- 2.1 Minor: OptionsEndpointTest asserts only the empty branch for `assets`/`blueprints` (no asset file seeded) — add happy-path shape assertions if Task 2.2 depends on exact shape.
- 1.3 Minor: switch case output-handle value literally "0" — PHP treats falsy→default, JS `String(output||'default')` does not. Edge case.
- 1.3 Minor: JS Object.entries() reorders integer-like string keys vs PHP insertion order — affects only visual handle order/label, not routable handle set.
- 1.3 Minor: parallel node with zero configured branches renders zero source handles (looks like terminal node). Matches spec but UX quirk.
- 1.3 Observed polish: Loop node-library description still reads "Runs a sub-automation once for every item in a collection" (stale after inline-loop change; Parallel desc was updated). Update in a polish pass.
