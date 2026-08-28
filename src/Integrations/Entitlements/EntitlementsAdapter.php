<?php

namespace Goldnead\StatamicAutomations\Integrations\Entitlements;

/**
 * Thin adapter over the entitlements addon's public API.
 *
 * Everything the sibling owns is reached by class-string and guarded by
 * `class_exists`. Nothing in this package may type-hint an entitlements class:
 * these files are autoloaded on sites that do not have the addon, and a
 * type-hint on a missing class is a fatal error at the moment the container
 * touches it, not a graceful absence.
 *
 * The expected surface (`Goldnead\Entitlements\EntitlementManager`):
 *   - grant(mixed $subject, string $productSlug, string $source, ?string $sourceRef, ...): Entitlement
 *   - revoke(Entitlement $entitlement, string $reason, ?Identity $actor = null): bool
 *   - forSubject(mixed $subject): Builder
 *
 * ## On writing twice
 *
 * `grant()` is idempotent by a unique index on
 * (subject, product, source, source_ref, brand), and it catches the constraint
 * violation itself when two callers race. A second grant with the same tuple
 * returns the existing row rather than writing another. This adapter therefore
 * does not add a guard of its own: a read-then-write check here would be weaker
 * than the one already in the database, and would only make the failure harder
 * to find.
 *
 * ## Why the state is read back, and not only `wasRecentlyCreated`
 *
 * Because "the call succeeded" and "this person has access" are different
 * facts, and the gap between them is silent. The manager returns an existing
 * grant untouched, and an existing grant may be revoked, expired or scheduled.
 * A revoked one in particular **stays revoked** by design, so a retried webhook
 * cannot undo a refund; reopening it is a separate decision with its own method
 * on the manager.
 *
 * So a caller that reads only "did this write" is told everything went well
 * about somebody who has nothing. The state is read back after the write and
 * reported, and the action above turns the dead states into a failed node.
 */
class EntitlementsAdapter
{
    /** Default FQCN of the entitlements manager (overridable via config). */
    public const MANAGER = 'Goldnead\\Entitlements\\EntitlementManager';

    /** Default FQCN of the subject value object. */
    public const SUBJECT_REFERENCE = 'Goldnead\\Entitlements\\Support\\SubjectReference';

    public function available(): bool
    {
        return $this->manager() !== null;
    }

    /**
     * Write a grant, or return the one that is already there.
     *
     * @return array{ok: bool, created?: bool, entitlement?: array<string, mixed>, error?: string}
     */
    public function grant(
        string $subjectType,
        string $subjectId,
        string $productSlug,
        string $source,
        ?string $sourceRef = null,
        ?string $expiresAt = null,
    ): array {
        $manager = $this->manager();

        if ($manager === null) {
            return ['ok' => false, 'error' => 'The entitlements addon is not installed.'];
        }

        $subject = $this->subject($subjectType, $subjectId);

        if ($subject === null) {
            return ['ok' => false, 'error' => 'Could not build a subject reference from the given type and id.'];
        }

        try {
            $expires = $this->parseDate($expiresAt);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => "Could not read \"{$expiresAt}\" as a date."];
        }

        try {
            $entitlement = $manager->grant(
                $subject,
                $productSlug,
                $source,
                $sourceRef,
                null,
                $expires,
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->message($e)];
        }

        // Read before the refresh below: `wasRecentlyCreated` is the model's own
        // answer and needs no second query, but it is a property of this
        // instance and deserves to be taken while it is certainly untouched.
        $created = (bool) ($entitlement->wasRecentlyCreated ?? false);

        // A grant that was claimed out of Pending is returned as the manager
        // found it, before the claim landed. Without this the state read below
        // would be the one from before the transition.
        $this->refresh($entitlement);

        $state = $this->stateOf($entitlement);

