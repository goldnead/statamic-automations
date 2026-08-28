<?php

namespace Goldnead\StatamicAutomations\Listeners;

use Goldnead\StatamicAutomations\Concerns\RunsAutomationsForEvent;

/**
 * Brings the entitlements, booking and invoices addons' events into the engine.
 *
 * All three already fired these and nothing could hear them: ten events with no
 * trigger node in the editor, so a site that wanted "when access is revoked,
 * tell me" had to write a listener by hand — which is the work this addon
 * exists to remove.
 *
 * Same shape as {@see HandleFunnelOrPaymentEvent}, deliberately: an event class
 * mapped to a trigger handle, registered only when the sibling is installed.
 * The two are kept apart rather than merged into one big map because the
 * registration is per-addon — a site with booking but not invoices must listen
 * for three events, not ten.
 *
 * The class-strings are strings and not `::class` references on purpose. None
 * of these packages is a dependency, so the classes may not exist, and
 * `class_exists` at registration is what keeps a site without them working
 * exactly as it does today.
 *
 * ## The handle rule, written down because handles are permanent
 *
 * `<package slug without the statamic- prefix, dashes to underscores>.<what
 * happened, snake_case, past tense>`. It holds for all seven families in this
 * addon: `statamic-webhook-manager` gives `webhook_manager.`,
 * `statamic-leadhub` gives `leadhub.`, and so on through funnels, marketing,
 * payments, entitlements, invoices and booking.
 *
 * That last one is why the rule is spelled out here rather than left to
 * pattern-matching. `booking.made` reads odd beside six plural neighbours, and
 * the pull towards `bookings.` is real. But the neighbours are plural because
 * their packages are, not because a rule said so, and swapping to the noun that
 * looks right would replace a mechanical rule with a matter of taste. A handle
 * that appears in a saved flow cannot be renamed afterwards, so it is worth
 * having the boring rule and keeping it.
 */
class HandleCommerceEvent
{
    use RunsAutomationsForEvent;

    /** Entitlements event class => automation trigger handle. */
    public const ENTITLEMENT_TRIGGERS = [
        'Goldnead\\Entitlements\\Events\\EntitlementGranted' => 'entitlements.granted',
        'Goldnead\\Entitlements\\Events\\EntitlementRevoked' => 'entitlements.revoked',
        'Goldnead\\Entitlements\\Events\\EntitlementExpired' => 'entitlements.expired',
        'Goldnead\\Entitlements\\Events\\EntitlementRenewed' => 'entitlements.renewed',
        'Goldnead\\Entitlements\\Events\\EntitlementPending' => 'entitlements.pending',
    ];

    /** Booking event class => automation trigger handle. */
    public const BOOKING_TRIGGERS = [
        'Goldnead\\StatamicBooking\\Events\\BookingMade' => 'booking.made',
        'Goldnead\\StatamicBooking\\Events\\BookingCancelled' => 'booking.cancelled',
        'Goldnead\\StatamicBooking\\Events\\BookingRescheduled' => 'booking.rescheduled',
    ];

    /** Invoices event class => automation trigger handle. */
    public const INVOICE_TRIGGERS = [
        'Goldnead\\Invoices\\Events\\InvoiceIssued' => 'invoices.issued',
        'Goldnead\\Invoices\\Events\\CreditNoteIssued' => 'invoices.credit_note_issued',
    ];

    public function handle(object $event): void
    {
        $handle = $this->handleForEvent(
            $event,
            self::ENTITLEMENT_TRIGGERS,
            self::BOOKING_TRIGGERS,
            self::INVOICE_TRIGGERS,
        );

        if ($handle === null) {
            return;
        }

        $this->runFor($handle, $event);
    }
}
