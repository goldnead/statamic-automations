<?php

namespace Goldnead\StatamicAutomations\Integrations\Offers\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * What the eight offers triggers share: the flattening of offer, seat pool,
 * seat and coupon, and the offer filter.
 *
 * The fields are the ones the offers addon sends to its webhooks under the
 * same handles (`WebhookPayload` in statamic-offers), so a flow and a webhook
 * describe one moment in one shape. What is never here: a seat's claim token
 * and a pool's manage token. They are links that act on somebody's behalf,
 * and a run context is stored, shown in the CP log and can be sent on by a
 * webhook node.
 *
 * `email` at the top level is the person the run is about: the seat for seat
 * events, the buyer who owns the pool for pool events, the buyer for a coupon.
 * It is the default subject path, so "only once per person" works without a
 * subject key.
 */
abstract class OfferTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function group(): string
    {
        return 'Offers';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [self::offerFilterField()];
    }

    public function matches(object|array $event, array $config): bool
    {
        $offer = $this->filterValue($config, 'offer');

        return $offer === null || $this->offerHandleOf($event) === $offer;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make($this->context($event));
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function context(object|array $event): array;

    /**
     * @return array<string, mixed>
     */
    protected static function offerFilterField(): array
    {
        return [
            'handle' => 'offer',
            'label' => 'Offer',
            'type' => 'select',
            'options_source' => 'offers.offers',
            'required' => false,
            'help' => 'Leave empty for every offer.',
        ];
    }

    /** The handle of the offer the event is about, through the pool if need be. */
    protected function offerHandleOf(object|array $event): ?string
    {
        $offer = $this->propertyOf($event, 'offer');

        if (is_object($offer)) {
            return $this->stringOf($offer->handle ?? null);
        }

        $pool = $this->propertyOf($event, 'pool');

        return is_object($pool) ? $this->stringOf($pool->offer ?? null) : null;
    }

    /**
     * @return array{id: int|null, handle: string|null, name: string|null}
     */
    protected function offerOf(mixed $offer): array
    {
        if (! is_object($offer)) {
            return ['id' => null, 'handle' => null, 'name' => null];
        }

        return [
            'id' => $this->intOf($offer->id ?? null),
            'handle' => $this->stringOf($offer->handle ?? null),
            'name' => $this->stringOf($offer->name ?? null),
        ];
    }

    /**
     * The pool's offer: the model where the pool can load it, else the handle
     * it names.
     *
     * @return array{id: int|null, handle: string|null, name: string|null}
     */
    protected function offerOfPool(mixed $pool): array
    {
        if (! is_object($pool)) {
            return $this->offerOf(null);
        }

        $model = null;

        if (method_exists($pool, 'offerModel')) {
            try {
                $model = $pool->offerModel();
            } catch (\Throwable) {
                $model = null;
            }
        }

        return is_object($model)
            ? $this->offerOf($model)
            : ['id' => null, 'handle' => $this->stringOf($pool->offer ?? null), 'name' => null];
    }

    /**
     * @return array<string, mixed>
     */
    protected function poolOf(mixed $pool): array
    {
        if (! is_object($pool)) {
            return [];
        }

        $taken = null;

        if (method_exists($pool, 'takenCount')) {
            try {
                $taken = (int) $pool->takenCount();
            } catch (\Throwable) {
                $taken = null;
            }
        }

        return [
            'id' => $this->intOf($pool->id ?? null),
            'product' => $this->stringOf($pool->product ?? null),
            'seats' => $this->intOf($pool->seats ?? null),
            'taken' => $taken,
            'owner' => [
                'email' => $this->stringOf($pool->owner_email ?? null),
                'name' => $this->stringOf($pool->owner_name ?? null),
            ],
            'payment_id' => $this->intOf($pool->payment_id ?? null),
            'closed_at' => $this->dateOf($pool->closed_at ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function seatOf(mixed $seat): array
    {
        if (! is_object($seat)) {
            return [];
        }

        return [
            'id' => $this->intOf($seat->id ?? null),
            'email' => $this->stringOf($seat->email ?? null),
            'name' => $this->stringOf($seat->name ?? null),
            'status' => $this->stringOf($seat->status ?? null),
            'invited_at' => $this->dateOf($seat->invited_at ?? null),
            'claimed_at' => $this->dateOf($seat->claimed_at ?? null),
            'revoked_at' => $this->dateOf($seat->revoked_at ?? null),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function offerOutputSchema(): array
    {
        return ['id' => 'integer', 'handle' => 'string', 'name' => 'string'];
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    protected static function poolOutputSchema(): array
    {
        return [
            'id' => 'integer',
            'product' => 'string',
            'seats' => 'integer',
            'taken' => 'integer',
            'owner' => ['email' => 'string', 'name' => 'string'],
            'payment_id' => 'integer',
            'closed_at' => 'string',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function seatOutputSchema(): array
    {
        return [
            'id' => 'integer',
            'email' => 'string',
            'name' => 'string',
            'status' => 'string',
            'invited_at' => 'string',
            'claimed_at' => 'string',
            'revoked_at' => 'string',
        ];
    }
}
