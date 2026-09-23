<?php

namespace Goldnead\StatamicAutomations\Integrations\Funnels\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * Somebody bought, and then said no to the offer after it.
 *
 * Narrower than `funnels.offer_declined`, which fires on every no, including
 * the visitor who declined the first offer and bought nothing. This one fires
 * only when the run already has a paid purchase, so the buyer is known and
 * `payment` is what they bought: the moment for "you have the course, the
 * recordings are still available".
 *
 * Both fire on the same click. A site that wants the follow-up only for
 * buyers takes this one and leaves the other alone.
 *
 * `payment` is flattened exactly like on the payments triggers, so a token
 * written for `payments.paid` works here unchanged.
 */
class UpsellDeclinedTrigger extends FunnelOfferDeclinedTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'funnels.upsell_declined';
    }

    public static function label(): string
    {
        return 'Upsell Declined';
    }

    public static function description(): ?string
    {
        return 'Triggered when a buyer declines an offer after a paid purchase in the same funnel. Carries what they bought. funnels.offer_declined fires on the same click.';
    }

    public static function schema(): array
    {
        return array_merge(parent::schema(), [
            [
                'handle' => 'bought_offer',
                'label' => 'Bought offer',
                'type' => 'select',
                'options_source' => 'offers.offers',
                'required' => false,
                'help' => 'Only when the purchase before was this offer, whichever pricing option. Leave empty for every purchase.',
            ],
        ]);
    }

    public static function outputSchema(): array
    {
        $schema = parent::outputSchema();
        $email = $schema['email'];
        unset($schema['email']);

        return $schema + [
            'payment' => self::paymentOutputSchema(),
            'email' => $email,
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        if (! parent::matches($event, $config)) {
            return false;
        }

        $bought = $this->filterValue($config, 'bought_offer');

        if ($bought === null) {
            return true;
        }

        $product = $this->paymentOf($event)['product'] ?? null;
        $parsed = is_string($product) ? self::parseOfferHandle($product) : null;

        return $parsed !== null && $parsed['offer'] === $bought;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $visit = $this->visitOf($event);
        $payment = $this->paymentOf($event);

        return AutomationContext::make([
            'visit' => $visit,
            'step' => $this->stepOf($event),
            'payment' => $payment,
            // The buyer's address. The visit usually has it; the payment
            // always does, and a visit that started without a form does not.
            'email' => $visit['email'] ?? ($payment['email'] ?? null),
        ]);
    }

    /**
     * The step, with the declined offer taken from the event.
     *
     * The event names the offer itself (`offerHandle`); it is preferred over
     * the step's config because it is what the funnels addon decided was
     * declined.
     *
     * @return array<string, mixed>
     */
    protected function stepOf(object|array $event): array
    {
        $step = parent::stepOf($event);
        $offer = $this->propertyOf($event, 'offerHandle');

        if (is_string($offer) && $offer !== '') {
            $step['offer'] = $offer;
        }

        return $step;
    }
}
