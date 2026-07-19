# Task 1.1: Arbitrary-output routing + inline-Loop subgraph (engine)

Part of epic `automations-editor-functional-overhaul`. This is the FIRST task. Backend PHP engine work, TDD with Pest.

## Why
Today the Loop node does NOT iterate the downstream graph nodes. `LoopNode::execute()` (src/Nodes/Logic/LoopNode.php around line 85) requires a *separate target automation* (`config['automation']`, required, returns failed if empty) and runs it per item. So wiring nodes after a loop does nothing — "loops don't loop". We make Loop iterate the inline downstream subgraph, which is what users expect (Zapier/n8n style).

## Files
- Modify: `src/Engine/WorkflowRunner.php` — `walk()` (~lines 264-333, DFS over automation edges/nodes), `nextNode($current, $outputHandle, ...)` (~359-375, matches `edge.from_output === output`).
- Modify: `src/Nodes/Logic/LoopNode.php`.
- Test: `tests/Engine/LoopInlineTest.php` (create), `tests/Engine/LoopNestedTest.php` (create).

## Interfaces this task PRODUCES (later tasks rely on these names)
- `LoopNode` emits two output handles: `loop` (loop body) and `done`.
- `LoopNode` gains `config.mode` with default `inline`; `mode=automation` keeps the legacy separate-automation behavior (no longer required/default).
- `WorkflowRunner` runs the `loop`-output-reachable subgraph once per resolved item, then continues via the `done` output.
- Loop context variables available inside the body (in the run scope, token-resolvable): `item`, `index`, `loop.count`, `loop.first`, `loop.last`. Nested loops must scope these on a stack (inner loop shadows outer, restores on exit).

## Steps (TDD — follow superpowers:test-driven-development)
1. READ `WorkflowRunner.php` (`walk`, `nextNode`, run-scope + token resolution API) and `LoopNode.php` fully. Learn how the run scope stores variables and how `createRun`/`execute` currently work, and how `ActionResult`/output handles flow.
2. Write failing test `tests/Engine/LoopInlineTest.php`: an automation `manual → loop(items=[a,b,c], mode=inline)` with the `loop` output wired to an `add_log_entry` whose message is `{{item}}`, and the `done` output wired to an `add_log_entry("done")`. Run the automation. Assert: body log ran 3× with values a,b,c in order; done log ran exactly once after. (Use whatever log/inspection the existing tests use — mirror an existing engine test's setup helpers.)
3. Run `vendor/bin/pest tests/Engine/LoopInlineTest.php` → expect FAIL.
4. Implement: in `LoopNode`, add `mode` (default `inline`), drop the hard `automation` requirement (only require `automation` when `mode=automation`), declare outputs `loop`+`done`. In `WorkflowRunner::walk()`: when the current node is an inline loop, resolve `items` (token → array; if empty/non-array, log a notice and route straight to `done`), then for each item push loop-context vars onto a scope stack, drive the subgraph reachable from the `loop` edge to its natural end (a node with no outgoing edge for the taken output), pop the scope. After the last item, route via `done`.
5. Run the test → expect PASS.
6. Write `tests/Engine/LoopNestedTest.php`: loop over [x,y] whose body contains a nested control node (another loop OR a switch). Assert iteration scope + routing both correct (inner does not leak into outer). Run → PASS.
7. Run the FULL engine test suite `vendor/bin/pest tests/Engine` to confirm no regressions.
8. Commit: `git add -A && git commit -m "feat(engine): inline loop subgraph execution + arbitrary output routing"` (use `git -c user.name=goldnead -c user.email=chief@gldnr.studio commit` if identity missing).

## Global constraints
- Additiv + reversibel. Legacy `mode=automation` path must keep working.
- `automation_nodes` + `automation_edges` (`from_node_key`/`from_output`/`to_node_key`) are the truth — do not change the schema unless a migration is truly required (if so, flag it).
- Follow existing code patterns in the engine. DRY, YAGNI.
- Do NOT touch frontend/JS in this task (the editor's dynamic handles are a later task).
