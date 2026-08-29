<?php

namespace Goldnead\StatamicAutomations\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Wie oft eine Automation ueberhaupt gelaufen ist.
 *
 * Ein Lauf ist eine Einschreibung: ein Betreff, ein Durchgang durch einen
 * Ablauf. Die Zahl beantwortet „arbeitet das System", nicht „arbeitet es gut" —
 * das steht in der Quote nebenan.
 *
 * Testlaeufe zaehlen nicht mit, siehe {@see AutomationMetric}.
 */
class Runs extends RunMetric implements HasBreakdowns
{
    public function handle(): string
    {
        return 'automations.runs';
    }

    public function label(): string
    {
        return __('statamic-automations::insights.runs');
    }

    public function description(): ?string
    {
        return __('statamic-automations::insights.runs_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return (int) $this->untilNow($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->untilNow($query), $query, 'count(*)'),
        );
    }

    public function breakdowns(): array
    {
        return $this->runBreakdowns();
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->splitRuns($query, $dimension, $limit);
    }
}
