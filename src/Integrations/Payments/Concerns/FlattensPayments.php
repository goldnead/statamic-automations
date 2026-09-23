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
            // Since the pause (payments P1). Null on every agreement that
            // never paused, and on a payments release older than the column.
            'paused_at' => $this->dateOf($subscription->paused_at ?? null),
            'resumes_at' => $this->dateOf($subscription->resumes_at ?? null),
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
            'paused_at' => 'string',
            'resumes_at' => 'string',
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
        $product = $this->filterValue($config, 'product');
        $offer = $this->filterValue($config, 'offer');
        $option = $this->filterValue($config, 'pricing_option');

        if ($product === null && $offer === null && $option === null) {
            return true;
        }

        // One candidate has to satisfy every filter that is set. Spread over
        // two candidates, "offer A" and "option of offer B" would both pass on
        // a renewal that carries A on the subscription and B on the payment.
        foreach ($this->productCandidates($event) as $candidate) {
            if ($product !== null && $candidate !== $product) {
                continue;
            }

            $parsed = ($offer !== null || $option !== null) ? self::parseOfferHandle($candidate) : null;

            if ($offer !== null && ($parsed === null || $parsed['offer'] !== $offer)) {
                continue;
            }

            if ($option !== null) {
                $wanted = self::parseOfferHandle($option);

                if ($parsed === null || $wanted === null || $wanted['option'] === null
                    || $parsed['offer'] !== $wanted['offer'] || $parsed['option'] !== $wanted['option']) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The product handles an event names, in the order they are checked.
     *
     * A subscription event names its product on the subscription, a payment
     * event on the payment, and `SubscriptionRenewed` carries both. A trigger
     * whose event names its models differently overrides this.
     *
     * Filtered on null rather than on falsiness. A plain array_filter drops
     * the string "0", and a product handle of "0" would then stop matching a
     * filter set to exactly that.
     *
     * @return list<string>
     */
    protected function productCandidates(object|array $event): array
    {
        return $this->handlesOf([
            $this->subscriptionOf($event)['product'] ?? null,
            $this->paymentOf($event)['product'] ?? null,
        ]);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    protected function handlesOf(array $values): array
    {
        return array_values(array_map(
            'strval',
            array_filter($values, fn ($value) => is_string($value) || is_int($value)),
        ));
    }

    /**
     * A configured filter value, or null when it is not set.
     *
     * @param  array<string, mixed>  $config
     */
    protected function filterValue(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : (is_int($value) ? (string) $value : null);
    }

    /**
     * Split a sold handle into the offer and the pricing option it names.
     *
     * `offer:kurs`, `offer:kurs:raten3`, `offer:kurs:=2500` and
     * `offer:kurs:+setup` are all a purchase of the offer `kurs`; only the
     * second names an option. The offers addon's own parser decides when it is
     * installed, because the prefix is configurable there and a second opinion
     * about the grammar would drift. Without it the same rule is applied here:
     * the offer ends at the first colon after the prefix, and a suffix is an
     * option only when it is a plain key.
     *
     * @return array{offer: string, option: string|null}|null
     */
    protected static function parseOfferHandle(string $handle): ?array
    {
        $parser = 'Goldnead\\StatamicOffers\\Support\\OfferHandle';

        if (class_exists($parser)) {
            try {
                $parsed = $parser::parse($handle);
            } catch (\Throwable) {
                $parsed = null;
            }

            return $parsed === null ? null : ['offer' => (string) $parsed->offer, 'option' => $parsed->option];
        }

        $prefix = (string) config('statamic-offers.handle_prefix', 'offer:');
        $prefix = $prefix === '' ? 'offer:' : $prefix;

        if (! str_starts_with($handle, $prefix)) {
            return null;
        }

        $parts = explode(':', substr($handle, strlen($prefix)), 2);

        if ($parts[0] === '') {
            return null;
        }

        $suffix = $parts[1] ?? null;
        $option = $suffix !== null && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $suffix) === 1 ? $suffix : null;

        return ['offer' => $parts[0], 'option' => $option];
    }

    /**
     * The product filter fields, identical on every trigger in this group.
     *
     * Three of them, because they answer three different questions. `product`
     * is the exact handle and stays exact: flows stored before the other two
     * existed rely on `offer:kurs` not also catching `offer:kurs:raten3`.
     * `offer` is every way of buying one offer, whichever option or amount.
     * `pricing_option` is exactly one option of one offer.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function productFilterSchema(): array
    {
        return [
            [
                'handle' => 'product',
                'label' => 'Product',
                'type' => 'select',
                'options_source' => 'payments.products',
                'required' => false,
                'help' => 'Leave empty for every product.',
            ],
            [
                'handle' => 'offer',
                'label' => 'Offer',
                'type' => 'select',
                'options_source' => 'offers.offers',
                'required' => false,
                'help' => 'Only purchases through this offer, whichever pricing option was chosen. Leave empty for every offer.',
            ],
            [
                'handle' => 'pricing_option',
                'label' => 'Pricing option',
                'type' => 'select',
                'options_source' => 'offers.pricing_options',
                'required' => false,
                'help' => 'Only purchases of exactly this pricing option, for example the instalment plan.',
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

    protected function stringOf(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function intOf(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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
