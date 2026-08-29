<?php

namespace Goldnead\StatamicAutomations\Integrations\Insights;

use Goldnead\StatamicAutomations\Http\Controllers\Pages\DashboardPageController;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Von den Laeufen mit einem Urteil: wie viele haben funktioniert.
 *
 * **Der Nenner ist `erfolgreich + gescheitert`, nicht „alle Laeufe".** Ein Lauf,
 * der in einer Verzoegerung wartet, hat noch kein Urteil; einer, den ein
 * Stopp-Knoten beendet hat, hat eines, aber keines ueber die Technik. Beide in
 * den Nenner zu nehmen macht aus der Erfolgsquote eine Abschlussquote — eine
 * fuenfteilige Willkommensstrecke mit vier Tagen Wartezeit laese sich dann
 * dauerhaft bei dreissig Prozent, ohne dass je etwas kaputt war.
 *
 * Das weicht bewusst von der 30-Tage-Kachel der Addon-Uebersicht ab
 * ({@see DashboardPageController}),
 * die `erfolgreich / alle` rechnet. Auf einem gemeinsamen Schirm neben Webhooks
 * und Benachrichtigungen muss „Erfolgsquote" in allen drei Addons dasselbe
 * heissen, und diese Bedeutung ist die, die keine offene Frage als Misserfolg
 * verbucht.
 *
 * **Null ist nicht null Prozent.** Ohne einen einzigen abgeschlossenen Lauf gibt
 * es keine Antwort, und „0 %" waere eine Aussage ueber Laeufe, die es nicht gab.
 */
class SuccessRate extends RunMetric
{
    public function handle(): string
    {
        return 'automations.success_rate';
    }

    public function label(): string
    {
        return __('statamic-automations::insights.success_rate');
    }

    public function description(): ?string
    {
        return __('statamic-automations::insights.success_rate_description');
    }

    public function unit(): string
    {
        return Unit::PERCENT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        $row = $this->untilNow($query)
            ->selectRaw($this->verdictCounts(), $this->verdictBindings())
            ->first();

        return $this->rate((int) ($row->succeeded ?? 0), (int) ($row->failed ?? 0));
    }

    /**
     * Eine Quote je Eimer, und `null`, wo es nichts zu teilen gibt.
     *
     * Ausgelassen waere hier falsch: der Vertrag fuellt ausgelassene Eimer mit
     * einer Null auf, und eine Null ist bei einer Quote die Behauptung „nichts
     * hat funktioniert". `null` heisst „an diesem Tag ist kein Lauf zu einem
     * Ende gekommen" und laesst das Diagramm die Saeule weglassen, statt eine
     * leere zu zeichnen.
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $rows = $this->untilNow($query)
            ->selectRaw($this->bucketExpression($query).' as bucket, '.$this->verdictCounts(), $this->verdictBindings())
            ->groupBy('bucket')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $buckets[(string) $row->bucket] = $this->rate((int) $row->succeeded, (int) $row->failed);
        }

        ksort($buckets);

        return $buckets;
    }

    /**
     * Eine Nachkommastelle. „87.4629 %" behauptet eine Genauigkeit, die drei
     * Laeufe nicht tragen.
     */
    protected function rate(int $succeeded, int $failed): ?float
    {
        $decided = $succeeded + $failed;

        return $decided > 0 ? round($succeeded / $decided * 100, 1) : null;
    }

    /**
     * Beide Haelften in einer Abfrage.
     *
     * Zwei Zaehlungen waeren zwei Abfragen und, schlimmer, zwei Gelegenheiten,
     * unterschiedlich zu filtern.
     */
    protected function verdictCounts(): string
    {
        return 'sum(case when status = ? then 1 else 0 end) as succeeded, '
            .'sum(case when status = ? then 1 else 0 end) as failed';
    }

    /** @return array<int, string> */
    protected function verdictBindings(): array
    {
        return [AutomationRun::STATUS_SUCCESS, AutomationRun::STATUS_FAILED];
    }
}
