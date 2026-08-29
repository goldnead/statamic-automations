<?php

namespace Goldnead\StatamicAutomations\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Wie viele Leute eine einzelne Serie verlassen haben.
 *
 * Nicht dasselbe wie eine Abmeldung von der Liste: eine Zeile in
 * `automation_opt_outs` heisst „von dieser Automation nichts mehr", und die
 * Newsletter-Anmeldung bleibt unberuehrt. Genau deshalb gehoert die Zahl auf
 * einen gemeinsamen Schirm neben den Versand: sie steigt, wenn eine Strecke zu
 * lang, zu haeufig oder am Thema vorbei ist, und sie steigt frueher als die
 * Abmeldequote, weil sie den billigeren Ausstieg misst.
 *
 * Der Zeitstempel ist `opted_out_at` und nicht `created_at`: die Zeile wird
 * geschrieben, wenn jemand klickt, aber der fachliche Zeitpunkt steht in der
 * eigenen Spalte, und ein spaeterer Wiedereintritt loescht die Zeile — die
 * Tabelle fuehrt keinen Status, sondern nur die geltenden Ausstiege.
 *
 * `is_test` gibt es hier nicht: einen Ausstieg kann man nicht testen. Geklammert
 * wird trotzdem ({@see TableMetric::untilNow()}):
 * ein Ausstieg mit einem Datum in der Zukunft ist kein Ausstieg, den jemand
 * vollzogen hat. Begruendung in voller Laenge in {@see AutomationMetric}.
 */
class OptOuts extends AutomationMetric
{
    protected function table(): string
    {
        return 'automation_opt_outs';
    }

    protected function timestamp(): string
    {
        return 'opted_out_at';
    }

    public function handle(): string
    {
        return 'automations.opt_outs';
    }

    public function label(): string
    {
        return __('statamic-automations::insights.opt_outs');
    }

    public function description(): ?string
    {
        return __('statamic-automations::insights.opt_outs_description');
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
}