        return [
            'ok' => true,
            'created' => $created,
            'state' => $state['value'],
            'grants_access' => $state['grants_access'],
            // Whether it can still become active with nobody doing anything,
            // which is true for a scheduled grant and false for a revoked or
            // expired one. The difference decides whether "no access" is a
            // problem or a date in the future.
            'provisional' => $state['provisional'],
            'entitlement' => [
                'id' => $entitlement->id ?? null,
                'product_slug' => $entitlement->product_slug ?? null,
                'source' => $entitlement->source ?? null,
                'source_ref' => $entitlement->source_ref ?? null,
                'expires_at' => $this->iso($entitlement->expires_at ?? null),
            ],
        ];
    }

    /**
     * Withdraw every grant this subject holds for this product.
     *
     * Every one, not the first: the addon's unique key allows several rows per
     * (subject, product) on purpose — the same course won by opt-in and later
     * bought are two legitimate grants — and revoking only one of them would
     * leave access in place while reporting success.
     *
     * @return array{ok: bool, revoked?: int, matched?: int, error?: string}
     */
    public function revoke(string $subjectType, string $subjectId, string $productSlug, string $reason): array
    {
        $manager = $this->manager();

        if ($manager === null) {
            return ['ok' => false, 'error' => 'The entitlements addon is not installed.'];
        }

        $subject = $this->subject($subjectType, $subjectId);

        if ($subject === null) {
            return ['ok' => false, 'error' => 'Could not build a subject reference from the given type and id.'];
        }

        try {
            $grants = $manager->forSubject($subject)
                ->where('product_slug', $productSlug)
                ->get();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->message($e)];
        }

        $revoked = 0;

        foreach ($grants as $grant) {
            try {
                // revoke() returns false for a grant that was already revoked.
                // That is a no-op, not a failure, and it must not be counted:
                // the count is what a downstream step reads to decide whether
                // anything actually changed.
                if ($manager->revoke($grant, $reason)) {
                    $revoked++;
                }
            } catch (\Throwable $e) {
                // The partial count travels with the failure. Breaking off at
                // grant three of five and reporting only "failed" hides that
                // two are already gone, which is the thing whoever cleans up
                // needs to know first.
                return ['ok' => false, 'error' => $this->message($e), 'revoked' => $revoked];
            }
        }

        return ['ok' => true, 'revoked' => $revoked, 'matched' => $grants->count()];
    }

    /**
     * The manager, or null when the addon is absent.
     */
    protected function manager(): ?object
    {
        $class = (string) config('automations.integrations.entitlements.manager', self::MANAGER);

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
     * A subject as the addon wants it: a `(type, id)` value object.
     *
     * Not an Eloquent model. A grant may belong to somebody who has no user
     * record — a CRM contact who claimed a lead magnet and never made an
     * account — and the addon stores the pair rather than a foreign key for
     * exactly that reason. Passing the pair means this action works for both.
     */
    protected function subject(string $type, string $id): ?object
    {
        $class = (string) config(
            'automations.integrations.entitlements.subject_reference',
            self::SUBJECT_REFERENCE,
        );

        if ($class === '' || ! class_exists($class)) {
            return null;
        }

        try {
            return new $class($type, $id);
        } catch (\Throwable) {
            // Both halves are required and neither may be empty.
            return null;
        }
    }

    /**
     * The grant's derived state, reduced to three plain values.
     *
     * Derived, not the stored `status` column: Scheduled and Expired are never
     * written there, so a caller that reads the column gets both of them wrong.
     *
     * @return array{value: string|null, grants_access: bool, provisional: bool}
     */
    protected function stateOf(object $entitlement): array
    {
        $unknown = ['value' => null, 'grants_access' => false, 'provisional' => false];

        if (! method_exists($entitlement, 'state')) {
            return $unknown;
        }

        try {
            $state = $entitlement->state();
        } catch (\Throwable) {
            return $unknown;
        }

        return [
            'value' => $state instanceof \BackedEnum ? (string) $state->value : null,
            'grants_access' => method_exists($state, 'grantsAccess') && $state->grantsAccess(),
            'provisional' => method_exists($state, 'isProvisional') && $state->isProvisional(),
        ];
    }

    protected function refresh(object $entitlement): void
    {
        if (! method_exists($entitlement, 'refresh')) {
            return;
        }

        try {
            $entitlement->refresh();
        } catch (\Throwable) {
            // A row that cannot be re-read is still a row that was written.
            // Losing the state reading is better than losing the grant.
        }
    }

    protected function parseDate(?string $value): ?\DateTimeInterface
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    protected function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(\DATE_ATOM) : null;
    }

    protected function message(\Throwable $e): string
    {
        return $e->getMessage() !== '' ? $e->getMessage() : $e::class;
    }
}
