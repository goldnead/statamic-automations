<?php

namespace Goldnead\StatamicAutomations\Tests\Fixtures;

/**
 * The slice of `Goldnead\LeadMagnets\Models\Grant` the "Recent Grant" action
 * reads: `query()->where('entitlement_id', …)->with('resource')->first()`, and
 * on the result `->resource->title`.
 *
 * The action reaches the model by the class name in
 * `automations.integrations.lead_magnets.grant_model`, so a test points that at
 * this class and the real addon is never needed.
 */
class FakeLeadMagnetGrant
{
    /** @var array<int, string> entitlement id => resource title */
    public static array $titles = [];

    public ?object $resource = null;

    private ?int $entitlementId = null;

    public static function query(): static
    {
        return new static;
    }

    public function where(string $column, mixed $value): static
    {
        if ($column === 'entitlement_id') {
            $this->entitlementId = (int) $value;
        }

        return $this;
    }

    public function with(string ...$relations): static
    {
        return $this;
    }

    public function first(): ?static
    {
        $title = self::$titles[$this->entitlementId] ?? null;

        if ($title === null) {
            return null;
        }

        $row = new static;
        $row->resource = (object) ['title' => $title];

        return $row;
    }
}
