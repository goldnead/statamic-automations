<?php

namespace Goldnead\StatamicAutomations\Integrations\Invoices\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Invoices\Concerns\FlattensInvoices;

/**
 * An invoice exists.
 *
 * The seam for everything the invoices addon deliberately does not do: sending
 * it, filing it, handing it to an accountant. It fires only after a document
 * was actually written, never when an existing one was handed back, so a mail
 * behind it goes out once even if the invoice is asked for three times.
 *
 * No filter, and that is a decision rather than an omission. An invoice has
 * line items rather than one product, so a product filter would have to guess
 * which line it meant; and there is nothing else here anybody would narrow by.
 * A trigger with a filter field that filters nothing is worse than one without,
 * because somebody will set it and believe it.
 */
class InvoiceIssuedTrigger implements AutomationTrigger
{
    use FlattensInvoices;

    public static function handle(): string
    {
        return 'invoices.issued';
    }

    public static function label(): string
    {
        return 'Invoice Issued';
    }

    public static function description(): ?string
    {
        return 'Triggered when an invoice is written, carrying its number, amounts and buyer.';
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
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'invoice' => $this->invoiceOf($event, 'invoice'),
        ]);
    }
}
