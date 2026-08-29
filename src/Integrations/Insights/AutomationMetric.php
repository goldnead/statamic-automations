<?php

namespace Goldnead\StatamicAutomations\Integrations\Insights;

use Goldnead\StatamicAutomations\Support\RunStats;
use Goldnead\StatamicInsights\Support\TableMetric;

/**
 * Was jede Kennzahl dieses Addons gemeinsam hat.
 *
 * Der Vertrag liegt drueben in `statamic-insights`: dieses Addon kennt seine
 * Tabellen, das Analytics-Addon kennt Zeitraum, Vergleich, Diagramm und
 * Bildschirm. Keines nennt die Tabellen des anderen. Deshalb steht das
 * Geschwister-Paket in `suggest` und nicht in `require`, und deshalb wird keine
 * einzige Datei in diesem Ordner geladen, solange es nicht installiert ist
 * (siehe die Absicherung im ServiceProvider).
 *
 * **Was hier gemessen wird, ist Betrieb**: lief es durch, wie oft, wie lange,
 * und wer ist ausgestiegen. Der Trichter je Automation
 * ({@see RunStats}) und die 30-Tage-Kacheln
 * der Uebersicht bleiben, wo sie sind — sie beantworten eine Frage ueber EINE
 * Automation, und ein gemeinsamer Schirm neben Umsatz und Newsletter stellt die
 * Frage ueber den Betrieb im Ganzen.
 *
 * Zwei Regeln gelten fuer alles in diesem Ordner:
 *
 * 1. **Testlaeufe sind keine Zahlen.** Wer im Editor auf „Test" drueckt, ist
 *    keine Person, die durch den Ablauf geht. `RunStats` schliesst sie
 *    ebenfalls aus; taete es diese Seite nicht, laese sich die Erfolgsquote auf
 *    genau den Automationen am besten, die noch nie jemandem etwas geschickt
 *    haben. Die Bedingung sitzt in {@see RunMetric::inPeriod()}, also an der
 *    einen Stelle, die Kennzahl, Verlauf und jede Aufteilung zugleich traegt.
 * 2. **Eine Marke sieht ihre eigenen Zahlen.** Jede Tabelle hier traegt
 *    `brand_id`, und jeder andere Bildschirm des Addons laeuft durch
 *    `BrandScope`. Eine Kachel, die ueber alle Marken summiert, waehrend die
 *    Liste daneben eine Marke zeigt, ist die stille Sorte falsch. Angemeldet
 *    wird das mit {@see brandColumn()}; verengt wird zentral in
 *    {@see TableMetric::brandScoped()}.
 * 3. **Nichts hier meldet Zukunft.** Jede der fuenf Kennzahlen beantwortet,
 *    *was passiert ist*, und keine, *was ansteht*. Sie fragen deshalb alle ueber
 *    {@see TableMetric::untilNow()} statt ueber `inPeriod()`: beim Preset
 *    „gesamter Zeitraum" hat das Fenster keine obere Grenze, und was dann in
 *    einer Zeitspalte in der Zukunft steht, wird als Geschehenes gemeldet.
 *
 *    Heute kann keine der beiden gelesenen Spalten in der Zukunft liegen —
 *    `started_at` setzt die Maschine, wenn ein Lauf beginnt, `opted_out_at` der
 *    Klick. Die Klammer steht trotzdem, und zwar aus drei Gruenden: das Schema
 *    erzwingt es nicht (ein `timestamp`, den jeder Schreiber setzen kann), die
 *    Tabelle nebenan tut es bereits (`automation_scheduled_jobs.due_at` haelt
 *    genau das, was noch kommt), und der Fehler waere unsichtbar, weil er nur
 *    im weitesten Bereich auftritt, wo niemand die Zahl nachrechnet. Ein
 *    Vergleich mehr ist dafuer ein guenstiger Preis.
 *
 *    Wer hier je eine Kennzahl ueber `automation_scheduled_jobs.due_at`
 *    ergaenzt, klammert **nicht**: dort ist die Zukunft der Punkt.
 */
abstract class AutomationMetric extends TableMetric
{
    public function group(): string
    {
        return __('statamic-automations::insights.group');
    }

    /**
     * Die Spalte, an der diese Tabellen ihre Marke tragen.
     *
     * Mehr braucht es nicht: {@see TableMetric::inPeriod()} verengt damit
     * Kachel, Verlauf und jede Aufteilung zugleich, und zwar nach genau den
     * Regeln, nach denen `BrandScope` jedes Modell dieses Addons verengt.
     * Nachgebaut war das hier vorher von Hand — dieselbe Antwort, aber eine
     * eigene Kopie, und vier Kopien in der Addon-Familie sind vier
     * Gelegenheiten, spaeter auseinanderzulaufen.
     */
    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    /** Die Worte fuer eine Zeile, die in dieser Aufteilung keinen Wert hat. */
    protected function missingLabel(string $dimension): string
    {
        return __('statamic-automations::insights.no_'.$dimension);
    }
}
