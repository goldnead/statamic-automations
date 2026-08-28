<?php

/**
 * Stand-ins for the domain events of four addons this package does not depend
 * on: statamic-payments, statamic-entitlements, statamic-booking and
 * statamic-invoices.
 *
 * They exist so a test can prove the whole path — an event is dispatched, the
 * listener hears it, an automation runs — rather than only that a trigger class
 * flattens an array correctly. The listener maps on `$event::class`, so nothing
 * short of a class with the real name will do; `class_alias` reports the
 * original name and would not match.
 *
 * Every declaration is guarded on the real class being absent, so installing
 * any of those addons as a dev dependency later takes precedence and this file
 * quietly steps aside. The constructor signatures mirror the real ones,
 * property names included — a stub that is easier to satisfy than the original
 * proves only that the test passes.
 */

namespace Goldnead\StatamicPayments\Events {
    if (! class_exists(PaymentRefunded::class, false)) {
        class PaymentRefunded
        {
            public function __construct(
                public readonly object $payment,
                public readonly int $amountCent,
                public readonly bool $isFull,
            ) {}
        }
    }

    if (! class_exists(SubscriptionRenewed::class, false)) {
        class SubscriptionRenewed
        {
            public function __construct(
                public readonly object $subscription,
                public readonly object $payment,
            ) {}
        }
    }
}

namespace Goldnead\Entitlements\Events {
    if (! class_exists(EntitlementGranted::class, false)) {
        class EntitlementGranted
        {
            public function __construct(
                public readonly object $entitlement,
                public readonly mixed $previousState = null,
                public readonly ?object $actor = null,
            ) {}
        }
    }

    if (! class_exists(EntitlementRevoked::class, false)) {
        class EntitlementRevoked
        {
            public function __construct(
                public readonly object $entitlement,
                public readonly string $reason,
                public readonly mixed $previousState = null,
                public readonly ?object $actor = null,
            ) {}
        }
    }
}

namespace Goldnead\StatamicBooking\Events {
    if (! class_exists(BookingMade::class, false)) {
        class BookingMade
        {
            public function __construct(public readonly object $booking) {}
        }
    }
}

namespace Goldnead\Invoices\Events {
    if (! class_exists(CreditNoteIssued::class, false)) {
        class CreditNoteIssued
        {
            public function __construct(
                public readonly object $creditNote,
                public readonly object $reverses,
            ) {}
        }
    }
}
