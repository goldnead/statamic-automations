<?php

namespace Goldnead\StatamicAutomations\Integrations\Funnels\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

/**
 * Somebody bought something in a funnel.
 *
 * Fired after the payment is paid, not when the button was clicked. The
 * distinction is the one this whole family is built on, and an automation that
 * delivered on acceptance rather than on payment would give the goods away.
 */
class FunnelOfferAcceptedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'funnels.offer_accepted';
    }

    public static function label(): string
    {
        return 'Funnel Offer Accepted';
    }

    public static function description(): ?string
    {
        return 'Triggered when a visitor accepts an offer in a funnel and the payment went through.';
    }

    public static function group(): string
    {
        return 'Funnels';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'funnel',
                'label' => 'Funnel',
                'type' => 'text',
                'required' => false,
                'help' => 'The funnel handle. Leave empty for every funnel.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'visit' => [
                'id' => 'string',
                'email' => 'string',
                'name' => 'string',
                'funnel' => 'string',
                'funnel_title' => 'string',
                'payment_id' => 'string',
                'completed_at' => 'datetime',
            ],
            'step' => [
                'key' => 'string',
                'type' => 'string',
                'label' => 'string',
            ],
            'payment' => [
                'id' => 'string',
                'product' => 'string',
                'amount_cent' => 'integer',
                'currency' => 'string',
                'status' => 'string',
                'email' => 'string',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $handle = $config['funnel'] ?? null;

        if (! $handle) {
            return true;
        }

        return ($this->visitOf($event)['funnel'] ?? null) === $handle;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'visit' => $this->visitOf($event),
            'step' => $this->stepOf($event),
            'payment' => $this->paymentOf($event),
        ]);
    }

    /**
     * The walk, flattened.
     *
     * Read defensively rather than typed against: this file must compile and
     * behave on a site where the funnels addon is not installed at all, which
     * is exactly why the parameter is `object|array`.
     *
     * @return array<string, mixed>
     */
    protected function visitOf(object|array $event): array
    {
        $visit = is_array($event) ? ($event['visit'] ?? null) : ($event->visit ?? null);

        if (is_array($visit)) {
            return $visit;
        }

        if (! is_object($visit)) {
            return [];
        }

        return [
            'id' => $visit->id ?? null,
            'email' => $visit->email ?? null,
            'name' => $visit->name ?? null,
            'funnel' => $visit->funnel->handle ?? null,
            'funnel_title' => $visit->funnel->title ?? null,
            'payment_id' => $visit->payment_id ?? null,
            'completed_at' => $visit->completed_at ?? null,
        ];
    }

    protected function stepOf(object|array $event): array
    {
        $step = is_array($event) ? ($event['step'] ?? null) : ($event->step ?? null);

        if (is_array($step)) {
            return $step;
        }

        if (! is_object($step)) {
            return [];
        }

        return [
            'key' => $step->node_key ?? null,
            'type' => $step->type ?? null,
            'label' => $step->label ?? null,
        ];
    }

    /**
     * The payment, flattened.
     *
     * @return array<string, mixed>
     */
    protected function paymentOf(object|array $event): array
    {
        $payment = is_array($event) ? ($event['payment'] ?? null) : ($event->payment ?? null);

        if (is_array($payment)) {
            return $payment;
        }

        if (! is_object($payment)) {
            return [];
        }

        return [
            'id' => $payment->id ?? null,
            'product' => $payment->product ?? null,
            'amount_cent' => $payment->amount_cent ?? null,
            'currency' => $payment->currency ?? null,
            'discount_code' => $payment->discount_code ?? null,
            'status' => $payment->status ?? null,
            'email' => $payment->email ?? null,
            'name' => $payment->name ?? null,
            'provider' => $payment->provider ?? null,
        ];
    }
}
