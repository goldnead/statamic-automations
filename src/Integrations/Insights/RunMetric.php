<?php

namespace Goldnead\StatamicAutomations\Integrations\Insights;

use Goldnead\StatamicAutomations\Casts\MillisecondDateTime;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\TableMetric;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Was aus `automation_runs` gelesen wird.
 *
 * **Der Zeitstempel ist `started_at`, nicht `created_at`.** Eine Zeile entsteht,
 * wenn die Warteschlange sie annimmt; der Lauf passiert, wenn er anfaengt. Auf
 * einer Box mit Rueckstau liegen zwischen beidem Minuten, und eine Tagesachse,
 * die nach `created_at` bucketet, verschiebt eine Nachtspitze auf den Vortag.
 * Der Preis ist benannt: ein Lauf, der noch in der Warteschlange steht, hat kein
 * `started_at` und zaehlt in keiner Zahl mit — was richtig ist, denn er ist noch
 * nicht gelaufen. Sobald er anfaengt, erscheint er.
 *
 * Gefragt wird ueber `untilNow()` und nicht ueber `inPeriod()`: ein Lauf, dessen
 * Start in der Zukunft steht, ist kein gelaufener Lauf. Die vollstaendige
 * Begruendung steht in {@see AutomationMetric}.
 *
 * Auch die Fehlschlaege haengen an `started_at` und nicht an `finished_at`. Das
 * ist eine Kohorten-Sicht: von den Laeufen, die in diesem Zeitraum begonnen
 * haben, sind so viele gescheitert. Nur so teilen Zaehler und Nenner denselben
 * Nenner — datierte man Fehlschlaege auf ihr Ende, koennte eine Quote ueber
 * hundert Prozent steigen, sobald ein langer Lauf ueber Mitternacht scheitert.
 */
abstract class RunMetric extends AutomationMetric
{
    protected function table(): string
    {
        return 'automation_runs';
    }

    protected function timestamp(): string
    {
        return 'started_at';
    }

    /**
     * Jeder echte Lauf im Fenster, dieser Marke, ohne Testlaeufe.
     *
     * Alles darunter baut hierauf auf, also gilt eine Bedingung von hier fuer
     * Kachel, Verlauf und jede Aufteilung zugleich und kann in keiner davon
     * vergessen werden. Die Marke steht nicht mehr hier: die haengt an
     * {@see AutomationMetric::brandColumn()} und wird von `parent::inPeriod()`
     * angelegt.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        return parent::inPeriod($query, $column)->where('is_test', false);
    }

    /**
     * Dieselbe Klammer wie oben, aber mit Millisekunden.
     *
     * {@see TableMetric::untilNow()} vergleicht gegen `Carbon::now()`, und
     * Laravel bindet ein Datum mit dem Format der Verbindung — `Y-m-d H:i:s`,
     * ohne Bruchteil. Diese Tabelle speichert `started_at` aber mit
     * Millisekunden ({@see MillisecondDateTime}, seit
     * `2026_07_28_000002_add_millisecond_precision_to_run_timestamps`), also
     * steht dort „23:30:00.000" und die Schranke lautet „23:30:00".
     *
     * Auf SQLite ist das ein Textvergleich, und „23:30:00.000" ist groesser als
     * „23:30:00" — jeder Lauf der laufenden Sekunde fiele aus jeder Zahl heraus
     * und erschiene erst eine Sekunde spaeter. Auf MySQL mit `timestamp(3)` ist
     * derselbe Vergleich typisiert und ginge gut, was die Sache schlimmer
     * macht: eine gruene Suite auf SQLite haette es nie gezeigt, und auf
     * Postgres waere es wieder ein Problem.
     *
     * Die Schranke wird deshalb hier im selben Format formatiert, in dem die
     * Spalte geschrieben wird. Gefunden hat es der Zeitzonen-Test, der einen
     * Lauf um 23:30 anlegt und ihn im selben Augenblick zaehlt.
     */
    protected function untilNow(MetricQuery $query, ?string $column = null): Builder
    {
        $column ??= $this->timestamp();

        return $this->inPeriod($query, $column)
            ->where($column, '<=', Carbon::now()->format(MillisecondDateTime::FORMAT));
    }

    /**
     * Die Aufteilungen, die jede Lauf-Kennzahl anbieten kann.
     *
     * @return array<string, string>
     */
    protected function runBreakdowns(): array
    {
        return [
            'status' => __('statamic-automations::insights.breakdown_status'),
            'trigger' => __('statamic-automations::insights.breakdown_trigger'),
            'automation' => __('statamic-automations::insights.breakdown_automation'),
        ];
    }

