<?php

namespace Goldnead\StatamicAutomations\Integrations\Invoices\Concerns;

/**
 * Turns the invoices an invoice event carries into plain arrays.
 *
 * The field list is narrower than the table and stays that way. Four columns
 * are left out on purpose: the frozen seller block (the site's own address,
 * which nobody needs a token for), free-form `meta` (no schema to promise),
 * `buyer_address` (a multi-line block that belongs in a rendered document
 * rather than in a mail body) and `reverses_invoice_id` (a raw key, whose
 * useful form is the second document the credit-note trigger already carries).
 *
 * `tax_reason` and `tax_note` are both here and they are not the same. The
 * first is the short rule, "§ 19 UStG". The second is the sentence that has to
 * appear on the document, and a mail that names the rule without the sentence
 * is missing the part the law asked for.
 *
 * Amounts stay in cent and stay unsigned, exactly as the addon stores them. A
 * credit note carries the same positive amounts as the invoice it reverses —
 * the reversal is expressed by `kind`, not by a minus sign — so a flow that
 * sums `gross_cent` across both documents must subtract on the credit note
 * itself rather than expecting the data to do it.
 */
trait FlattensInvoices
{
    /**
     * An invoice, flattened.
     *
     * @return array<string, mixed>
     */
    protected function invoiceOf(object|array $event, string $key): array
    {
        $invoice = $this->propertyOf($event, $key);

        if (is_array($invoice)) {
            return $invoice;
        }

        if (! is_object($invoice)) {
            return [];
        }

        return [
            'id' => $invoice->id ?? null,
            'number' => $invoice->number ?? null,
            'kind' => $invoice->kind ?? null,
            'payment_id' => $invoice->payment_id ?? null,
            'issued_at' => $this->dateOf($invoice->issued_at ?? null),
            'currency' => $invoice->currency ?? null,
            'buyer_name' => $invoice->buyer_name ?? null,
            'buyer_email' => $invoice->buyer_email ?? null,
            'buyer_country' => $invoice->buyer_country ?? null,
            'buyer_vat_id' => $invoice->buyer_vat_id ?? null,
            'net_cent' => $invoice->net_cent ?? null,
            'tax_cent' => $invoice->tax_cent ?? null,
            'gross_cent' => $invoice->gross_cent ?? null,
            'tax_reason' => $invoice->tax_reason ?? null,
            'tax_note' => $invoice->tax_note ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function invoiceOutputSchema(): array
    {
        return [
            'id' => 'string',
            'number' => 'string',
            'kind' => 'string',
            'payment_id' => 'string',
            'issued_at' => 'string',
            'currency' => 'string',
            'buyer_name' => 'string',
            'buyer_email' => 'string',
            'buyer_country' => 'string',
            'buyer_vat_id' => 'string',
            'net_cent' => 'integer',
            'tax_cent' => 'integer',
            'gross_cent' => 'integer',
            'tax_reason' => 'string',
            'tax_note' => 'string',
        ];
    }

    protected function propertyOf(object|array $event, string $key): mixed
    {
        return is_array($event) ? ($event[$key] ?? null) : ($event->{$key} ?? null);
    }

    protected function dateOf(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
