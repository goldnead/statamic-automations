<?php

namespace Goldnead\StatamicAutomations\Listeners;

use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;

class HandleFormSubmitted
{
    public function __construct(
        protected TriggerRegistry $triggers,
        protected WorkflowRunner $runner,
    ) {
    }

    public function handle(object $event): void
    {
        $trigger = $this->triggers->instance('form_submitted');
        if ($trigger === null) {
            return;
        }

        $automations = Automation::query()
            ->where('enabled', true)
            ->whereHas('nodes', function ($q) {
                $q->where('type', 'form_submitted');
            })
            ->with('nodes')
            ->get();

        foreach ($automations as $automation) {
            $triggerNode = $automation->nodes->first(fn ($n) => $n->type === 'form_submitted');
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
