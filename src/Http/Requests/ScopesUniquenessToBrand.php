<?php

namespace Goldnead\StatamicAutomations\Http\Requests;

use Illuminate\Validation\Rules\Unique;

/**
 * Keeps the handle validation in step with the schema it is validating for.
 *
 * Since 1.5.0 the database makes the automation handle unique per brand:
 * `unique(brand_id, handle)`. `Rule::unique()` does not know that. It compiles
 * to a query on the raw query builder, which no Eloquent global scope ever
 * reaches, so an unscoped rule asks a question about every brand's rows.
 *
 * Left unscoped it produced two silent effects, both wrong in the same
 * direction — the validator being stricter than the database:
 *
 *  - A brand could not create an automation with a handle another brand had
 *    already taken, although the schema allows exactly that and the whole
 *    point of brand-scoping the unique was that it should.
 *  - The refusal named the reason. "The handle has already been taken" is a
 *    statement about rows the asking tenant is not permitted to see.
 *
 * Adding `where('brand_id', …)` makes the rule ask the question the index
 * answers. Uniqueness inside a brand is unchanged.
 */
trait ScopesUniquenessToBrand
{
    protected function brandScoped(Unique $rule): Unique
    {
        return $rule->where('brand_id', $this->currentBrandId());
    }

    /**
     * The brand a row created by this request will be stamped with.
     *
     * Single-brand mode resolves to the default brand, which is what HasBrand
     * stamps there too — so the rule matches the row that is about to be
     * written in both modes.
     */
    protected function currentBrandId(): ?int
    {
        if (! app()->bound('brand-context')) {
            return null;
        }

        return app('brand-context')->currentId();
    }
}
