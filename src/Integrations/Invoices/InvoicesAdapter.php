<?php

namespace Goldnead\StatamicAutomations\Integrations\Invoices;

/**
 * Thin adapter over the invoices addon's public API.
 *
 * Reached by class-string and guarded by `class_exists`, like every other
 * integration here: these files load on sites without the addon.
 *
 * The expected surface (`Goldnead\Invoices\InvoiceWriter`):
 *   - forPayment(Payment $payment): ?Invoice
 *   - creditNoteFor(Payment $payment): ?Invoice
 *
 * ## Two things this adapter exists to paper over
 *
 * **The writer's return value is ambiguous.** `forPayment()` returns the same
 * thing whether it wrote an invoice or found one that was already there, so a
 * flow that mails "your invoice" off its success would mail it again on every
 * retry. The model's own `wasRecentlyCreated` answers the question without a
 * second query, and it is reported as `created`.
 *
 * **`creditNoteFor()` returns null for two different reasons**: there was no
 * invoice to reverse, or a credit note already exists. A caller cannot tell
 * those apart, and one of them is a problem while the other is not. This
 * adapter asks afterwards which it was — a read after a null, not a
 * read-before-write, so it adds no race of its own.
 *
 * Both writes are already held by a unique index on (payment, kind) in the
 * addon's own schema, so a genuine collision surfaces as a database error
 * rather than a second document. That error is caught and reported, not thrown:
 * an invoice that could not be written must fail the node, not the run.
 */
class InvoicesAdapter
{
    /** Default FQCN of the invoice writer (overridable via config). */
    public const WRITER = 'Goldnead\\Invoices\\InvoiceWriter';

    /** Default FQCN of the invoice model. */
    public const INVOICE = 'Goldnead\\Invoices\\Models\\Invoice';

    /** Default FQCN of the payment model an invoice is written for. */
    public const PAYMENT = 'Goldnead\\StatamicPayments\\Models\\Payment';

    public function available(): bool
    {
        return $this->writer() !== null && $this->paymentClass() !== null;
    }

    /**
     * Write the invoice for a payment, or hand back the one already written.
     *
     * @return array{ok: bool, created?: bool, invoice?: array<string, mixed>, error?: string}
     */
    public function issue(string $paymentId): array
    {
        $writer = $this->writer();

        if ($writer === null) {
            return ['ok' => false, 'error' => 'The invoices addon is not installed.'];
        }

        $found = $this->payment($paymentId);

        if (! isset($found['payment'])) {
            return ['ok' => false, 'error' => $found['error'] ?? "No payment found with id {$paymentId}."];
        }

        $payment = $found['payment'];

        try {
            $invoice = $writer->forPayment($payment);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->message($e)];
        }

        if ($invoice === null) {
            // The writer's one null case: the payment is not paid. Writing an
            // invoice for money that has not arrived is the wrong outcome, so
            // this is reported rather than retried.
            return ['ok' => false, 'error' => 'The payment is not paid, so no invoice was written.'];
        }

        return [
            'ok' => true,
            'created' => (bool) ($invoice->wasRecentlyCreated ?? false),
            'invoice' => $this->flatten($invoice),
        ];
    }

    /**
     * Write the credit note that reverses a payment's invoice.
     *
     * @return array{ok: bool, created?: bool, invoice?: array<string, mixed>, error?: string}
     */
    public function creditNote(string $paymentId): array
    {
        $writer = $this->writer();

        if ($writer === null) {
            return ['ok' => false, 'error' => 'The invoices addon is not installed.'];
        }

        $found = $this->payment($paymentId);

        if (! isset($found['payment'])) {
            return ['ok' => false, 'error' => $found['error'] ?? "No payment found with id {$paymentId}."];
        }

        $payment = $found['payment'];

        try {
            $creditNote = $writer->creditNoteFor($payment);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->message($e)];
        }

        if ($creditNote !== null) {
            return [
                'ok' => true,
                'created' => true,
                'invoice' => $this->flatten($creditNote),
            ];
        }

        // Null means one of two things and the difference matters. Asking now
        // is safe: whatever the answer, the write has already happened or has
        // already been refused.
        $existing = $this->existingCreditNote($paymentId);

        if ($existing !== null) {
            return [
                'ok' => true,
                'created' => false,
                'invoice' => $this->flatten($existing),
            ];
        }

        return ['ok' => false, 'error' => 'There is no invoice for this payment to reverse.'];
    }

    protected function writer(): ?object
    {
        $class = (string) config('automations.integrations.invoices.writer', self::WRITER);

        if ($class === '' || ! class_exists($class)) {
            return null;
        }

        try {
            return app($class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The payment, or a reason it could not be had.
     *
     * Two outcomes, kept apart: a row that is not there, and a lookup that
     * broke. Reporting a database error as "no payment found with id 3" sends
     * whoever reads the run looking for a missing record that exists.
     *
     * @return array{payment?: object, error?: string}
     */
    protected function payment(string $id): array
    {
        $class = $this->paymentClass();

        if ($class === null) {
            return ['error' => 'The payments addon is not installed, so there is nothing to invoice.'];
        }

        if ($id === '') {
            return ['error' => 'No payment was given.'];
        }

        try {
            $payment = $class::query()->find($id);
        } catch (\Throwable $e) {
            return ['error' => 'Could not look up payment '.$id.': '.$this->message($e)];
        }

        if ($payment === null) {
            return ['error' => "No payment found with id {$id}."];
        }

        try {
            // The writer reads the payment's line items to build the invoice
            // lines. Loading them here rather than letting it lazy-load keeps
            // this working under Eloquent's strict mode.
            return ['payment' => $payment->loadMissing('items')];
        } catch (\Throwable $e) {
            return ['error' => 'Could not load the items of payment '.$id.': '.$this->message($e)];
        }
    }

    /**
     * @return class-string|null
     */
    protected function paymentClass(): ?string
    {
        $class = (string) config('automations.integrations.invoices.payment_model', self::PAYMENT);

        return $class !== '' && class_exists($class) ? $class : null;
    }

    protected function existingCreditNote(string $paymentId): ?object
    {
        $class = (string) config('automations.integrations.invoices.model', self::INVOICE);

        if ($class === '' || ! class_exists($class)) {
            return null;
        }

        // The addon's own constant where it exists. A literal here would keep
        // working right up until the day the value changes, and then fail by
        // finding nothing rather than by breaking.
        $kind = defined($class.'::KIND_CREDIT_NOTE') ? constant($class.'::KIND_CREDIT_NOTE') : 'credit_note';

        try {
            return $class::query()
                ->where('payment_id', $paymentId)
                ->where('kind', $kind)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function flatten(object $invoice): array
    {
        return [
            'id' => $invoice->id ?? null,
            'number' => $invoice->number ?? null,
            'kind' => $invoice->kind ?? null,
            'payment_id' => $invoice->payment_id ?? null,
            'issued_at' => ($invoice->issued_at ?? null) instanceof \DateTimeInterface
                ? $invoice->issued_at->format(\DATE_ATOM)
                : null,
            'currency' => $invoice->currency ?? null,
            'buyer_name' => $invoice->buyer_name ?? null,
            'buyer_email' => $invoice->buyer_email ?? null,
            'net_cent' => $invoice->net_cent ?? null,
            'tax_cent' => $invoice->tax_cent ?? null,
            'gross_cent' => $invoice->gross_cent ?? null,
        ];
    }

    protected function message(\Throwable $e): string
    {
        return $e->getMessage() !== '' ? $e->getMessage() : $e::class;
    }
}
