<?php

namespace Goldnead\StatamicAutomations\Listeners;

use Goldnead\StatamicAutomations\Concerns\RunsAutomationsForEvent;

/**
 * Brings the funnel and payment addons' events into the engine.
 *
 * Both addons already fired these and nothing could hear them: the trigger
 * nodes existed nowhere, so a site that wanted "send the course when the
 * payment goes through" had to write a listener by hand, which is exactly the
 * work the automations addon exists to remove.
 *
 * Thirteen events, all of them: four funnel events and the payments addon's
 * full nine. The payments map covered three of those nine for a while, and the
 * six it left out were the ones about money going back and about subscriptions
 * starting, renewing and ending.
 *
 * Same shape as {@see HandleLeadHubEvent}, deliberately: an event class mapped
 * to a trigger handle, registered only when the sibling is installed. The loop
 * that turns a handle into runs lives in {@see RunsAutomationsForEvent}, shared
 * with {@see HandleCommerceEvent}.
 */
class HandleFunnelOrPaymentEvent
{
    use RunsAutomationsForEvent;

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
        'Goldnead\\StatamicPayments\\Events\\CheckoutAbandoned' => 'payments.checkout_abandoned',
        'Goldnead\\StatamicPayments\\Events\\PaymentRefunded' => 'payments.refunded',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionStarted' => 'payments.subscription_started',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionRenewed' => 'payments.subscription_renewed',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionCancelled' => 'payments.subscription_cancelled',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionEnded' => 'payments.subscription_ended',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionStartFailed' => 'payments.subscription_start_failed',
    ];

    public function handle(object $event): void
    {
        $handle = $this->handleForEvent(
            $event,
            self::FUNNEL_TRIGGERS,
            self::PAYMENT_TRIGGERS,
        );

        if ($handle === null) {
            return;
        }

        $this->runFor($handle, $event);
    }
}
