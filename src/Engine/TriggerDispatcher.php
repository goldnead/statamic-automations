<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;

/**
 * Generic entry point for event-driven triggers.
 *
 * Given a trigger handle and an incoming event, it finds every enabled
 * automation whose start node is of that trigger type, checks the
 * trigger's matches() predicate, builds the context and dispatches a run.
 *
 * This generalises the original per-event listeners so any number of
 * Statamic / integration events can be wired up with a one-line closure
 * in the ServiceProvider.
 */
class TriggerDispatcher
{
    public function __construct(
        protected TriggerRegistry $triggers,
        protected WorkflowRunner $runner,
        protected AutomationRepository $repository,
    ) {}

    public function dispatch(string $triggerHandle, object|array $event): void
    {
        $trigger = $this->triggers->instance($triggerHandle);
        if ($trigger === null) {
            return;
        }

        $automations = $this->repository->enabled()
            ->filter(fn ($automation) => $automation->nodes->contains(fn ($n) => $n->type === $triggerHandle));

        foreach ($automations as $automation) {
            $triggerNode = $automation->nodes->first(fn ($n) => $n->type === $triggerHandle);
            if ($triggerNode === null) {
                continue;
            }

            $config = $triggerNode->config ?? [];

            if (! $trigger->matches($event, $config)) {
                continue;
            }

            $context = $trigger->buildContext($event, $config);
            $run = $this->runner->createRun($automation, $context, $triggerNode);

            RunAutomation::dispatch($run->id, $context->all(), false);
        }
    }
}
