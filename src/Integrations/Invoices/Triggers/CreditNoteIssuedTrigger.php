<?php

namespace Goldnead\StatamicAutomations\Integrations\Invoices\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Invoices\Concerns\FlattensInvoices;

/**
 * An invoice was reversed by a second document.
 *
 * Both documents are in the context, because a credit note read on its own says
 * nothing about what it undid. `credit_note` is the new document, `reverses` is
 * the invoice it cancels, and a mail that names only the first leaves the
 * recipient looking for a number they cannot place.
 *
 * The two carry the same positive amounts. A credit note is a reversal by kind,
 * not by sign, and it always reverses the full invoice even when only part of
 * the money went back.
 */
class CreditNoteIssuedTrigger implements AutomationTrigger
{
    use FlattensInvoices;

    public static function handle(): string
    {
        return 'invoices.credit_note_issued';
    }

    public static function label(): string
    {
        return 'Credit Note Issued';
    }

    public static function description(): ?string
    {
        return 'Triggered when a credit note reverses an invoice, carrying both documents.';
    }

    public static function group(): string
    {
        return 'Invoices';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    /**
     * No filter, for the same reason as its sibling: an invoice has line items
     * rather than one product, so a product filter would have to guess which
     * line it meant, and there is nothing else here anybody would narrow by.
     * A field that filters nothing is worse than none, because somebody will
     * set it and believe it.
     */
    public static function schema(): array
    {
        return [];
    }

    public static function outputSchema(): array
    {
        return [
            'credit_note' => self::invoiceOutputSchema(),
            'reverses' => self::invoiceOutputSchema(),
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            // The event names these `creditNote` and `reverses`. The first is
            // renamed because every other token in the system is snake_case.
            'credit_note' => $this->invoiceOf($event, 'creditNote') ?: $this->invoiceOf($event, 'credit_note'),
            'reverses' => $this->invoiceOf($event, 'reverses'),
        ]);
    }
}
