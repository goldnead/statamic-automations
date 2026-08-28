<?php

namespace Goldnead\StatamicAutomations\Integrations\Invoices\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Invoices\InvoicesAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Writes the credit note that reverses a payment's invoice.
 *
 * Only registered when the invoices addon is detected.
 *
 * ## It always reverses the whole invoice
 *
 * There is no partial credit note. The document copies the invoice's amounts as
 * they stand and cancels it in full, whatever share of the money actually went
 * back. On a partial refund that is the wrong document, and this action is the
 * wrong step — the addon's own listener knows that and stays out of partial
 * refunds for exactly this reason. Put it behind a full-refund condition, or
 * behind the payments trigger with "Only full refunds" turned on.
 *
 * ## Running twice
 *
 * Safe. One credit note per payment, held by a unique index in the addon. A
 * second run reports the existing document with `created: false` rather than
 * writing a second one or failing.
 *
 * A payment that never had an invoice fails the node with that as the reason.
 * The addon returns the same empty answer for "already reversed" and "nothing
 * to reverse"; those are told apart here so the message says which happened.
 */
class IssueCreditNoteAction implements AutomationAction
{
    public function __construct(protected InvoicesAdapter $adapter) {}

    public static function handle(): string
    {
        return 'invoices.issue_credit_note';
    }

    public static function label(): string
    {
        return 'Issue Credit Note';
    }

    public static function description(): ?string
    {
        return "Reverses a payment's invoice in full with a credit note, or returns the one already written.";
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
        return [
            [
                'handle' => 'payment_id',
                'label' => 'Payment',
                'type' => 'data_reference',
                'source' => 'payment',
                'required' => true,
                'help' => 'The payment whose invoice is reversed. Defaults to {{ payment.id }}. The credit note always covers the full invoice.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'created' => 'boolean',
            'invoice' => [
                'id' => 'string',
                'number' => 'string',
                'kind' => 'string',
                'payment_id' => 'string',
                'issued_at' => 'string',
                'currency' => 'string',
                'buyer_name' => 'string',
                'buyer_email' => 'string',
                'net_cent' => 'integer',
                'tax_cent' => 'integer',
                'gross_cent' => 'integer',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $paymentId = trim((string) ($config['payment_id'] ?? $context->get('payment.id') ?? ''));

        if ($context->isTestMode() && ! config('automations.test_mode.persist_invoice_changes', false)) {
            return ActionResult::success([
                'preview' => ['payment_id' => $paymentId],
                'note' => 'Test mode — no credit note was written.',
            ]);
        }

        if ($paymentId === '') {
            return ActionResult::missingDataReference('payment_id', 'Payment', '{{ payment.id }}');
        }

        $result = $this->adapter->creditNote($paymentId);

        if (! ($result['ok'] ?? false)) {
            return ActionResult::failed($result['error'] ?? 'Writing the credit note failed.', [
                'payment_id' => $paymentId,
            ]);
        }

        return ActionResult::success([
            'created' => $result['created'] ?? false,
            'invoice' => $result['invoice'] ?? [],
        ]);
    }
}
