<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationNode;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\NormalizesKeyValue;

/**
 * Fan out to several branches and continue every one of them.
 *
 * Two modes, mirroring {@see \Goldnead\StatamicAutomations\Nodes\Logic\LoopNode}:
 * - "automation" (default, legacy): each branch is a named sub-automation
 *   that receives the current context; results are collected under
 *   {{ <node>.branches }} keyed by branch name, so a downstream node sees
 *   a single joined result. Kept as the default so existing wiring built
 *   against this behavior keeps working unchanged.
 * - "inline" (opt-in via `mode: inline`): this node only declares its
 *   configured branch handles and signals which output(s) to take. The
 *   actual fan-out is driven by
 *   {@see \Goldnead\StatamicAutomations\Engine\WorkflowRunner}, which runs
 *   the subgraph reachable from EVERY connected branch output to
 *   completion — not just the first — the same way it drives an inline
 *   Loop's body once per item.
 *
 * Execution is sequential within one run in both modes (the engine runs a
 * run to completion synchronously); the value here is the scatter/gather
 * shape, not OS-level concurrency.
 */
class ParallelNode implements AutomationNode
{
    use NormalizesKeyValue;

    /**
     * outputHandle used by {@see execute()} in inline mode to signal the
     * WorkflowRunner that this is a fan-out node — mirrors
     * {@see LoopNode::OUTPUT_LOOP}.
     */
    public const OUTPUT_FAN_OUT = 'fan_out';

    public function __construct(protected WorkflowRunner $runner)
    {
    }

    /**
     * Output handles this node can route through. Automation mode always
     * routes via the default "success" handle (the branches are sub-runs,
     * not graph edges); inline mode's outputs are its configured branch
     * handles (the key_value's keys — see {@see executeInlineMode()}).
     *
     * @return array<int, string>
     */
    public static function outputs(array $config = []): array
    {
        $mode = (string) ($config['mode'] ?? 'inline') ?: 'inline';

        if ($mode !== 'inline') {
            return ['default'];
        }

        $branches = static::normalizeKeyValue($config['branches'] ?? []);

        return array_values(array_map('strval', array_keys($branches)));
    }

    public static function handle(): string
    {
        return 'parallel';
    }

    public static function label(): string
    {
        return 'Parallel (fan-out / join)';
    }

    public static function description(): ?string
    {
        return 'Fans out to every connected branch in this graph and joins their results before continuing (or, in legacy mode, runs a separate automation per branch).';
    }

    public static function group(): string
    {
        return 'Logic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'mode',
                'label' => 'Mode',
                'type' => 'select',
                'options' => [
                    ['value' => 'inline', 'label' => 'Run the connected nodes for each branch'],
                    ['value' => 'automation', 'label' => 'Run a separate automation per branch (legacy)'],
                ],
                'default' => 'inline',
            ],
            [
                'handle' => 'branches',
                'label' => 'Branches',
                'type' => 'key_value',
                'required' => true,
                'help' => 'Automation mode: branch name → automation handle. Inline mode: branch output handle → label. Connect an edge from each branch handle.',
            ],
            [
                'handle' => 'fail_fast',
                'label' => 'Fail if any branch fails',
                'type' => 'toggle',
                'default' => false,
                'help' => 'Automation mode only.',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $mode = (string) ($config['mode'] ?? 'inline') ?: 'inline';

        return $mode === 'inline'
            ? $this->executeInlineMode($config)
            : $this->executeAutomationMode($context, $config);
    }

    /**
     * Declare the configured branch handles and hand control back to the
     * WorkflowRunner, which drives EVERY subgraph wired to those handles
     * to completion. This node itself never runs a branch.
     */
    protected function executeInlineMode(array $config): ActionResult
    {
        $branches = static::normalizeKeyValue($config['branches'] ?? []);
        $handles = array_values(array_map('strval', array_keys($branches)));

        if (empty($handles)) {
            return ActionResult::failed('At least one branch is required.');
        }

        return ActionResult::success(['branches' => $handles], self::OUTPUT_FAN_OUT);
    }

    /**
     * Legacy behavior: run each branch as a separate sub-automation and
     * join the results. Unchanged from before this node gained a "mode".
     */
    protected function executeAutomationMode(AutomationContext $context, array $config): ActionResult
    {
        $branches = $this->normalizeKeyValue($config['branches'] ?? []);
        if (empty($branches)) {
            return ActionResult::failed('At least one branch is required.');
        }

        $depth = (int) $context->get('_call_depth', 0);
        $max = (int) config('automations.max_call_depth', 3);
        if ($depth >= $max) {
            return ActionResult::failed("Maximum sub-automation depth ({$max}) reached.");
        }

        $joined = [];
        $failFast = (bool) ($config['fail_fast'] ?? false);

        foreach ($branches as $name => $ref) {
            $target = app(\Goldnead\StatamicAutomations\Contracts\AutomationRepository::class)->findByRef((string) $ref);
            if ($target === null) {
                if ($failFast) {
                    return ActionResult::failed("Branch '{$name}': automation '{$ref}' not found.");
                }
                $joined[$name] = ['status' => 'missing', 'automation' => $ref];

                continue;
            }

            $childData = $context->all();
            $childData['_call_depth'] = $depth + 1;
            $childContext = AutomationContext::make($childData, $context->isTestMode());

            $trigger = $target->nodes->first(fn ($n) => $n->type !== null && str_contains($n->type, 'manual'))
                ?? $target->nodes->first();

            $run = $this->runner->createRun($target, $childContext, $trigger);
            $finished = $this->runner->execute($run, $childContext);

            $joined[$name] = ['run_id' => $finished->id, 'status' => $finished->status];

            if ($failFast && $finished->status === AutomationRun::STATUS_FAILED) {
                return ActionResult::failed("Branch '{$name}' failed.", ['branches' => $joined]);
            }
        }

        return ActionResult::success(['branches' => $joined]);
    }
}
