<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Haelt fest, welche cal.com-Zustellung schon verarbeitet wurde.
 *
 * cal.com stellt erneut zu, wenn eine Antwort ausbleibt oder zu lange dauert.
 * Ohne diese Schranke wuerde eine Absage, die zweimal ankommt, zwei Ablaeufe
 * starten und damit zwei Mails an dieselbe Person schicken.
 *
 * Die Kennung ist das Paar aus Ereignistyp und Buchungs-`uid`. Die `uid` allein
 * reicht nicht: dieselbe Buchung wird angelegt, verschoben und abgesagt, und
 * das sind drei Vorgaenge, die alle laufen sollen. Eine Verlegung bekommt bei
 * cal.com ohnehin eine eigene `uid`, die alte steht daneben in `rescheduleUid`.
 *
 * ## Ein Aufruf, nicht zwei
 *
 * `Cache::add` schreibt nur, wenn der Schluessel noch nicht existiert, und
 * meldet in derselben Bewegung, ob es geklappt hat. Der naheliegende Weg, erst
 * `has` und dann `put`, hat zwischen den beiden Aufrufen eine Luecke, und genau
 * in diese Luecke faellt die zweite Zustellung, wenn cal.com sie parallel
 * schickt oder zwei Arbeiter dieselbe Anfrage bearbeiten. Der Weg mit der
 * Luecke ist der, der im Test funktioniert und im Betrieb gelegentlich nicht.
 *
 * ## Was passiert, wenn der Cache nichts behaelt
 *
 * `Cache::add` ist nicht bei jedem Treiber dasselbe. Beim `null`-Treiber
 * antwortet es **immer** `false`. Wer das ungeprueft als "schon dagewesen"
 * liest, verwirft jede einzelne Zustellung, antwortet cal.com dabei mit 200 und
 * startet nie einen Ablauf: der Anschluss meldet Erfolg und tut nichts. Deshalb
 * wird ein `false` gegengeprueft. Steht der Schluessel danach nicht im Cache,
 * kann dieser Cache gar nichts behalten, und dann ist Durchlassen die richtige
 * Richtung: ein Ablauf, der einmal zu oft laeuft, ist reparabel, ein Anschluss,
 * der stumm nie laeuft, faellt monatelang niemandem auf.
 *
 * Stumm bleibt das trotzdem nicht. Wer diesen Zustand hat, will ihn wissen.
 */
class CalComDeliveries
{
    protected const PREFIX = 'statamic-automations.cal_com.delivery.';

    /**
     * Vormerken, dass diese Zustellung verarbeitet wird.
     *
     * Antwortet `true`, wenn sie neu ist und der Ablauf starten darf, und
     * `false`, wenn dasselbe Ereignis mit derselben `uid` schon durch war.
     *
     * Fehlt die `uid`, tritt der Fingerabdruck des rohen Rumpfes an ihre
     * Stelle. Eine Wiederholung schickt Byte fuer Byte dieselbe Nutzlast, also
     * traegt der Fingerabdruck genau so weit wie die `uid`.
     */
    public function firstSeen(string $triggerEvent, ?string $uid, string $rawBody): bool
    {
        $key = $this->keyFor($triggerEvent, $uid, $rawBody);

        if (Cache::add($key, true, now()->addMinutes($this->windowMinutes()))) {
            return true;
        }

        if (Cache::has($key)) {
            return false;
        }

        // Der Schluessel wurde nicht geschrieben und ist auch nicht da: dieser
        // Cache behaelt nichts. Die Schranke existiert dann nicht, und das muss
        // jemand erfahren.
        Log::warning('statamic-automations: der Cache behaelt nichts, der Schutz gegen doppelte cal.com-Zustellungen wirkt nicht.', [
            'store' => config('cache.default'),
            'trigger_event' => $triggerEvent,
        ]);

        return true;
    }

    /**
     * Die Vormerkung zuruecknehmen.
     *
     * Gebraucht, wenn der Start des Ablaufs nach dem Vormerken fehlschlaegt.
     * Ohne das waere die Buchung verloren: die Vormerkung stuende, cal.coms
     * Wiederholung liefe in "schon dagewesen", und der Ablauf, der eigentlich
     * starten sollte, startet nie. Die Schranke wuerde damit genau den
     * Mechanismus entwaffnen, dessentwegen es sie gibt.
     */
    public function release(string $triggerEvent, ?string $uid, string $rawBody): void
    {
        Cache::forget($this->keyFor($triggerEvent, $uid, $rawBody));
    }

    protected function keyFor(string $triggerEvent, ?string $uid, string $rawBody): string
    {
        $identity = is_string($uid) && $uid !== ''
            ? $uid
            : 'body:'.hash('sha256', $rawBody);

        return self::PREFIX.sha1($triggerEvent.'|'.$identity);
    }

    /**
     * Wie lange eine Kennung als gesehen gilt, in Minuten.
     */
    protected function windowMinutes(): int
    {
        $configured = config('automations.integrations.cal_com.dedupe_minutes', 1440);

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : 1440;
    }
}
