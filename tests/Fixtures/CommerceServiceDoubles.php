<?php

namespace Goldnead\StatamicAutomations\Tests\Fixtures;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Stand-ins for the two services the commerce actions write through.
 *
 * They exist because none of the four sibling addons is a dependency, so
 * without them the actions are only ever tested in the state "the addon is
 * missing" — and every claim their docblocks make about running twice, about a
 * revoked grant staying revoked, about `created` telling a write from a read,
 * would be an untested assertion. It was exactly that gap that hid a real
 * defect: the grant action reported success for somebody whose grant was
 * revoked and therefore had no access at all.
 *
 * ## These doubles are strict on purpose
 *
 * A double that accepts everything proves only that the call happened. Each of
 * these reproduces the rules of the original, read off its source, and refuses
 * what the original refuses:
 *
 *   - A grant is identified by (subject type, subject id, product, source,
 *     source reference), and a second grant on the same tuple returns the row
 *     that is there rather than writing another.
 *   - A grant that is Pending is claimed to Active by a second grant, because
 *     that is a transition somebody asked for.
 *   - **A revoked grant stays revoked.** Nothing about a repeated grant reopens
 *     it. This is the rule the defect was hiding behind.
 *   - `revoke()` returns whether *this* call changed anything, so revoking
 *     twice returns true and then false.
 *   - A revocation without a reason throws, as it does in the original.
 *   - `forPayment()` returns the invoice that already exists instead of writing
 *     a second one, and `creditNoteFor()` returns null on the second call
 *     rather than the document, which is the asymmetry the adapter exists to
 *     paper over.
 *
 * Where a rule here and a rule in the addon ever part company, this file is
 * wrong and the addon is right.
 */

/** Mirrors `Goldnead\Entitlements\Enums\EntitlementState`. */
enum FakeState: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case GracePeriod = 'grace_period';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function grantsAccess(): bool
    {
        return $this === self::Active || $this === self::GracePeriod;
    }

    public function isProvisional(): bool
    {
        return $this === self::Scheduled;
    }
}

/** Mirrors the columns of `Goldnead\Entitlements\Models\Entitlement` that leave the addon. */
class FakeEntitlement
{
    public bool $wasRecentlyCreated = false;

    public ?string $revoked_reason = null;

    public function __construct(
        public int $id,
        public string $subject_type,
        public string $subject_id,
        public string $product_slug,
        public string $source,
        public string $source_ref,
        public FakeState $status,
        public ?\DateTimeInterface $expires_at = null,
    ) {}

    public function state(): FakeState
    {
        return $this->status;
    }

    public function refresh(): static
    {
        // The store holds this very object, so there is nothing to re-read. The
        // method exists because the adapter calls it and must not care.
        return $this;
    }
}

/** Mirrors `Goldnead\Entitlements\EntitlementManager`, rules included. */
class FakeEntitlementManager
{
    /** @var array<int, FakeEntitlement> */
    public array $grants = [];

    private int $nextId = 1;

    public function grant(
        mixed $subject,
        string $productSlug,
        string $source,
        ?string $sourceRef = null,
        ?\DateTimeInterface $startsAt = null,
        ?\DateTimeInterface $expiresAt = null,
    ): FakeEntitlement {
        [$type, $id] = $this->pair($subject);

        if (trim($productSlug) === '' || trim($source) === '') {
            throw new InvalidArgumentException('A grant needs a product slug and a source.');
        }

        // Absence of an external reference is the empty string, never null:
        // nulls do not collide in a unique index.
        $sourceRef = (string) ($sourceRef ?? '');

        $existing = $this->find($type, $id, $productSlug, $source, $sourceRef);

        if ($existing !== null) {
            // The original re-reads the row, so what it hands back is a fresh
            // model and a fresh model has never been created. Keeping the flag
            // from the first write would make this double kinder than the thing
            // it stands in for, and the kindness would land exactly on the
            // property the actions branch on.
            $existing->wasRecentlyCreated = false;

            // Pending is claimed to Active. Everything else, revoked above all,
            // is returned exactly as it stands.
            if ($existing->status === FakeState::Pending) {
                $existing->status = FakeState::Active;
            }

            return $existing;
        }

        $grant = new FakeEntitlement(
            id: $this->nextId++,
            subject_type: $type,
            subject_id: $id,
            product_slug: $productSlug,
            source: $source,
            source_ref: $sourceRef,
            status: FakeState::Active,
            expires_at: $expiresAt,
        );
        $grant->wasRecentlyCreated = true;

        return $this->grants[] = $grant;
    }

