<?php

namespace Goldnead\StatamicAutomations\Integrations\Offers\Triggers;

use Goldnead\StatamicAutomations\Support\RestartPolicy;

/**
 * A paid purchase used a coupon.
 *
 * Filterable by the code, in any case, because that is what a campaign is
 * named after. The payment is flattened like on the payments triggers.
 * The offers addon notes that a redelivered payment webhook can announce the
 * same redemption twice; "only once per person" on the trigger catches that.
 */
class CouponRedeemedTrigger extends OfferTrigger
{
    public static function handle(): string
    {
        return 'offers.coupon_redeemed';
    }

    public static function label(): string
    {
        return 'Coupon Redeemed';
    }

    public static function description(): ?string
    {
        return 'Triggered when a paid purchase used a coupon. Filterable by the code.';
    }

    public static function schema(): array
    {
        // The two re-entry fields every trigger gets, declared here with this
        // trigger's defaults so the editor shows what the engine will do
        // (see enrollmentDefaults()).
        $reentry = array_map(function (array $field) {
            $defaults = self::enrollmentDefaults();

            if (isset($defaults[$field['handle']])) {
                $field['default'] = $defaults[$field['handle']];
            }

            if ($field['handle'] === RestartPolicy::SUBJECT_CONFIG_KEY) {
                $field['help'] = 'Set to {{ payment.id }}: a redelivered payment starts no second run. Change it to {{ email }} for once per buyer.';
            }

            return $field;
        }, RestartPolicy::triggerSchema());

        return [
            [
                'handle' => 'code',
                'label' => 'Coupon code',
                'type' => 'text',
                'required' => false,
                'help' => 'Only this code, in any case. Leave empty for every coupon.',
            ],
            ...$reentry,
        ];
    }

    /**
     * Once per payment, unless the node says otherwise.
     *
     * Older offers releases can announce the same redemption twice when the
     * payment webhook is delivered twice. The family's re-entry rule does the
     * rest: "ignore" with the payment as the subject.
     *
     * @return array<string, string>
     */
    public static function enrollmentDefaults(): array
    {
        return [
            RestartPolicy::CONFIG_KEY => RestartPolicy::Ignore->value,
            RestartPolicy::SUBJECT_CONFIG_KEY => '{{ payment.id }}',
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'coupon' => [
                'id' => 'integer',
                'code' => 'string',
                'name' => 'string',
                'percent' => 'integer',
                'amount_cent' => 'integer',
                'currency' => 'string',
            ],
            'discount_cent' => 'integer',
            'currency' => 'string',
            'buyer' => ['email' => 'string', 'name' => 'string'],
            'payment' => self::paymentOutputSchema(),
            'email' => 'string',
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $code = $this->filterValue($config, 'code');

        if ($code === null) {
            return true;
        }

        $coupon = $this->propertyOf($event, 'coupon');
        $actual = is_object($coupon) ? $this->stringOf($coupon->code ?? null) : null;

        return $actual !== null && mb_strtolower($actual) === mb_strtolower($code);
    }

    protected function context(object|array $event): array
    {
        $coupon = $this->propertyOf($event, 'coupon');
        $payment = $this->paymentOf($event);

        return [
            'coupon' => is_object($coupon) ? [
                'id' => $this->intOf($coupon->id ?? null),
                'code' => $this->stringOf($coupon->code ?? null),
                'name' => $this->stringOf($coupon->name ?? null),
                'percent' => $this->intOf($coupon->percent ?? null),
                'amount_cent' => $this->intOf($coupon->amount_cent ?? null),
                'currency' => $this->stringOf($coupon->currency ?? null),
            ] : [],
            'discount_cent' => $this->intOf($this->propertyOf($this->propertyOf($event, 'payment') ?? [], 'discount_cent')),
            'currency' => $payment['currency'] ?? null,
            'buyer' => ['email' => $payment['email'] ?? null, 'name' => $payment['name'] ?? null],
            'payment' => $payment,
            'email' => $payment['email'] ?? null,
        ];
    }
}
