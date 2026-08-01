<?php

namespace Goldnead\StatamicAutomations\Nodes\Logic;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationLogicNode;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\DeclaresOutputs;
use Goldnead\StatamicAutomations\Support\NodeOutputs;

/**
 * Iterate over a collection, driving the downstream graph once per item.
 *
 * The items are read from a token (e.g. {{ trigger.entries }}) which the
 * executor resolves to a real array before this node runs.
 *
 * Two modes:
 * - "inline" (default): this node only resolves + validates the items and
 *   signals which output to take. The actual per-item iteration is driven
 *   by {@see WorkflowRunner}, which
 *   re-walks the subgraph reachable from the "loop" output once per item
 *   (injecting {{ item }} / {{ index }} / {{ loop.* }} into the run
 *   context for the duration of that pass), then continues via the "done"
 *   output once all items are exhausted (or immediately if there are
 *   none).
 * - "automation" (legacy): runs a separate target automation once per
 *   item as a sub-run, exactly as this node originally worked. Kept for
 *   backwards compatibility with existing wiring.
 */
class LoopNode implements AutomationLogicNode
{
    use DeclaresOutputs;

    /** Output handle taken once per item — wire the loop body here. */
    public const OUTPUT_LOOP = 'loop';

    /** Output handle taken once, after all items have been processed. */
    public const OUTPUT_DONE = 'done';

    public function __construct(protected WorkflowRunner $runner) {}

    /**
     * The body runs once per item off `loop` and the flow continues on
     * `done` — no loop-back edge, hence the plain labels over the more
     * mechanical "Loop"/"Done", which read as if the body had to close a
     * loop. Legacy automation mode routes via neither (it returns the
     * default success handle), but declaring both regardless keeps a graph
     * built in one mode wireable in the other.
     *
     * `done` is the continuation: it is where the flow goes *after* the
     * loop, so it is what Duplicate and insert-on-edge attach to. Before
     * 1.7.0 they took the first output and dropped the copy inside the loop
     * body.
     *
     * @return array<string, mixed>
     */
    public static function outputSpec(): array
    {
        return NodeOutputs::fixed([
            ['handle' => self::OUTPUT_LOOP, 'label' => 'For each item'],
            ['handle' => self::OUTPUT_DONE, 'label' => 'After loop'],
        ], primary: self::OUTPUT_DONE);
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
        return 'Iterates over a collection. The "For each item" output runs its connected nodes once per item; once every item has run, the flow automatically continues via "After loop" — no loop-back edge needed.';
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
                'tokenable' => true,
                'help' => 'A token resolving to an array, e.g. {{ trigger.entries }}. Connect the nodes to repeat per item to the "For each item" output; they run automatically once per item, then the flow continues via "After loop" — no loop-back edge needed.',
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

        $target = app(AutomationRepository::class)->findByRef((string) $ref);
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

            if ($finished->status === AutomationRun::STATUS_FAILED) {
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
