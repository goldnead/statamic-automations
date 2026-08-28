<?php

namespace Goldnead\StatamicAutomations\Integrations\Invoices\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Invoices\InvoicesAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Writes the invoice for a payment.
 *
 * Only registered when the invoices addon is detected.
 *
 * ## Why this exists when the addon already writes invoices by itself
 *
 * It writes them on `PaymentPaid`, and only while `invoices.auto_issue` is on.
 * A site that turns that off to keep the decision in one place, or one that
 * needs an invoice for a payment that was reconciled by hand, has no way to ask
 * for one. This is that way.
 *
 * ## Running twice
 *
 * Safe. An invoice is identified by (payment, kind) and the addon holds that
 * with a unique index, so a second call returns the invoice that already
 * exists. A number is never handed out twice and a series never forks.
 *
 * Branch on `created` rather than on success. It is true only when this run
 * actually wrote the document, which is the difference between mailing an
 * invoice once and mailing it on every retry.
 */
class IssueInvoiceAction implements AutomationAction
{
    public function __construct(protected InvoicesAdapter $adapter) {}

    public static function handle(): string
    {
        return 'invoices.issue';
    }

    public static function label(): string
    {
        return 'Issue Invoice';
    }

    public static function description(): ?string
    {
        return 'Writes the invoice for a paid payment, or returns the one already written.';
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
                'help' => 'The payment to invoice. Defaults to {{ payment.id }} when the flow started on a payment.',
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

        // This action has no static configuration to validate, so the
        // test-mode branch comes first and the data reference is checked after
        // it: see ActionResult::missingDataReference().
        if ($context->isTestMode() && ! config('automations.test_mode.persist_invoice_changes', false)) {
            return ActionResult::success([
                'preview' => ['payment_id' => $paymentId],
                'note' => 'Test mode — no invoice was written.',
            ]);
        }

        if ($paymentId === '') {
            return ActionResult::missingDataReference('payment_id', 'Payment', '{{ payment.id }}');
        }

        $result = $this->adapter->issue($paymentId);

        if (! ($result['ok'] ?? false)) {
            return ActionResult::failed($result['error'] ?? 'Writing the invoice failed.', [
                'payment_id' => $paymentId,
            ]);
        }

        return ActionResult::success([
            'created' => $result['created'] ?? false,
            'invoice' => $result['invoice'] ?? [],
        ]);
    }
}
