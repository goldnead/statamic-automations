<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom;

/**
 * Prueft, ob eine eingehende Nutzlast wirklich von cal.com kommt.
 *
 * cal.com legt jedem Webhook den Header `x-cal-signature-256` bei: HMAC-SHA256
 * ueber den Rumpf der Anfrage, mit dem Secret des Webhooks als Schluessel, als
 * Hex-Zeichenkette.
 *
 * Zwei Punkte entscheiden darueber, ob die Pruefung etwas wert ist.
 *
 * **Der rohe Rumpf, nicht das dekodierte Array.** Wer `json_decode` und danach
 * `json_encode` benutzt, um die Signatur zu bilden, prueft nicht mehr die
 * Bytes, die cal.com signiert hat: Schluesselreihenfolge, Escaping von
 * Schraegstrichen, Unicode-Escapes und die Genauigkeit von Fliesskommazahlen
 * ueberleben den Umweg nicht. Die Signatur schlaegt dann bei echten Nutzlasten
 * fehl und der naechstliegende Ausweg waere, die Pruefung zu lockern. Deshalb
 * nimmt diese Klasse ausschliesslich einen String entgegen.
 *
 * **Vergleich in konstanter Zeit.** `===` bricht beim ersten abweichenden Byte
 * ab. Wer die Route oft genug aufruft, kann aus den Laufzeiten Byte fuer Byte
 * eine gueltige Signatur rekonstruieren. `hash_equals` laeuft immer ueber die
 * volle Laenge.
 */
class CalComSignature
{
    /**
     * Der Header, den cal.com setzt. Kleingeschrieben, weil Laravels
     * `Request::header()` ohnehin ohne Ruecksicht auf Gross- und
     * Kleinschreibung sucht.
     */
    public const HEADER = 'x-cal-signature-256';

    /**
     * Stimmt die mitgelieferte Signatur fuer diesen Rumpf und dieses Secret?
     *
     * Antwortet `false`, sobald eines der drei Stuecke fehlt. Ein leeres Secret
     * ist keine Erlaubnis, sondern ein Grund abzulehnen: sonst wuerde ein
     * vergessener Eintrag in der Konfiguration die Route fuer jeden oeffnen,
     * der ihre URL kennt.
     */
    public static function matches(?string $secret, string $rawBody, ?string $provided): bool
    {
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals(self::sign($secret, $rawBody), $provided);
    }

    /**
     * Die Signatur, die cal.com fuer diesen Rumpf gebildet haette.
     *
     * Oeffentlich, weil die Tests damit echte Anfragen bauen. Eine Testsuite,
     * die ihre Signatur selbst nachprogrammiert, prueft am Ende ihre eigene
     * Kopie und nicht diesen Code.
     */
    public static function sign(string $secret, string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }
}
