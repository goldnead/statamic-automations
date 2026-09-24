<?php

/**
 * Stand-ins for the offers events (offers `8a4b92f`) and `InvoiceDelivered`
 * (invoices `8993a73`). Same rules as {@see CommerceEventStubs}: the real
 * class names and constructor signatures, `brandId` computed the way the real
 * classes compute it, and a guard so an installed sibling wins.
 */

namespace Goldnead\StatamicOffers\Events {
    if (! class_exists(SeatPoolOpened::class, false)) {
        class SeatPoolOpened
        {
            public readonly ?int $brandId;

            public function __construct(public readonly object $pool, ?int $brandId = null)
            {
                $this->brandId = $brandId ?? (($pool->brand_id ?? 0) > 0 ? $pool->brand_id : null);
            }
        }
    }

    if (! class_exists(SeatInvited::class, false)) {
        class SeatInvited
        {
            public readonly ?int $brandId;

            public function __construct(public readonly object $seat, public readonly object $pool, ?int $brandId = null)
            {
                $this->brandId = $brandId ?? (($pool->brand_id ?? 0) > 0 ? $pool->brand_id : null);
            }
        }
    }

    if (! class_exists(SeatAccepted::class, false)) {
        class SeatAccepted
        {
            public readonly ?int $brandId;

            public function __construct(public readonly object $seat, public readonly object $pool, ?int $brandId = null)
            {
                $this->brandId = $brandId ?? (($pool->brand_id ?? 0) > 0 ? $pool->brand_id : null);
            }
        }
    }

    if (! class_exists(SeatRevoked::class, false)) {
        class SeatRevoked
        {
            public readonly ?int $brandId;

            public function __construct(
                public readonly object $seat,
                public readonly object $pool,
                public readonly string $previousStatus,
                public readonly ?string $reason = null,
                ?int $brandId = null,
            ) {
                $this->brandId = $brandId ?? (($pool->brand_id ?? 0) > 0 ? $pool->brand_id : null);
            }
        }
    }

    if (! class_exists(SeatPoolClosed::class, false)) {
        class SeatPoolClosed
        {
            public readonly ?int $brandId;

            public function __construct(public readonly object $pool, public readonly string $reason, ?int $brandId = null)
            {
                $this->brandId = $brandId ?? (($pool->brand_id ?? 0) > 0 ? $pool->brand_id : null);
            }
        }
    }

    if (! class_exists(OfferSoldOut::class, false)) {
        class OfferSoldOut
        {
            public readonly ?int $brandId;

            public function __construct(public readonly object $offer, public readonly int $sold, ?int $brandId = null)
            {
                $this->brandId = $brandId ?? (($offer->brand_id ?? 0) > 0 ? $offer->brand_id : null);
            }
        }
    }

    if (! class_exists(CouponRedeemed::class, false)) {
        class CouponRedeemed
        {
            public readonly ?int $brandId;

            public function __construct(public readonly object $coupon, public readonly object $payment, ?int $brandId = null)
            {
                $this->brandId = $brandId ?? ((int) ($payment->brand_id ?? 0) > 0 ? (int) $payment->brand_id : null);
            }
        }
    }

    if (! class_exists(ShortLinkSwitched::class, false)) {
        class ShortLinkSwitched
        {
            public readonly ?int $brandId;

            public function __construct(public readonly object $offer, public readonly string $reason, ?int $brandId = null)
            {
                $this->brandId = $brandId ?? (($offer->brand_id ?? 0) > 0 ? $offer->brand_id : null);
            }
        }
    }
}

namespace Goldnead\Invoices\Events {
    if (! class_exists(InvoiceDelivered::class, false)) {
        class InvoiceDelivered
        {
            public function __construct(public readonly object $invoice, public readonly string $to) {}
        }
    }
}
