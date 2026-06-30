<?php

namespace Goldnead\StatamicAutomations\Listeners;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;

class HandleEntryPublished
{
    public function __construct(
        protected TriggerRegistry $triggers,
        protected WorkflowRunner $runner,
        protected AutomationRepository $repository,
    ) {
    }

    public function handle(object $event): void
    {
        $trigger = $this->triggers->instance('entry_published');
        if ($trigger === null) {
            return;
        }

        $automations = $this->repository->enabled()
            ->filter(fn ($automation) => $automation->nodes->contains(fn ($n) => $n->type === 'entry_published'));

        foreach ($automations as $automation) {
            $triggerNode = $automation->nodes->first(fn ($n) => $n->type === 'entry_published');
            if ($triggerNode === null) {
                continue;
            }

            if (! $trigger->matches($event, $triggerNode->config ?? [])) {
                continue;
            }

            $context = $trigger->buildContext($event, $triggerNode->config ?? []);
            $run = $this->runner->createRun($automation, $context, $triggerNode);

            RunAutomation::dispatch($run->id, $context->all(), false);
        }
    }
}
