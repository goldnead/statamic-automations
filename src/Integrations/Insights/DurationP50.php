<?php

namespace Goldnead\StatamicAutomations\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Database\Query\Builder;

/**
 * Die mittlere Laufzeit, in Sekunden.
 *
 * **Median und nicht Durchschnitt.** Laufzeiten haben einen langen Schwanz: ein
 * einziger Lauf, der neunzig Sekunden an einem fremden Dienst haengt, hebt den
 * Durchschnitt von hundert Laeufen so weit an, dass die Kachel eine Stoerung
 * meldet, die niemand hat. Der Median beschreibt den Lauf in der Mitte, und das
 * ist die Zahl, nach der jemand fragt, der wissen will „wie lange dauert das
 * normalerweise".
 *
 * **Nearest rank, nicht interpoliert.** Bei gerader Anzahl wird der untere der
 * beiden mittleren Werte genommen statt ihr Mittel. Der ausgegebene Wert ist
 * damit immer die Laufzeit eines Laufs, den es wirklich gab — dieselbe Regel,
 * die `DeliveryStatsService` im Webhook-Addon fuer seine Perzentile benutzt, und
 * derselbe Grund: eine interpolierte Millisekunde ist eine Zahl, zu der keine
 * Zeile in der Tabelle passt.
 *
 * **Wie das ueber drei Treiber portabel bleibt.** SQLite kennt kein
 * `percentile_cont`, MySQL bis 8.0 auch nicht, und ein in SQL nachgebauter
 * Median ist auf jedem Treiber anders falsch. Der Weg hier ist derselbe auf
 * allen dreien: zaehlen, dann `order by duration_ms` mit `offset` auf den
 * Rangplatz. Fuer den Verlauf werden Eimer und Dauer als Paare gelesen und in
 * PHP gruppiert — eine Abfrage statt einer je Tag, und ueber neunzig Tage
 * taeglicher Balken waeren das sonst neunzig.
 *
 * **Millisekunden werden zu Sekunden.** `duration_ms` ist die gespeicherte
 * Spalte, {@see Unit::DURATION} sind Sekunden. Eine Nachkommastelle bleibt
 * stehen, damit ein halbsekuendiger Ablauf nicht als „0" ausgewiesen wird; die
 * Formatierung auf dem Schirm rundet danach selbst auf ganze Sekunden, Minuten
 * oder Stunden, je nach Groesse.
 *
 * Laeufe ohne `duration_ms` — noch laufend, noch wartend — sind kein Nullwert,
 * sondern keine Antwort und bleiben aussen vor.
 */
class DurationP50 extends RunMetric
{
    public function handle(): string
    {
        return 'automations.duration_p50';
    }

    public function label(): string
    {
        return __('statamic-automations::insights.duration_p50');
    }

    public function description(): ?string
    {
        return __('statamic-automations::insights.duration_p50_description');
    }

    public function unit(): string
    {
        return Unit::DURATION;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        $measured = $this->measured($query);

        $count = (int) (clone $measured)->count();

        if ($count === 0) {
            return null;
        }

        $milliseconds = (clone $measured)
            ->orderBy('duration_ms')
            ->offset($this->rankIndex($count))
            ->limit(1)
            ->value('duration_ms');

        return $this->seconds((int) $milliseconds);
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $rows = $this->measured($query)
            ->selectRaw($this->bucketExpression($query).' as bucket, duration_ms')
            ->orderBy('duration_ms')
            ->get();

        /** @var array<string, array<int, int>> $perBucket */
        $perBucket = [];

        foreach ($rows as $row) {
            $perBucket[(string) $row->bucket][] = (int) $row->duration_ms;
        }

        $buckets = [];

        foreach ($perBucket as $bucket => $durations) {
            // Global nach `duration_ms` sortiert gelesen, also ist jede
            // Teilliste bereits sortiert — ein zweites sort() waere Arbeit fuer
            // eine Reihenfolge, die schon steht.
            $buckets[$bucket] = $this->seconds($durations[$this->rankIndex(count($durations))]);
        }

        ksort($buckets);

        return $buckets;
    }

    /** Laeufe im Fenster, die eine Dauer aufgezeichnet haben. */
    protected function measured(MetricQuery $query): Builder
    {
        return $this->untilNow($query)->whereNotNull('duration_ms');
    }

    /**
     * Der nullbasierte Rangplatz des Medians nach nearest rank.
     *
     * `ceil(0.5 * n) - 1`, ausgerechnet ohne Fliesskomma: bei vier Werten der
     * zweite, bei fuenf der dritte.
     */
    protected function rankIndex(int $count): int
    {
        return intdiv($count - 1, 2);
    }

    protected function seconds(int $milliseconds): float
    {
        return round($milliseconds / 1000, 1);
    }
}
