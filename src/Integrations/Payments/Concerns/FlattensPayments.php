<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Concerns;

/**
 * Turns the models a payment event carries into plain arrays.
 *
 * Every trigger in this namespace needs the same thing and for the same reason:
 * the event hands over an Eloquent model, the run context has to survive being
 * serialised into a queue payload, and a token like `{{ payment.product }}` has
 * to resolve on the other side. A model does none of that reliably.
 *
 * The field lists here are the triggers' public surface, not the table. They are
 * deliberately narrower than `toArray()`: a column added to `payments` next year
 * should not silently appear in every automation's data picker, and a column
 * renamed there should break one method here rather than every stored flow.
 *
 * `paymentOf()` returns exactly the nine keys the first three payment triggers
 * shipped with. That is not sentiment — those keys are in stored automations as
 * `{{ payment.* }}` tokens, and dropping one would break flows that already run.
 */
trait FlattensPayments
{
    /**
     * The payment, flattened.
     *
     * @return array<string, mixed>
     */
    protected function paymentOf(object|array $event, string $key = 'payment'): array
    {
        $payment = $this->propertyOf($event, $key);

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

    /**
     * The subscription, flattened.
     *
     * `times` and `times_charged` are both here because the pair is the only
     * way a flow can tell "instalment 3 of 12" from an open-ended plan, and the
     * difference decides what a renewal mail may say.
     *
     * @return array<string, mixed>
     */
    protected function subscriptionOf(object|array $event, string $key = 'subscription'): array
    {
        $subscription = $this->propertyOf($event, $key);

        if (is_array($subscription)) {
            return $subscription;
        }

        if (! is_object($subscription)) {
            return [];
        }

        return [
            'id' => $subscription->id ?? null,
            'product' => $subscription->product ?? null,
            'provider' => $subscription->provider ?? null,
            'amount_cent' => $subscription->amount_cent ?? null,
            'currency' => $subscription->currency ?? null,
            'interval' => $subscription->interval ?? null,
            'times' => $subscription->times ?? null,
            'times_charged' => $subscription->times_charged ?? null,
            'status' => $subscription->status ?? null,
            'email' => $subscription->email ?? null,
            'name' => $subscription->name ?? null,
            'starts_at' => $this->dateOf($subscription->starts_at ?? null),
            'next_payment_at' => $this->dateOf($subscription->next_payment_at ?? null),
            'cancelled_at' => $this->dateOf($subscription->cancelled_at ?? null),
            'ended_at' => $this->dateOf($subscription->ended_at ?? null),
        ];
    }

    /**
     * The output schema fragment for {@see paymentOf()}.
     *
     * One definition rather than nine copies: the schema and the flattening are
     * a promise to the editor, and a promise kept in two places drifts.
     *
     * @return array<string, string>
     */
    protected static function paymentOutputSchema(): array
    {
        return [
            'id' => 'string',
            'product' => 'string',
            'amount_cent' => 'integer',
            'currency' => 'string',
            'discount_code' => 'string',
            'status' => 'string',
            'email' => 'string',
            'name' => 'string',
            'provider' => 'string',
        ];
    }

    /**
     * The output schema fragment for {@see subscriptionOf()}.
     *
     * @return array<string, string>
     */
    protected static function subscriptionOutputSchema(): array
    {
        return [
            'id' => 'string',
            'product' => 'string',
            'provider' => 'string',
            'amount_cent' => 'integer',
            'currency' => 'string',
            'interval' => 'string',
            'times' => 'integer',
            'times_charged' => 'integer',
            'status' => 'string',
            'email' => 'string',
            'name' => 'string',
            'starts_at' => 'string',
            'next_payment_at' => 'string',
            'cancelled_at' => 'string',
            'ended_at' => 'string',
        ];
    }

    /**
     * Match the configured product against whichever of the two carries one.
     *
     * A subscription event names its product on the subscription, a payment
     * event on the payment, and `SubscriptionRenewed` carries both. Checking
     * both and accepting either keeps one filter field working across all of
     * them, which is what somebody configuring "only for the choir course"
     * expects.
     *
     * @param  array<string, mixed>  $config
     */
    protected function matchesProduct(object|array $event, array $config): bool
    {
        $product = $config['product'] ?? null;

        if (! $product) {
            return true;
        }

        $candidates = [
            $this->subscriptionOf($event)['product'] ?? null,
            $this->paymentOf($event)['product'] ?? null,
        ];

        // Filtered on null rather than on falsiness. A plain array_filter drops
        // the string "0", and a product handle of "0" would then stop matching
        // a filter set to exactly that.
        $candidates = array_filter($candidates, fn ($candidate) => $candidate !== null);

        return in_array($product, $candidates, true);
    }

    /**
     * The product filter field, identical on every trigger in this group.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function productFilterSchema(): array
    {
        return [
            [
                'handle' => 'product',
                'label' => 'Product',
                'type' => 'text',
                'required' => false,
                'help' => 'The product handle. Leave empty for every product.',
            ],
        ];
    }

    /**
     * Read one property off an event that may be an object or an array.
     *
     * These classes are loaded on sites where statamic-payments is not
     * installed, so "the shape I expected is not here" is a normal case and
     * must not throw inside a queue worker.
     */
    protected function propertyOf(object|array $event, string $key): mixed
    {
        return is_array($event) ? ($event[$key] ?? null) : ($event->{$key} ?? null);
    }

    /**
     * A date as an ISO-8601 string, or null.
     *
     * Carbon instances do survive the queue, but they arrive as objects a
     * template cannot print and a comparison node cannot compare. A string can
     * do both.
     */
    protected function dateOf(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
