<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationNode;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Iterate over a collection and run a sub-automation once per item.
 *
 * The items are read from a token (e.g. {{ trigger.entries }}) which the
 * executor resolves to a real array before this node runs. Each iteration
 * runs the chosen automation with the current item injected under the
 * configured key (default {{ item }}) and the zero-based {{ loop.index }}.
 *
 * Delegating each iteration to a sub-run keeps the loop body reusable and
 * avoids re-entering the graph walker, which executes one path at a time.
 */
class LoopNode implements AutomationNode
{
    public function __construct(protected WorkflowRunner $runner)
    {
    }

    public static function handle(): string
    {
        return 'loop';
    }

    public static function label(): string
    {
        return 'Loop (for each)';
    }

    public static function description(): ?string
    {
        return 'Runs a sub-automation once for every item in a collection.';
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
                'handle' => 'items',
                'label' => 'Items',
                'type' => 'text',
                'required' => true,
                'help' => 'A token resolving to an array, e.g. {{ trigger.entries }}.',
            ],
            [
                'handle' => 'automation',
                'label' => 'Automation to run per item (handle or id)',
                'type' => 'text',
                'required' => true,
            ],
            [
                'handle' => 'item_key',
                'label' => 'Item variable name',
                'type' => 'text',
                'default' => 'item',
                'help' => 'Each item is exposed under this key, e.g. {{ item }}.',
            ],
            [
                'handle' => 'max_iterations',
                'label' => 'Max iterations',
                'type' => 'integer',
                'default' => 100,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $items = $config['items'] ?? [];
        if (! is_array($items)) {
            $items = $items === null || $items === '' ? [] : [$items];
        }

        $ref = $config['automation'] ?? null;
        if (empty($ref)) {
            return ActionResult::failed('A target automation is required for the loop body.');
        }

        $depth = (int) $context->get('_call_depth', 0);
        $max = (int) config('automations.max_call_depth', 3);
        if ($depth >= $max) {
            return ActionResult::failed("Maximum sub-automation depth ({$max}) reached.");
        }

        $target = app(\Goldnead\StatamicAutomations\Contracts\AutomationRepository::class)->findByRef((string) $ref);
        if ($target === null) {
            return ActionResult::failed("Automation '{$ref}' not found.");
        }

        $itemKey = (string) ($config['item_key'] ?? 'item') ?: 'item';
        $maxIterations = max(1, (int) ($config['max_iterations'] ?? 100));
        $items = array_slice(array_values($items), 0, $maxIterations);

        $results = [];
        $failed = 0;

        foreach ($items as $index => $item) {
            $childData = $context->all();
            $childData[$itemKey] = $item;
            $childData['loop'] = ['index' => $index, 'total' => count($items)];
            $childData['_call_depth'] = $depth + 1;

            $childContext = AutomationContext::make($childData, $context->isTestMode());
            $trigger = $target->nodes->first(fn ($n) => $n->type !== null && str_contains($n->type, 'manual'))
                ?? $target->nodes->first();

            $run = $this->runner->createRun($target, $childContext, $trigger);
            $finished = $this->runner->execute($run, $childContext);

            if ($finished->status === \Goldnead\StatamicAutomations\Models\AutomationRun::STATUS_FAILED) {
                $failed++;
            }

            $results[] = ['index' => $index, 'run_id' => $finished->id, 'status' => $finished->status];
        }

        return ActionResult::success([
            'iterations' => count($items),
            'failed' => $failed,
            'runs' => $results,
        ]);
    }
}
