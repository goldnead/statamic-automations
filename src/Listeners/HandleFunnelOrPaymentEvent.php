<?php

namespace Goldnead\StatamicAutomations\Listeners;

use Goldnead\StatamicAutomations\Concerns\AppliesEnrollmentPolicy;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;

/**
 * Brings the funnel and payment addons' events into the engine.
 *
 * Both addons already fired these — four in funnels, two in payments — and
 * nothing could hear them. The trigger nodes existed nowhere, so a site that
 * wanted "send the course when the payment goes through" had to write a
 * listener by hand, which is exactly the work the automations addon exists to
 * remove.
 *
 * Same shape as {@see HandleLeadHubEvent}, deliberately: an event class mapped
 * to a trigger handle, registered only when the sibling is installed.
 */
class HandleFunnelOrPaymentEvent
{
    use AppliesEnrollmentPolicy;

    /** Funnel event class => automation trigger handle. */
    public const FUNNEL_TRIGGERS = [
        'Goldnead\\StatamicFunnels\\Events\\FunnelCompleted' => 'funnels.completed',
        'Goldnead\\StatamicFunnels\\Events\\FunnelFormSubmitted' => 'funnels.form_submitted',
        'Goldnead\\StatamicFunnels\\Events\\FunnelStepEntered' => 'funnels.step_entered',
        'Goldnead\\StatamicFunnels\\Events\\FunnelOfferAccepted' => 'funnels.offer_accepted',
    ];

    /** Payment event class => automation trigger handle. */
    public const PAYMENT_TRIGGERS = [
        'Goldnead\\StatamicPayments\\Events\\PaymentPaid' => 'payments.paid',
        'Goldnead\\StatamicPayments\\Events\\PaymentFailed' => 'payments.failed',
    ];

    public function __construct(
        protected TriggerRegistry $triggers,
        protected WorkflowRunner $runner,
    ) {}

    public function handle(object $event): void
    {
        $handle = self::FUNNEL_TRIGGERS[$event::class]
            ?? self::PAYMENT_TRIGGERS[$event::class]
            ?? null;

        if ($handle === null) {
            return;
        }

        $trigger = $this->triggers->instance($handle);

        if ($trigger === null || ! Automation::schemaReady()) {
            return;
        }

        $automations = Automation::query()
            ->where('enabled', true)
            ->whereHas('nodes', fn ($q) => $q->where('type', $handle))
            ->with('nodes')
            ->get();

        foreach ($automations as $automation) {
            $node = $automation->nodes->first(fn ($n) => $n->type === $handle);

            if ($node === null || ! $trigger->matches($event, $node->config ?? [])) {
                continue;
            }

            $context = $trigger->buildContext($event, $node->config ?? []);
            $run = $this->createEnrolledRun($this->runner, $automation, $node, $context);

            if ($run === null) {
                continue;
            }

            RunAutomation::dispatch($run->id, $context->all(), false);
        }
    }
}
