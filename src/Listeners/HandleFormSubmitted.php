<?php

namespace Goldnead\StatamicAutomations\Listeners;

use Goldnead\StatamicAutomations\Concerns\AppliesEnrollmentPolicy;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;

class HandleFormSubmitted
{
    use AppliesEnrollmentPolicy;

    public function __construct(
        protected TriggerRegistry $triggers,
        protected WorkflowRunner $runner,
        protected AutomationRepository $repository,
    ) {}

    public function handle(object $event): void
    {
        $trigger = $this->triggers->instance('form_submitted');
        if ($trigger === null) {
            return;
        }

        $automations = $this->repository->enabled()
            ->filter(fn ($automation) => $automation->nodes->contains(fn ($n) => $n->type === 'form_submitted'));

        foreach ($automations as $automation) {
            $triggerNode = $automation->nodes->first(fn ($n) => $n->type === 'form_submitted');
            if ($triggerNode === null) {
                continue;
            }

            if (! $trigger->matches($event, $triggerNode->config ?? [])) {
                continue;
            }

            $context = $trigger->buildContext($event, $triggerNode->config ?? []);
            // The trigger node’s re-entry policy. See {@see AppliesEnrollmentPolicy}.
            $run = $this->createEnrolledRun($this->runner, $automation, $triggerNode, $context);

            if ($run === null) {
                continue;
            }

            RunAutomation::dispatch($run->id, $context->all(), false);
        }
    }
}