    public function revoke(FakeEntitlement $entitlement, string $reason): bool
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A revocation needs a reason.');
        }

        if ($entitlement->status === FakeState::Revoked) {
            return false;
        }

        $entitlement->status = FakeState::Revoked;
        $entitlement->revoked_reason = $reason;

        return true;
    }

    public function forSubject(mixed $subject): FakeGrantQuery
    {
        [$type, $id] = $this->pair($subject);

        return new FakeGrantQuery(array_values(array_filter(
            $this->grants,
            fn (FakeEntitlement $g) => $g->subject_type === $type && $g->subject_id === $id,
        )));
    }

    /**
     * Seed a grant in a state the write paths cannot produce directly, so a
     * test can start from "already revoked" or "expires next year".
     */
    public function seed(FakeEntitlement $grant): FakeEntitlement
    {
        $grant->id = $this->nextId++;

        return $this->grants[] = $grant;
    }

    private function find(string $type, string $id, string $product, string $source, string $ref): ?FakeEntitlement
    {
        foreach ($this->grants as $grant) {
            if ($grant->subject_type === $type
                && $grant->subject_id === $id
                && $grant->product_slug === $product
                && $grant->source === $source
                && $grant->source_ref === $ref
            ) {
                return $grant;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pair(mixed $subject): array
    {
        // The original refuses anything that is not a model or a reference, and
        // so does this: an action that passes the wrong thing must fail here
        // rather than quietly grant to nobody.
        if (! is_object($subject) || ! property_exists($subject, 'type') || ! property_exists($subject, 'id')) {
            throw new InvalidArgumentException('Cannot use '.get_debug_type($subject).' as a subject.');
        }

        return [(string) $subject->type, (string) $subject->id];
    }
}

/** The slice of an Eloquent builder the adapter uses, and no more. */
class FakeGrantQuery
{
    /** @param array<int, FakeEntitlement> $grants */
    public function __construct(private array $grants) {}

    public function where(string $column, mixed $value): static
    {
        return new static(array_values(array_filter(
            $this->grants,
            fn (FakeEntitlement $g) => ($g->{$column} ?? null) === $value,
        )));
    }

    /** @return Collection<int, FakeEntitlement> */
    public function get(): Collection
    {
        return collect($this->grants);
    }
}

/** Mirrors `Goldnead\Entitlements\Support\SubjectReference`: two strings, both required. */
final readonly class FakeSubjectReference
{
    public function __construct(public string $type, public string $id)
    {
        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('A subject reference needs both a type and an id.');
        }
    }
}

/** Mirrors the invoice columns that leave `Goldnead\Invoices`. */
class FakeInvoice
{
    public bool $wasRecentlyCreated = false;

    public function __construct(
        public int $id,
        public string $number,
        public string $kind,
        public string $payment_id,
        public ?\DateTimeInterface $issued_at = null,
        public string $currency = 'EUR',
        public ?string $buyer_name = null,
        public ?string $buyer_email = null,
        public int $net_cent = 0,
        public int $tax_cent = 0,
        public int $gross_cent = 0,
    ) {}
}

/** A payment, with the sliver of Eloquent the adapter touches. */
class FakePayment
{
    /** @var array<string, FakePayment> */
    public static array $rows = [];

    public bool $itemsLoaded = false;

    public function __construct(public string $id, public string $status = 'paid') {}

    public static function query(): FakePaymentQuery
    {
        return new FakePaymentQuery;
    }

    public function loadMissing(string $relation): static
    {
        // The writer reads the line items to build the invoice lines. An
        // adapter that forgets this works until Eloquent's strict mode is on,
        // which is why the double records that it was asked.
        $this->itemsLoaded = true;

        return $this;
    }
}

class FakePaymentQuery
{
    public function find(string $id): ?FakePayment
    {
        return FakePayment::$rows[$id] ?? null;
    }
}

/** Mirrors `Goldnead\Invoices\InvoiceWriter`, asymmetric return values included. */
class FakeInvoiceWriter
{
    /** @var array<int, FakeInvoice> */
    public array $invoices = [];

    private int $nextId = 1;

    public function forPayment(FakePayment $payment): ?FakeInvoice
    {
        if ($payment->status !== 'paid') {
            return null;
        }

        if ($existing = $this->existing($payment->id, 'invoice')) {
            // Returns the document that is there. The caller cannot tell that
            // from a fresh write except through `wasRecentlyCreated`, which is
            // the whole reason the adapter reads it.
            $existing->wasRecentlyCreated = false;

            return $existing;
        }

        $invoice = new FakeInvoice(
            id: $this->nextId,
            number: sprintf('RE2026-%04d', $this->nextId),
            kind: 'invoice',
            payment_id: $payment->id,
            issued_at: new \DateTimeImmutable('2026-08-29 10:00:00', new \DateTimeZone('UTC')),
            gross_cent: 9900,
        );
        $this->nextId++;
        $invoice->wasRecentlyCreated = true;

        return $this->invoices[] = $invoice;
    }

    public function creditNoteFor(FakePayment $payment): ?FakeInvoice
    {
        $original = $this->existing($payment->id, 'invoice');

        if ($original === null) {
            return null;
        }

        // Null, not the document: the second delivery of the same refund may
        // not produce a second credit note, and the addon says so by returning
        // nothing at all. Indistinguishable from "there was no invoice", which
        // is the ambiguity the adapter resolves with a read afterwards.
        if ($this->existing($payment->id, 'credit_note') !== null) {
            return null;
        }

        $note = new FakeInvoice(
            id: $this->nextId,
            number: sprintf('RE2026-%04d', $this->nextId),
            kind: 'credit_note',
            payment_id: $payment->id,
            issued_at: new \DateTimeImmutable('2026-08-29 11:00:00', new \DateTimeZone('UTC')),
            gross_cent: $original->gross_cent,
        );
        $this->nextId++;
        $note->wasRecentlyCreated = true;

        return $this->invoices[] = $note;
    }

    public function existing(string $paymentId, string $kind): ?FakeInvoice
    {
        foreach ($this->invoices as $invoice) {
            if ($invoice->payment_id === $paymentId && $invoice->kind === $kind) {
                return $invoice;
            }
        }

        return null;
    }
}

/**
 * The invoice model the adapter queries directly to tell "already reversed"
 * from "nothing to reverse". Reads out of the writer's store, so the two cannot
 * drift apart inside a test.
 */
class FakeInvoiceModel
{
    public static ?FakeInvoiceWriter $writer = null;

    public static function query(): FakeInvoiceQuery
    {
        return new FakeInvoiceQuery(self::$writer?->invoices ?? []);
    }
}

class FakeInvoiceQuery
{
    /** @param array<int, FakeInvoice> $invoices */
    public function __construct(private array $invoices) {}

    public function where(string $column, mixed $value): static
    {
        return new static(array_values(array_filter(
            $this->invoices,
            fn (FakeInvoice $i) => ($i->{$column} ?? null) === $value,
        )));
    }

    public function first(): ?FakeInvoice
    {
        return $this->invoices[0] ?? null;
    }
}
