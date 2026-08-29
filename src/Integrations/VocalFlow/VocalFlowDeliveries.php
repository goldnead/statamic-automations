<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow;

use Goldnead\StatamicAutomations\Integrations\CalCom\CalComDeliveries;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Haelt fest, welche VocalFlow-Zustellung schon verarbeitet wurde.
 *
 * VocalFlow stellt erneut zu, wenn eine Antwort ausbleibt oder zu lange dauert:
 * der Versand laeuft als Job mit mehreren Versuchen, und der Job traegt die
 * einmal gebaute Nutzlast unveraendert mit sich. Ohne diese Schranke wuerde
 * eine abgeschlossene Session, die zweimal ankommt, zwei Ablaeufe starten und
 * damit zwei Mails an dieselbe Person schicken.
 *
 * Die Mechanik ist dieselbe wie bei {@see CalComDeliveries} — ein `Cache::add`
 * statt `has`+`put`, Gegenprobe wenn es `false` meldet, Freigabe wenn der Start
 * scheitert. Die Begruendungen stehen dort in voller Laenge und werden hier
 * nicht wiederholt. Was sich unterscheidet, ist die **Kennung**, und die ist
 * hier nicht dieselbe Wahl.
 *
 * ## Warum nicht die Kennung des Vorgangs
 *
 * Bei cal.com ist die Kennung `triggerEvent` plus Buchungs-`uid`, und das
 * traegt, weil eine Buchung genau einmal angelegt, einmal verlegt, einmal
 * abgesagt wird. Bei VocalFlow traegt es nicht: `task.updated` feuert
 * jedesmal, wenn jemand an derselben Aufgabe etwas aendert. Wer auf
 * `task.updated` plus `task.id` sperrt, verwirft die zweite echte Aenderung
 * als vermeintliche Wiederholung — und der Ablauf, der auf "Aufgabe ist jetzt
 * fertig" wartet, laeuft nie.
 *
 * Genommen wird deshalb der Fingerabdruck der **kanonischen Nutzlast**, also
 * genau der Zeichenkette, ueber die auch die Signatur laeuft. Das trennt die
 * beiden Faelle sauber:
 *
 * - Eine Wiederholung schickt dieselbe Nutzlast noch einmal, inklusive
 *   `timestamp` und `metadata.webhook_id`. Gleicher Fingerabdruck, gesperrt.
 * - Eine zweite echte Aenderung traegt einen neuen `timestamp` und ein neues
 *   `updated_at`. Anderer Fingerabdruck, laeuft.
 *
 * Nebenbei faellt damit ein Umweg weg, den ein Header-basierter Schluessel
 * offen liesse: VocalFlow schickt zwar ein `X-Webhook-ID`, das steht aber
 * **nicht in der signierten Nutzlast**. Wer darauf sperrt, sperrt auf einen
 * Wert, den ein Fremder mit einem mitgeschnittenen Rumpf frei setzen kann, und
 * kaeme damit an der Schranke vorbei. Der Fingerabdruck deckt nur Signiertes
 * ab.
 *
 * Fuer die veroeffentlichte Session gilt das nicht: die Nutzlast dort traegt
 * keinen Zeitstempel, sondern nur `session_id` und `student_email`. Dort ist
 * die `session_id` die richtige Kennung, und dieselbe Session ein zweites Mal
 * zu veroeffentlichen ist genau der Fall, den man einmal laufen lassen will.
 * Deshalb nimmt diese Klasse die Kennung entgegen, statt sie selbst zu waehlen.
 */
class VocalFlowDeliveries
{
    protected const PREFIX = 'statamic-automations.vocalflow.delivery.';

    /**
     * Vormerken, dass diese Zustellung verarbeitet wird.
     *
     * Antwortet `true`, wenn sie neu ist und der Ablauf starten darf, und
     * `false`, wenn dieselbe Kennung im selben Bereich schon durch war.
     *
     * @param  string  $scope  Womit die Kennung gilt, in der Regel der Ereignisname.
     * @param  string  $identity  Die Kennung selbst.
     */
    public function firstSeen(string $scope, string $identity): bool
    {
        $key = $this->keyFor($scope, $identity);

        if (Cache::add($key, true, now()->addMinutes($this->windowMinutes()))) {
            return true;
        }

        if (Cache::has($key)) {
            return false;
        }

        // Der Schluessel wurde nicht geschrieben und ist auch nicht da: dieser
        // Cache behaelt nichts. Die Schranke existiert dann nicht, und das muss
        // jemand erfahren. Durchlassen ist die richtige Richtung: ein Ablauf,
        // der einmal zu oft laeuft, ist reparabel, ein Anschluss, der stumm nie
        // laeuft, faellt monatelang niemandem auf.
        Log::warning('statamic-automations: der Cache behaelt nichts, der Schutz gegen doppelte VocalFlow-Zustellungen wirkt nicht.', [
            'store' => config('cache.default'),
            'scope' => $scope,
        ]);

        return true;
    }

    /**
     * Die Vormerkung zuruecknehmen.
     *
     * Gebraucht, wenn der Start des Ablaufs nach dem Vormerken fehlschlaegt.
     * Ohne das waere das Ereignis verloren: die Vormerkung stuende, VocalFlows
     * Wiederholung liefe in "schon dagewesen", und der Ablauf, der eigentlich
     * starten sollte, startet nie.
     */
    public function release(string $scope, string $identity): void
    {
        Cache::forget($this->keyFor($scope, $identity));
    }

    protected function keyFor(string $scope, string $identity): string
    {
        return self::PREFIX.sha1($scope.'|'.$identity);
    }

    /**
     * Wie lange eine Kennung als gesehen gilt, in Minuten.
     */
    protected function windowMinutes(): int
    {
        $configured = config('automations.integrations.vocalflow.dedupe_minutes', 1440);

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : 1440;
    }
}
