<?php

namespace Goldnead\StatamicAutomations\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Wie viele Laeufe mit einem Fehler geendet haben.
 *
 * Nur `failed`. Ein Lauf, den ein Stopp-Knoten beendet oder den die
 * Neustart-Regel abgeraeumt hat, ist ausgestiegen und nicht gescheitert — das
 * zusammenzuwerfen faerbt jede gut gebaute Serie rot, die Leute absichtlich
 * fruehzeitig verlassen.
 *
 * Aufgeteilt wird nach Automation und Ausloeser, nicht nach Status: der Status
 * ist hier eine Konstante, und eine Aufteilung mit einer Zeile ist keine.
 */
class Failures extends RunMetric implements HasBreakdowns
{
    public function handle(): string
    {
        return 'automations.failures';
    }

    public function label(): string
    {
        return __('statamic-automations::insights.failures');
    }

    public function description(): ?string
    {
        return __('statamic-automations::insights.failures_description');
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

        return (int) $this->failedInPeriod($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->failedInPeriod($query), $query, 'count(*)'),
        );
    }

    public function breakdowns(): array
    {
        return [
            'automation' => __('statamic-automations::insights.breakdown_automation'),
            'trigger' => __('statamic-automations::insights.breakdown_trigger'),
        ];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || ! in_array($dimension, ['automation', 'trigger'], true)) {
            return [];
        }

        $column = $dimension === 'automation' ? 'automation_uuid' : 'trigger_type';

        return $this->labelRows(
            $this->splitByColumn($this->failedInPeriod($query), $query, $column, 'count(*)', $limit),
            $dimension,
        );
    }
}
