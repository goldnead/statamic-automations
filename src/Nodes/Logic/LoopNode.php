<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationNode;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Iterate over a collection, driving the downstream graph once per item.
 *
 * The items are read from a token (e.g. {{ trigger.entries }}) which the
 * executor resolves to a real array before this node runs.
 *
 * Two modes:
 * - "inline" (default): this node only resolves + validates the items and
 *   signals which output to take. The actual per-item iteration is driven
 *   by {@see \Goldnead\StatamicAutomations\Engine\WorkflowRunner}, which
 *   re-walks the subgraph reachable from the "loop" output once per item
 *   (injecting {{ item }} / {{ loop.* }} into the run context for the
 *   duration of that pass), then continues via the "done" output once all
 *   items are exhausted (or immediately if there are none).
 * - "automation" (legacy): runs a separate target automation once per
 *   item as a sub-run, exactly as this node originally worked. Kept for
 *   backwards compatibility with existing wiring.
 */
class LoopNode implements AutomationNode
{
    /** Output handle taken once per item — wire the loop body here. */
    public const OUTPUT_LOOP = 'loop';

    /** Output handle taken once, after all items have been processed. */
    public const OUTPUT_DONE = 'done';

    public function __construct(protected WorkflowRunner $runner)
    {
    }

    /**
     * Output handles this node can route through. Inline mode uses both;
     * legacy automation mode always routes via the default "success" handle.
     *
     * @return array<int, string>
     */
    public static function outputs(): array
    {
        return [self::OUTPUT_LOOP, self::OUTPUT_DONE];
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
                'handle' => 'mode',
                'label' => 'Mode',
                'type' => 'select',
                'options' => [
                    ['value' => 'inline', 'label' => 'Run the connected nodes for each item'],
                    ['value' => 'automation', 'label' => 'Run a separate automation for each item (legacy)'],
                ],
                'default' => 'inline',
            ],
            [
                'handle' => 'automation',
                'label' => 'Automation to run per item (handle or id)',
                'type' => 'text',
                'required' => false,
                'help' => 'Only used when Mode is "Run a separate automation".',
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
        $mode = (string) ($config['mode'] ?? 'inline') ?: 'inline';

        return $mode === 'automation'
            ? $this->executeAutomationMode($context, $config)
            : $this->executeInlineMode($config);
    }

    /**
     * Resolve + validate the items and hand control back to the
     * WorkflowRunner, which drives the "loop" subgraph once per item and
     * then continues via "done". This node itself never iterates.
     */
    protected function executeInlineMode(array $config): ActionResult
    {
        $items = $this->normalizeItems($config['items'] ?? []);
        $itemKey = (string) ($config['item_key'] ?? 'item') ?: 'item';
        $maxIterations = max(1, (int) ($config['max_iterations'] ?? 100));
        $items = array_slice($items, 0, $maxIterations);

        if (empty($items)) {
            return ActionResult::success(
                ['iterations' => 0, 'items' => [], 'item_key' => $itemKey],
                self::OUTPUT_DONE,
            );
        }

        return ActionResult::success(
            ['iterations' => count($items), 'items' => $items, 'item_key' => $itemKey],
            self::OUTPUT_LOOP,
        );
    }

    /**
     * Legacy behavior: run a separate target automation once per item as
     * a sub-run. Routes via the default "success" handle (not loop/done).
     */
    protected function executeAutomationMode(AutomationContext $context, array $config): ActionResult
    {
        $items = $this->normalizeItems($config['items'] ?? []);

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
        $items = array_slice($items, 0, $maxIterations);

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

    protected function normalizeItems(mixed $items): array
    {
        if (! is_array($items)) {
            $items = $items === null || $items === '' ? [] : [$items];
        }

        return array_values($items);
    }
}
