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

## Minor findings roll-up (triage at final review)
- 1.3 Minor: switch case output-handle value literally "0" — PHP treats falsy→default, JS `String(output||'default')` does not. Edge case.
- 1.3 Minor: JS Object.entries() reorders integer-like string keys vs PHP insertion order — affects only visual handle order/label, not routable handle set.
- 1.3 Minor: parallel node with zero configured branches renders zero source handles (looks like terminal node). Matches spec but UX quirk.
- 1.3 Observed polish: Loop node-library description still reads "Runs a sub-automation once for every item in a collection" (stale after inline-loop change; Parallel desc was updated). Update in a polish pass.
