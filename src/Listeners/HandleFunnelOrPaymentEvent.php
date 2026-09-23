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
 * Every event either addon fires that a flow can act on: six funnel events
 * and twenty from payments. The payments map covered three for a while, and
 * the six it left out were the ones about money going back and about
 * subscriptions starting, renewing and ending. The Suite build of 23.09.2026
 * added eleven more (pause, reminders, card, n-th failure, plan change,
 * blocked checkout, chargeback), the declined offer and the declined upsell
 * after a purchase. Deliberately not
 * mapped: `SubscriptionCycleFailed`, which fires per webhook delivery and is
 * covered once per failure by `SubscriptionAttemptFailed`, and
 * `PaymentCommunicationLogged`, which is a log line, not a moment.
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
        'Goldnead\\StatamicFunnels\\Events\\FunnelOfferDeclined' => 'funnels.offer_declined',
        'Goldnead\\StatamicFunnels\\Events\\UpsellDeclined' => 'funnels.upsell_declined',
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
        // Suite build 23.09.2026: pause, reminders, card, n-th failure, plan
        // paid off, plan change, replacement, blocked checkout, chargeback.
        'Goldnead\\StatamicPayments\\Events\\SubscriptionPaused' => 'payments.subscription_paused',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionResumed' => 'payments.subscription_resumed',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionPaymentUpcoming' => 'payments.subscription_payment_upcoming',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionCardExpiring' => 'payments.subscription_card_expiring',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionCardExpired' => 'payments.subscription_card_expired',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionAttemptFailed' => 'payments.subscription_attempt_failed',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionPlanCompleted' => 'payments.subscription_plan_completed',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionChanged' => 'payments.subscription_changed',
        'Goldnead\\StatamicPayments\\Events\\SubscriptionReplaced' => 'payments.subscription_replaced',
        'Goldnead\\StatamicPayments\\Events\\CheckoutBlocked' => 'payments.checkout_blocked',
        'Goldnead\\StatamicPayments\\Events\\PaymentChargedBack' => 'payments.charged_back',
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
