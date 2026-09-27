<?php

namespace Goldnead\StatamicAutomations\Tests\Engine;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\RunLogger;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\ResumeDelayedRun;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

/**
 * What ends an item and what ends the run, inside an inline loop.
 *
 * - A Filter that does not match inside the body ends only the current
 *   item; the next item runs. Outside a loop it still stops the run.
 * - A failing node inside the body fails the run (default `stop`), or,
 *   with the Loop's `on_item_error: continue`, ends only that item and is
 *   reported on the loop's output and in the run's error message.
 */
class LoopItemControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_filter_in_the_loop_body_skips_only_the_item_that_does_not_match(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'loop', 'type' => 'loop', 'config' => ['items' => ['a', 'b', 'c', 'b'], 'mode' => 'inline']],
            ['key' => 'only_b', 'type' => 'filter', 'config' => $this->itemIs('b')],
            ['key' => 'body', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => '{{ item }}:{{ index }}']],
            ['key' => 'done_log', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'done']],
        ], [
            ['t', 'loop'],
            ['loop', 'only_b', 'loop'],
            ['only_b', 'body'],
            ['loop', 'done_log', 'done'],
        ]);

        $run = $this->runFlow($automation);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertNull($run->error_message);
        $this->assertSame(['b:1', 'b:3'], $this->messages($run, 'body'));
        $this->assertSame(['done'], $this->messages($run, 'done_log'));
        // Every item reached the filter, the last one included.
        $this->assertCount(4, $run->nodeRuns()->where('node_key', 'only_b')->get());
    }

    public function test_a_filter_after_the_loop_still_stops_the_run(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'loop', 'type' => 'loop', 'config' => ['items' => ['a'], 'mode' => 'inline']],
            ['key' => 'body', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => '{{ item }}']],
            ['key' => 'never', 'type' => 'filter', 'config' => $this->itemIs('zzz')],
            ['key' => 'after', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'after']],
        ], [
            ['t', 'loop'],
            ['loop', 'body', 'loop'],
            ['loop', 'never', 'done'],
            ['never', 'after'],
        ]);

        $run = $this->runFlow($automation);

        $this->assertSame(AutomationRun::STATUS_STOPPED, $run->status);
        $this->assertSame([], $this->messages($run, 'after'));
    }

    public function test_a_stop_node_in_the_loop_body_still_ends_the_whole_run(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'loop', 'type' => 'loop', 'config' => ['items' => ['a', 'b'], 'mode' => 'inline']],
            ['key' => 'body', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => '{{ item }}']],
            ['key' => 'halt', 'type' => 'stop'],
        ], [
            ['t', 'loop'],
            ['loop', 'body', 'loop'],
            ['body', 'halt'],
        ]);

        $run = $this->runFlow($automation);

        $this->assertSame(AutomationRun::STATUS_STOPPED, $run->status);
        $this->assertSame(['a'], $this->messages($run, 'body'));
    }

    public function test_filters_in_nested_loops_end_only_their_own_item(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'outer', 'type' => 'loop', 'config' => ['items' => ['x', 'y'], 'mode' => 'inline']],
            ['key' => 'inner', 'type' => 'loop', 'config' => ['items' => ['1', '2', '3'], 'mode' => 'inline']],
            ['key' => 'inner_filter', 'type' => 'filter', 'config' => $this->itemIs('2')],
            ['key' => 'inner_log', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'in:{{ item }}']],
            // After the inner loop, back in the outer body: the outer item.
            ['key' => 'outer_filter', 'type' => 'filter', 'config' => $this->itemIs('y')],
            ['key' => 'outer_log', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'out:{{ item }}']],
            ['key' => 'done_log', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'done']],
        ], [
            ['t', 'outer'],
            ['outer', 'inner', 'loop'],
            ['inner', 'inner_filter', 'loop'],
            ['inner_filter', 'inner_log'],
            ['inner', 'outer_filter', 'done'],
            ['outer_filter', 'outer_log'],
            ['outer', 'done_log', 'done'],
        ]);

        $run = $this->runFlow($automation);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        // The inner filter let '2' through once per outer item.
        $this->assertSame(['in:2', 'in:2'], $this->messages($run, 'inner_log'));
        // The outer filter ended the outer 'x' pass, not the loop.
        $this->assertSame(['out:y'], $this->messages($run, 'outer_log'));
        $this->assertSame(['done'], $this->messages($run, 'done_log'));
    }

    public function test_a_failing_node_in_the_loop_body_fails_the_run_by_default(): void
    {
        $automation = $this->failingLoop([]);

        $run = $this->runFlow($automation);

        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        // The item after the failing one never ran.
        $this->assertSame(['ok:a'], $this->messages($run, 'ok'));
        $this->assertSame([], $this->messages($run, 'done_log'));
    }

    public function test_on_item_error_continue_skips_the_failed_item_and_reports_it(): void
    {
        $automation = $this->failingLoop(['on_item_error' => 'continue']);

        $run = $this->runFlow($automation);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(['ok:a', 'ok:c'], $this->messages($run, 'ok'));

        // The failed step is in the run log with its own error ...
        $boom = $run->nodeRuns()->where('node_key', 'boom')->get();
        $this->assertCount(1, $boom);
        $this->assertSame('failed', $boom->first()->status);

        // ... the run says items failed ...
        $this->assertStringContainsString("Loop 'loop': 1 of 3 items failed", (string) $run->error_message);
        $this->assertStringContainsString('(index 1)', (string) $run->error_message);

        // ... the loop's row carries the count ...
        $loopRun = $run->nodeRuns()->where('node_key', 'loop')->first();
        $this->assertSame(1, $loopRun->output['failed_items']);
        $this->assertSame(1, $loopRun->output['failed'][0]['index']);

        // ... and the steps after the loop can read it.
        $this->assertSame(['done:1'], $this->messages($run, 'done_log'));
    }

    public function test_on_item_error_continue_without_failures_leaves_the_run_clean(): void
    {
        $automation = $this->failingLoop(['on_item_error' => 'continue', 'items' => ['a', 'c']]);

        $run = $this->runFlow($automation);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertNull($run->error_message);
        $this->assertSame(['done:0'], $this->messages($run, 'done_log'));
    }

    /**
     * Item a fails, item b reaches a Delay: the context is persisted there
     * and the run finishes in the resume job. The failure of a must still be
     * on the finished run.
     */
    public function test_a_skipped_item_is_still_reported_when_a_later_item_waits_and_the_run_resumes(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'loop', 'type' => 'loop', 'config' => ['items' => ['a', 'b'], 'mode' => 'inline', 'on_item_error' => 'continue']],
            ['key' => 'is_a', 'type' => 'branch', 'config' => $this->itemIs('a')],
            ['key' => 'boom', 'type' => 'call_automation', 'config' => ['automation' => 'does-not-exist-xyz']],
            ['key' => 'wait', 'type' => 'delay', 'config' => ['amount' => 1, 'unit' => 'days']],
            ['key' => 'ok', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'after wait']],
        ], [
            ['t', 'loop'],
            ['loop', 'is_a', 'loop'],
            ['is_a', 'boom', 'true'],
            ['is_a', 'wait', 'false'],
            ['wait', 'ok'],
        ]);

        $context = AutomationContext::make([]);
        $runner = app(WorkflowRunner::class);
        $run = $runner->execute($runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't')), $context);

        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);

        $job = AutomationScheduledJob::where('automation_run_id', $run->id)->firstOrFail();
        (new ResumeDelayedRun($job->id))->handle($runner);

        $run = $run->fresh();
        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->nodeRuns()->where('node_key', 'ok')->count());
        $this->assertStringContainsString("Loop 'loop': 1 of 2 items failed", (string) $run->error_message);
        $this->assertStringContainsString('(index 0)', (string) $run->error_message);
    }

    /**
     * `continue` ends an item for a node's failed result, not for an error
     * of the engine itself (here: the run log cannot be written).
     */
    public function test_on_item_error_continue_does_not_swallow_engine_errors(): void
    {
        $this->app->bind(RunLogger::class, fn ($app) => new class($app->make(TokenResolver::class)) extends RunLogger
        {
            public function recordNodeRun(
                AutomationRun $run,
                string $nodeKey,
                string $nodeType,
                array $input,
                ?ActionResult $result = null,
                ?\Throwable $exception = null,
                ?\DateTimeInterface $startedAt = null,
            ): AutomationNodeRun {
                if ($nodeKey === 'explode') {
                    throw new \RuntimeException('run log unavailable');
                }

                return parent::recordNodeRun($run, $nodeKey, $nodeType, $input, $result, $exception, $startedAt);
            }
        });

        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'loop', 'type' => 'loop', 'config' => ['items' => ['a', 'b'], 'mode' => 'inline', 'on_item_error' => 'continue']],
            ['key' => 'explode', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => '{{ item }}']],
            ['key' => 'done_log', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'done']],
        ], [
            ['t', 'loop'],
            ['loop', 'explode', 'loop'],
            ['loop', 'done_log', 'done'],
        ]);

        $run = $this->runFlow($automation);

        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        $this->assertSame('run log unavailable', $run->error_message);
        $this->assertSame([], $this->messages($run, 'done_log'));
    }

    public function test_an_inner_loop_that_fails_ends_only_the_outer_item_when_the_outer_loop_continues(): void
    {
        $automation = $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'outer', 'type' => 'loop', 'config' => ['items' => ['x', 'bad', 'y'], 'mode' => 'inline', 'on_item_error' => 'continue']],
            // Inner loop on the default policy: its failure escapes to the outer loop.
            ['key' => 'inner', 'type' => 'loop', 'config' => ['items' => ['{{ item }}'], 'mode' => 'inline']],
            ['key' => 'is_bad', 'type' => 'branch', 'config' => $this->itemIs('bad')],
            ['key' => 'boom', 'type' => 'call_automation', 'config' => ['automation' => 'does-not-exist-xyz']],
            ['key' => 'ok', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'ok:{{ item }}']],
        ], [
            ['t', 'outer'],
            ['outer', 'inner', 'loop'],
            ['inner', 'is_bad', 'loop'],
            ['is_bad', 'boom', 'true'],
            ['is_bad', 'ok', 'false'],
        ]);

        $run = $this->runFlow($automation);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(['ok:x', 'ok:y'], $this->messages($run, 'ok'));
        $this->assertStringContainsString("Loop 'outer': 1 of 3 items failed", (string) $run->error_message);
    }

    /**
     * Loop over a, b, c where the body fails for b.
     *
     * @param  array<string, mixed>  $loopConfig
     */
    protected function failingLoop(array $loopConfig): Automation
    {
        return $this->buildAutomation([
            ['key' => 't', 'type' => 'manual'],
            ['key' => 'loop', 'type' => 'loop', 'config' => [...['items' => ['a', 'b', 'c'], 'mode' => 'inline'], ...$loopConfig]],
            ['key' => 'is_b', 'type' => 'branch', 'config' => $this->itemIs('b')],
            // Points at a nonexistent automation, so it always fails.
            ['key' => 'boom', 'type' => 'call_automation', 'config' => ['automation' => 'does-not-exist-xyz']],
            ['key' => 'ok', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'ok:{{ item }}']],
            ['key' => 'done_log', 'type' => 'add_log_entry', 'config' => ['level' => 'info', 'message' => 'done:{{ nodes.loop.failed_items }}']],
        ], [
            ['t', 'loop'],
            ['loop', 'is_b', 'loop'],
            ['is_b', 'boom', 'true'],
            ['is_b', 'ok', 'false'],
            ['loop', 'done_log', 'done'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function itemIs(string $value): array
    {
        return [
            'mode' => 'all',
            'conditions' => [['field' => 'item', 'operator' => 'equals', 'value' => $value]],
        ];
    }

    protected function runFlow(Automation $automation): AutomationRun
    {
        $context = AutomationContext::make([], testMode: true);
        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));

        return $runner->execute($run, $context);
    }

    /**
     * @return array<int, mixed>
     */
    protected function messages(AutomationRun $run, string $nodeKey): array
    {
        /** @var Collection<int, AutomationNodeRun> $rows */
        $rows = $run->nodeRuns()->where('node_key', $nodeKey)->orderBy('id')->get();

        return $rows->map(fn ($r) => $r->output['preview']['message'] ?? null)->values()->all();
    }

    /**
     * @param  array<int, array{key: string, type: string, config?: array<string, mixed>}>  $nodes
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $edges
     */
    protected function buildAutomation(array $nodes, array $edges): Automation
    {
        $automation = Automation::create(['name' => 'T', 'handle' => 'test-'.uniqid()]);

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
