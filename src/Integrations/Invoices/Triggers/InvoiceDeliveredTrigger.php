<?php

namespace Goldnead\StatamicAutomations\Integrations\Invoices\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Invoices\Concerns\FlattensInvoices;

/**
 * The invoice reached the buyer's mailbox.
 *
 * `email` is the address it actually left for, carried by the event, which
 * is not necessarily what the invoice says today.
 */
class InvoiceDeliveredTrigger implements AutomationTrigger
{
    use FlattensInvoices;

    public static function handle(): string
    {
        return 'invoices.delivered';
    }

    public static function label(): string
    {
        return 'Invoice Delivered';
    }

    public static function description(): ?string
    {
        return 'Triggered when an invoice was sent to the buyer, with the address it went to.';
    }

    public static function group(): string
    {
        return 'Invoices';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [];
    }

    public static function outputSchema(): array
    {
        return [
            'invoice' => self::invoiceOutputSchema(),
            'email' => 'string',
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $to = $this->propertyOf($event, 'to');

        return AutomationContext::make([
            'invoice' => $this->invoiceOf($event, 'invoice'),
            'email' => is_string($to) && $to !== '' ? $to : null,
        ]);
    }
}
