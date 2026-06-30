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
 * Fan out to several branch automations and join their results before the
 * flow continues. Each branch is a named sub-automation that receives the
 * current context. Branch outputs are collected under {{ <node>.branches }}
 * keyed by branch name, so a downstream node sees a single joined result.
 *
 * Execution is sequential within one run (the engine runs a run to
 * completion synchronously); the value here is the scatter/gather shape and
 * the join, not OS-level concurrency.
 */
class ParallelNode implements AutomationNode
{
    use NormalizesKeyValue;

    public function __construct(protected WorkflowRunner $runner)
    {
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
        return 'Runs several branch automations and joins their results before continuing.';
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
                'handle' => 'branches',
                'label' => 'Branches',
                'type' => 'key_value',
                'required' => true,
                'help' => 'Branch name → automation handle. Each runs with the current context.',
            ],
            [
                'handle' => 'fail_fast',
                'label' => 'Fail if any branch fails',
                'type' => 'toggle',
                'default' => false,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
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
