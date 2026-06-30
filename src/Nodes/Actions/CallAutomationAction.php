<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\NormalizesKeyValue;

/**
 * Runs another automation as a sub-flow, optionally passing a mapped
 * context and waiting for it to finish. Guards against runaway recursion
 * via a depth counter carried in the context.
 */
class CallAutomationAction implements AutomationAction
{
    use NormalizesKeyValue;

    public function __construct(protected WorkflowRunner $runner)
    {
    }

    public static function handle(): string
    {
        return 'call_automation';
    }

    public static function label(): string
    {
        return 'Call Automation';
    }

    public static function description(): ?string
    {
        return 'Runs another automation as a sub-flow, optionally waiting for the result.';
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
                'handle' => 'automation',
                'label' => 'Automation (handle or id)',
                'type' => 'text',
                'required' => true,
            ],
            [
                'handle' => 'wait',
                'label' => 'Wait for completion',
                'type' => 'toggle',
                'default' => true,
                'help' => 'When off, the sub-automation is queued and this flow continues immediately.',
            ],
            [
                'handle' => 'context',
                'label' => 'Context to pass',
                'type' => 'key_value',
                'required' => false,
                'help' => 'Leave empty to pass the current context unchanged.',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $ref = $config['automation'] ?? null;
        if (empty($ref)) {
            return ActionResult::failed('A target automation is required.');
        }

        $depth = (int) $context->get('_call_depth', 0);
        $max = (int) config('automations.max_call_depth', 3);
        if ($depth >= $max) {
            return ActionResult::failed("Maximum sub-automation depth ({$max}) reached.");
        }

        $target = Automation::query()->where('handle', $ref)->orWhere('id', $ref)->first();
        if ($target === null) {
            return ActionResult::failed("Automation '{$ref}' not found.");
        }

        $mapped = $this->normalizeKeyValue($config['context'] ?? []);
        $childData = $mapped ?: $context->all();
        $childData['_call_depth'] = $depth + 1;

        $childContext = AutomationContext::make($childData, $context->isTestMode());
        $trigger = $target->nodes->first(fn ($n) => $n->type !== null && str_contains($n->type, 'manual'))
            ?? $target->nodes->first();

        $run = $this->runner->createRun($target, $childContext, $trigger);

        if (! (bool) ($config['wait'] ?? true)) {
            RunAutomation::dispatch($run->id, $childContext->all(), $context->isTestMode());

            return ActionResult::success(['child_run_id' => $run->id, 'queued' => true]);
        }

        $finished = $this->runner->execute($run, $childContext);

        return ActionResult::success([
            'child_run_id' => $finished->id,
            'child_status' => $finished->status,
        ]);
    }
}
