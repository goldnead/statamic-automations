<?php

namespace Goldnead\StatamicAutomations\Tests\Engine;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Tests\TestCase;

/**
 * The Loop node's default "inline" mode iterates the downstream subgraph
 * wired to its "loop" output once per item, then continues via "done" —
 * instead of requiring a separate target automation.
 */
class LoopInlineTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_inline_loop_runs_body_once_per_item_then_done_once(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'loop',
                'type' => 'loop',
                'config' => [
                    'items' => ['a', 'b', 'c'],
                    'mode' => 'inline',
                ],
            ],
            [
                'key' => 'body',
                'type' => 'add_log_entry',
                'config' => ['level' => 'info', 'message' => '{{item}}'],
            ],
            [
                'key' => 'done_log',
                'type' => 'add_log_entry',
                'config' => ['level' => 'info', 'message' => 'done'],
            ],
        ], [
            ['t', 'loop'],
            ['loop', 'body', 'loop'],
            ['loop', 'done_log', 'done'],
        ]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);

        $nodeRuns = $finalRun->nodeRuns()->orderBy('id')->get();

        $bodyRuns = $nodeRuns->where('node_key', 'body')->values();
        $doneRuns = $nodeRuns->where('node_key', 'done_log')->values();

        $this->assertCount(3, $bodyRuns, 'Loop body should run once per item.');
        $this->assertSame(
            ['a', 'b', 'c'],
            $bodyRuns->map(fn ($r) => $r->output['preview']['message'])->all(),
        );

        $this->assertCount(1, $doneRuns, 'Done branch should run exactly once, after the loop finishes.');
        $this->assertSame('done', $doneRuns->first()->output['preview']['message']);

        // "done" must come strictly after all three body runs.
        $lastBodyId = $bodyRuns->last()->id;
        $this->assertGreaterThan($lastBodyId, $doneRuns->first()->id);
    }

    /**
     * The interface contract requires BOTH a bare `index` and the nested
     * `loop.count` / `loop.first` / `loop.last` keys to be resolvable
     * inside the loop body — not just `loop.index`.
     */
    public function test_inline_loop_exposes_bare_index_token_in_body(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'loop',
                'type' => 'loop',
                'config' => [
                    'items' => ['a', 'b', 'c'],
                    'mode' => 'inline',
                ],
            ],
            [
                'key' => 'body',
                'type' => 'add_log_entry',
                'config' => ['level' => 'info', 'message' => '{{index}}'],
            ],
        ], [
            ['t', 'loop'],
            ['loop', 'body', 'loop'],
        ]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);

        $bodyRuns = $finalRun->nodeRuns()->orderBy('id')->get()->where('node_key', 'body')->values();

        $this->assertCount(3, $bodyRuns);
        $this->assertSame(
            [0, 1, 2],
            $bodyRuns->map(fn ($r) => $r->output['preview']['message'])->all(),
            'A bare {{ index }} token must resolve to the 0-based item index inside the loop body.',
        );
    }

    /**
     * Once the loop's "done" output is reached, the loop-context scope
     * (`item` / `index` / `loop`) must be fully restored — a node wired
     * after "done" must not see any of it still set. This is the
     * pop/restore half of the scope contract; it must run even if the
     * push happened, proving the restore isn't merely skipped because
     * nothing was ever set.
     */
    public function test_inline_loop_scope_is_restored_after_done(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'loop',
                'type' => 'loop',
                'config' => [
                    'items' => ['a', 'b'],
                    'mode' => 'inline',
                ],
            ],
            [
                'key' => 'body',
                'type' => 'add_log_entry',
                'config' => ['level' => 'info', 'message' => '{{item}}'],
            ],
            [
                'key' => 'after_done',
                'type' => 'add_log_entry',
                // If the loop scope leaked past "done", these tokens would
                // resolve to the last item's values instead of empty.
                'config' => ['level' => 'info', 'message' => 'item=[{{item}}] index=[{{index}}] loop=[{{loop.index}}]'],
            ],
        ], [
            ['t', 'loop'],
            ['loop', 'body', 'loop'],
            ['loop', 'after_done', 'done'],
        ]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $finalRun->status);

        $afterDoneRuns = $finalRun->nodeRuns()->orderBy('id')->get()->where('node_key', 'after_done')->values();

        $this->assertCount(1, $afterDoneRuns);
        $this->assertSame(
            'item=[] index=[] loop=[]',
            $afterDoneRuns->first()->output['preview']['message'],
            'item/index/loop must be fully cleared from context after the loop completes, not leaked past "done".',
        );

        // Also assert directly on the shared run context object: no
        // loop-scope keys should survive past the loop at all.
        $this->assertFalse($context->has('item'));
        $this->assertFalse($context->has('index'));
        $this->assertFalse($context->has('loop'));
    }

    /**
     * When a loop body node fails without an `_on_error: continue` policy,
     * runFrom() throws and unwinds straight out of driveInlineLoop(). The
     * scope pop must still run (via `finally`) so the loop-context keys
     * (`item` / `index` / `loop`) never leak on the shared context object
     * — even though the run itself ends up FAILED and no further nodes
     * execute to observe it via node-run output.
     */
    public function test_inline_loop_scope_is_restored_even_when_body_throws(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            [
                'key' => 'loop',
                'type' => 'loop',
                'config' => [
                    'items' => ['a', 'b'],
                    'mode' => 'inline',
                ],
            ],
            [
                // Points at a nonexistent automation → always fails at
                // runtime, with no `_on_error` override, so it throws.
                'key' => 'boom',
                'type' => 'call_automation',
                'config' => ['automation' => 'does-not-exist-xyz'],
            ],
        ], [
            ['t', 'loop'],
            ['loop', 'boom', 'loop'],
        ]);

        $context = AutomationContext::make([], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));
        $finalRun = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_FAILED, $finalRun->status);

        // $context is the same object the runner mutated in place — if the
        // pop were skipped on the exception path, these would still hold
        // the first item's loop-scope values.
        $this->assertFalse($context->has('item'), 'item must not leak past a loop whose body threw.');
        $this->assertFalse($context->has('index'), 'index must not leak past a loop whose body threw.');
        $this->assertFalse($context->has('loop'), 'loop must not leak past a loop whose body threw.');
    }

    /**
     * @param  array<int, array{key: string, type: string, config?: array<string, mixed>}>  $nodes
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $edges
     */
    protected function buildAutomation(array $nodes, array $edges): Automation
    {
        $automation = Automation::create(['name' => 'T', 'handle' => 'test-' . uniqid()]);

        foreach ($nodes as $node) {
            AutomationNode::create([
                'automation_id' => $automation->id,
                'node_key' => $node['key'],
                'type' => $node['type'],
                'config' => $node['config'] ?? [],
            ]);
        }

        foreach ($edges as $edge) {
            AutomationEdge::create([
                'automation_id' => $automation->id,
                'from_node_key' => $edge[0],
                'from_output' => $edge[2] ?? 'default',
                'to_node_key' => $edge[1],
            ]);
        }

        return $automation->fresh()->load(['nodes', 'edges']);
    }
}