    /**
     * Eine Aufteilung ueber die Laeufe, mit lesbaren Beschriftungen.
     *
     * Die Spalte ist nicht der Name der Aufteilung: `trigger` teilt nach
     * `trigger_type`, `automation` nach `automation_uuid`. Die Handles sind das,
     * was in gespeicherten Ansichten und URLs landet, also sind sie kurz und
     * bleiben, wie sie sind.
     *
     * @return array<int, array{key: string|null, label: string, value: int|float}>
     */
    protected function splitRuns(MetricQuery $query, string $dimension, int $limit, string $aggregate = 'count(*)'): array
    {
        $column = match ($dimension) {
            'status' => 'status',
            'trigger' => 'trigger_type',
            'automation' => 'automation_uuid',
            default => null,
        };

        if ($column === null) {
            return [];
        }

        $rows = $this->splitByColumn($this->untilNow($query), $query, $column, $aggregate, $limit);

        return $this->labelRows($rows, $dimension);
    }

    /**
     * @param  array<int, array{key: string|null, value: int|float}>  $rows
     * @return array<int, array{key: string|null, label: string, value: int|float}>
     */
    protected function labelRows(array $rows, string $dimension): array
    {
        $names = $dimension === 'automation'
            ? $this->automationNames(array_values(array_filter(array_column($rows, 'key'))))
            : [];

        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => match (true) {
                $row['key'] === null => $this->missingLabel($dimension),
                $dimension === 'status' => $this->statusLabel($row['key']),
                $dimension === 'automation' => $names[$row['key']] ?? $row['key'],
                default => $row['key'],
            },
            'value' => $row['value'],
        ], $rows);
    }

    /**
     * Der Name der Automation, nie ihre uuid.
     *
     * Eine uuid auf einer Kachel ist eine Zeile, die niemand liest. Zwei Wege,
     * weil eine Automation nicht in der Datenbank stehen muss: der Flat-File-
     * Treiber haelt Definitionen in YAML, die Laeufe aber immer in der Tabelle.
     * Also erst ein `whereIn` ueber `automations` — eine Abfrage fuer alle
     * Zeilen der Aufteilung — und nur fuer das, was dann noch fehlt, das
     * Repository, dessen Treiber beide Ablagen kennt.
     *
     * Der zweite Weg ist durch `$limit` begrenzt und faellt im Normalbetrieb
     * (Datenbank-Treiber) gar nicht an. Wirft er, bleibt es bei der uuid: eine
     * kaputte Definition darf eine Beschriftung kosten, nie die ganze Kachel.
     *
     * @param  array<int, string>  $uuids
     * @return array<string, string>
     */
    protected function automationNames(array $uuids): array
    {
        if ($uuids === [] || ! Schema::hasTable('automations')) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = DB::table('automations')
            ->whereIn('uuid', $uuids)
            ->pluck('name', 'uuid')
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->all();

        $missing = array_values(array_diff($uuids, array_keys($names)));

        if ($missing === []) {
            return $names;
        }

        try {
            $repository = app(AutomationRepository::class);

            foreach ($missing as $uuid) {
                $found = $repository->find($uuid);

                if ($found !== null && is_string($found->name) && $found->name !== '') {
                    $names[$uuid] = $found->name;
                }
            }
        } catch (Throwable) {
            // Beim Handle bleiben. Siehe oben.
        }

        return $names;
    }

    /**
     * Ein Status in Worten, sonst der Status selbst.
     *
     * Uebersetzt wird nur, was dieses Addon besitzt: die sieben Zustaende in
     * {@see AutomationRun}. Kommt in einer spaeteren Fassung ein achter dazu,
     * erscheint sein Handle statt eines fehlenden Schluessels — sichtbar,
     * unmissverstaendlich und ohne dass diese Datei mitwachsen muss.
     *
     * Ausloeser-Handles werden bewusst NICHT uebersetzt: sie werden von
     * fremden Addons registriert und sind offen. Ein huebscheres Wort dafuer zu
     * erfinden hiesse, dass die Aufteilung anders heisst als der Knoten im
     * Editor, und zwei Namen fuer dieselbe Sache sind schlimmer als ein
     * technischer.
     */
    protected function statusLabel(string $status): string
    {
        $key = 'statamic-automations::insights.status.'.$status;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $status;
    }

    /** Die Laeufe im Fenster, die mit einem Fehler geendet haben. */
    protected function failedInPeriod(MetricQuery $query): Builder
    {
        return $this->untilNow($query)->where('status', AutomationRun::STATUS_FAILED);
    }
}
