<?php

namespace Goldnead\StatamicAutomations\Integrations\Affiliates\Concerns;

/**
 * Turns the partner and commission models of statamic-affiliates into plain
 * arrays, for the same reasons as the payments flattening: the run context has
 * to survive the queue, and the field list is the triggers' public surface,
 * not the table. Payout details are deliberately absent; they are encrypted in
 * the table for a reason, and a data picker is not where they belong.
 *
 * `email` is copied to the top level of the context by the triggers. It is
 * the partner's address and the default subject path, and a partner flow is
 * almost always a mail to the partner.
 */
trait FlattensAffiliates
{
    /**
     * @return array<string, mixed>
     */
    protected function partnerOf(mixed $partner): array
    {
        if (is_array($partner)) {
            return $partner;
        }

        if (! is_object($partner)) {
            return [];
        }

        return [
            'id' => $partner->id ?? null,
            'name' => $partner->name ?? null,
            'email' => $partner->email ?? null,
            'code' => $partner->code ?? null,
            'status' => $partner->status ?? null,
            'commission_percent' => $partner->commission_percent ?? null,
            'website' => $partner->website ?? null,
            'user_id' => $partner->user_id ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function commissionOf(mixed $commission): array
    {
        if (is_array($commission)) {
            return array_diff_key($commission, ['partner' => true]);
        }

        if (! is_object($commission)) {
            return [];
        }

        return [
            'id' => $commission->id ?? null,
            'kind' => $commission->kind ?? null,
            'status' => $commission->status ?? null,
            'product' => $commission->product ?? null,
            'cycle' => $commission->cycle ?? null,
            'base_cent' => $commission->base_cent ?? null,
            'amount_cent' => $commission->amount_cent ?? null,
            'reversed_cent' => $commission->reversed_cent ?? null,
            'currency' => $commission->currency ?? null,
            'rate' => $commission->rate ?? null,
            'payment_id' => $commission->payment_id ?? null,
            'reason' => $commission->reason ?? null,
            'sold_at' => $this->dateOf($commission->sold_at ?? null),
            'available_at' => $this->dateOf($commission->available_at ?? null),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function partnerOutputSchema(): array
    {
        return [
            'id' => 'integer',
            'name' => 'string',
            'email' => 'string',
            'code' => 'string',
            'status' => 'string',
            'commission_percent' => 'string',
            'website' => 'string',
            'user_id' => 'string',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function commissionOutputSchema(): array
    {
        return [
            'id' => 'integer',
            'kind' => 'string',
            'status' => 'string',
            'product' => 'string',
            'cycle' => 'integer',
            'base_cent' => 'integer',
            'amount_cent' => 'integer',
            'reversed_cent' => 'integer',
            'currency' => 'string',
            'rate' => 'string',
            'payment_id' => 'integer',
            'reason' => 'string',
            'sold_at' => 'string',
            'available_at' => 'string',
        ];
    }

    protected function read(mixed $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        return is_object($source) ? ($source->{$key} ?? null) : null;
    }

    protected function dateOf(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
