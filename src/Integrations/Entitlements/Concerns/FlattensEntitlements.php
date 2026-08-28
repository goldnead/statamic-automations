<?php

namespace Goldnead\StatamicAutomations\Integrations\Entitlements\Concerns;

/**
 * Turns the objects an entitlement event carries into plain arrays.
 *
 * Three kinds of value arrive here and none of them survives a run context
 * unaided: an Eloquent model, a backed enum, and an `Identity` value object.
 * The enum is the reason this is not simply `toArray()` — an enum instance
 * printed into a mail body is a fatal error, and `EntitlementState::Active` in
 * a condition node compares against nothing a person would type.
 *
 * `state` and `status` are both here and they are not the same thing. `status`
 * is the stored column; `state` is what the addon derives from it plus the
 * clock, and it is the one that answers "does this person have access right
 * now". A flow that branches on `status` will get Scheduled and Expired wrong,
 * because neither is ever written to the column.
 */
trait FlattensEntitlements
{
    /**
     * The grant, flattened.
     *
     * `meta` is deliberately absent: it is free-form JSON written by whoever
     * created the grant, so it has no schema to promise and nothing the data
     * picker could offer.
     *
     * @return array<string, mixed>
     */
    protected function entitlementOf(object|array $event, string $key = 'entitlement'): array
    {
        $entitlement = $this->propertyOf($event, $key);

        if (is_array($entitlement)) {
            return $entitlement;
        }

        if (! is_object($entitlement)) {
            return [];
        }

        return [
            'id' => $entitlement->id ?? null,
            'subject_type' => $entitlement->subject_type ?? null,
            'subject_id' => $entitlement->subject_id ?? null,
            'product_slug' => $entitlement->product_slug ?? null,
            'source' => $entitlement->source ?? null,
            'source_ref' => $entitlement->source_ref ?? null,
            'status' => $entitlement->status ?? null,
            'state' => $this->stateOf($entitlement),
            'starts_at' => $this->dateOf($entitlement->starts_at ?? null),
            'expires_at' => $this->dateOf($entitlement->expires_at ?? null),
            'grace_until' => $this->dateOf($entitlement->grace_until ?? null),
            'revoked_at' => $this->dateOf($entitlement->revoked_at ?? null),
            'revoked_reason' => $entitlement->revoked_reason ?? null,
        ];
    }

    /**
     * The output schema fragment for {@see entitlementOf()}.
     *
     * @return array<string, string>
     */
    protected static function entitlementOutputSchema(): array
    {
        return [
            'id' => 'string',
            'subject_type' => 'string',
            'subject_id' => 'string',
            'product_slug' => 'string',
            'source' => 'string',
            'source_ref' => 'string',
            'status' => 'string',
            'state' => 'string',
            'starts_at' => 'string',
            'expires_at' => 'string',
            'grace_until' => 'string',
            'revoked_at' => 'string',
            'revoked_reason' => 'string',
        ];
    }

    /**
     * Who caused it, flattened from an `Identity`.
     *
     * A chargeback handled by a webhook and a refund granted by a person are
     * the same row and very different facts; this is the only place that
     * difference is visible to a flow. `type` is the actor category the
     * identity was built with: `user`, `contact`, `system`, `anonymous`, or
     * something the host application defined. A notification step that wants to
     * stay quiet for machine traffic has to name the types it reacts to rather
     * than assume there is only one machine type.
     *
     * @return array<string, mixed>
     */
    protected function actorOf(object|array $event, string $key = 'actor'): array
    {
        $actor = $this->propertyOf($event, $key);

        if (is_array($actor)) {
            return $actor;
        }

        if (! is_object($actor)) {
            return [];
        }

        return [
            'type' => $actor->type ?? null,
            'id' => $actor->id ?? null,
            'email' => $actor->email ?? null,
            'name' => $actor->name ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function actorOutputSchema(): array
    {
        return [
            'type' => 'string',
            'id' => 'string',
            'email' => 'string',
            'name' => 'string',
        ];
    }

    /**
     * The state a grant was in before this event, as a plain string.
     */
    protected function previousStateOf(object|array $event): ?string
    {
        return $this->enumValue($this->propertyOf($event, 'previousState')
            ?? $this->propertyOf($event, 'previous_state'));
    }

    /**
     * The filter fields shared by every trigger in this group.
     *
     * Product and source, because those are the two questions a flow asks: a
     * grant for the choir course, and a grant that came from a purchase rather
     * than from an opt-in. Same product, different source, very different mail.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function entitlementFilterSchema(): array
    {
        return [
            [
                'handle' => 'product_slug',
                'label' => 'Product',
                'type' => 'text',
                'required' => false,
                'help' => 'The product slug the grant is for. Leave empty for every product.',
            ],
            [
                'handle' => 'source',
                'label' => 'Source',
                'type' => 'text',
                'required' => false,
                'help' => 'Where the grant came from, for example mollie or newsletter_optin. Leave empty for every source.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function matchesEntitlement(object|array $event, array $config): bool
    {
        $grant = $this->entitlementOf($event);

        foreach (['product_slug', 'source'] as $field) {
            $wanted = $config[$field] ?? null;

            if ($wanted && ($grant[$field] ?? null) !== $wanted) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why access was withdrawn.
     *
     * Required at the addon's API, so on a real revocation this is never empty
     * and a notification can print it without a fallback. It is still guarded,
     * because these classes also load where the sibling addon is absent.
     */
    protected function reasonOf(object|array $event): ?string
    {
        $reason = $this->propertyOf($event, 'reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    protected function stateOf(object $entitlement): ?string
    {
        if (! method_exists($entitlement, 'state')) {
            return null;
        }

        try {
            return $this->enumValue($entitlement->state());
        } catch (\Throwable) {
            // A grant whose state cannot be derived is still a grant worth
            // announcing. Losing one field is better than losing the run.
            return null;
        }
    }

    /**
     * A backed enum as its value, anything else through unchanged.
     */
    protected function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) && $value !== '' ? $value : null;
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
