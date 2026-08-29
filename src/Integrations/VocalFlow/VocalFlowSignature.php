<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow;

use Goldnead\StatamicAutomations\Integrations\CalCom\CalComSignature;

/**
 * Prueft, ob eine eingehende Nutzlast wirklich von VocalFlow kommt.
 *
 * VocalFlow legt jedem Webhook den Header `X-Webhook-Signature` bei:
 * `sha256=` gefolgt von einem HMAC-SHA256 als Hex-Zeichenkette, mit dem Secret
 * des Abos als Schluessel.
 *
 * ## Warum hier nicht der rohe Rumpf signiert wird, und bei cal.com schon
 *
 * {@see CalComSignature} nimmt ausschliesslich einen String entgegen und
 * verbietet den Umweg ueber `json_decode`/`json_encode` ausdruecklich. Hier ist
 * es genau umgekehrt, und das ist kein Versehen, sondern die Vorgabe der
 * Gegenseite.
 *
 * VocalFlow bildet die Signatur nicht ueber die Bytes, die es dann verschickt,
 * sondern ueber eine kanonische Fassung der Nutzlast:
 *
 * ```php
 * // vocal-flow, app/Services/WebhookSecurityService.php
 * $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
 * return hash_hmac('sha256', $jsonPayload, $secret);
 * ```
 *
 * Verschickt wird die Nutzlast danach ueber Laravels `Http::post($url,
 * $payload)`, und der HTTP-Client kodiert sie ein zweites Mal, mit seinen
 * eigenen Flags. Die Bytes auf der Leitung escapen Schraegstriche und
 * Nicht-ASCII-Zeichen deshalb anders als die Bytes, die signiert wurden: aus
 * `https://beispiel.de` wird `https:\/\/beispiel.de`, aus `ü` wird `ü`.
 *
 * Ein Empfaenger, der wie bei cal.com ueber den rohen Rumpf prueft, weist damit
 * **jede echte Zustellung** ab, sobald irgendwo ein Schraegstrich oder ein
 * Umlaut in der Nutzlast steht — und in einer VocalFlow-Nutzlast steht beides
 * immer, spaetestens im `model_type` (`App\Models\Session`) und in deutschen
 * Namen. Der Fehler saehe aus wie ein falsch eingetragenes Secret und wuerde
 * genau dort gesucht.
 *
 * Also wird hier dieselbe Kanonisierung nachgebaut: dekodieren, mit denselben
 * beiden Flags neu kodieren, darueber den HMAC. Was verglichen wird, ist damit
 * der **Inhalt** der Nutzlast und nicht ihre Schreibweise.
 *
 * ## Was diese Verschiebung kostet, und was sie nicht kostet
 *
 * Sie kostet, dass dekodiert werden muss, bevor feststeht, dass die Nutzlast
 * von VocalFlow stammt. Bei cal.com liegt die Signaturpruefung vor jedem
 * `json_decode`; hier ist das Dekodieren Teil der Pruefung und laesst sich
 * nicht dahinter schieben. Deshalb steht im Controller die Groessenschranke
 * davor und `json_decode` bekommt eine ausdrueckliche Tiefenbegrenzung: was ein
 * Fremder ohne Secret an Arbeit ausloesen kann, ist damit auf das Dekodieren
 * einer beschraenkten Zeichenkette gedeckelt.
 *
 * Sie kostet **nicht** die Reihenfolge der Schluessel. `json_decode` haelt sie
 * ein und `json_encode` gibt sie unveraendert wieder aus, also fuehrt eine
 * umsortierte Nutzlast weiterhin zu einer anderen Signatur. Was wegfaellt, ist
 * ausschliesslich die Schreibweise: Escaping, Leerraum zwischen den Zeichen,
 * die Schreibung gleichwertiger Zahlen. Alles davon ist bedeutungsgleich, und
 * keines davon traegt Inhalt, an dem ein Ablauf haengt.
 *
 * ## Vergleich in konstanter Zeit
 *
 * Wie bei cal.com und aus demselben Grund: `===` bricht beim ersten
 * abweichenden Byte ab, und wer die Route oft genug aufruft, kann aus den
 * Laufzeiten Byte fuer Byte eine gueltige Signatur rekonstruieren.
 * `hash_equals` laeuft immer ueber die volle Laenge.
 */
class VocalFlowSignature
{
    /**
     * Der Header, den VocalFlow setzt. Kleingeschrieben, weil Laravels
     * `Request::header()` ohnehin ohne Ruecksicht auf Gross- und
     * Kleinschreibung sucht.
     */
    public const HEADER = 'x-webhook-signature';

    /**
     * Das Praefix, das VocalFlow der Hex-Zeichenkette voranstellt.
     *
     * Es ist Teil des Vergleichs und wird nicht abgeschnitten. Ein Empfaenger,
     * der es wegwirft und nur den Rest prueft, akzeptiert `md5=<hex>` und
     * `=<hex>` genauso — und laedt damit ein, dass ein spaeterer
     * Verfahrenswechsel auf der Gegenseite hier unbemerkt bleibt.
     */
    public const PREFIX = 'sha256=';

    /**
     * Die groesste Verschachtelungstiefe, die beim Dekodieren angenommen wird.
     *
     * PHPs Vorgabe ist 512. Eine VocalFlow-Nutzlast ist vier Ebenen tief
     * (`data.session.…`), 64 ist also weit jenseits von allem Echten und
     * begrenzt gleichzeitig, was ein Fremder ohne Secret an Rekursion
     * ausloesen kann — sowohl beim Dekodieren als auch beim Kanonisieren.
     */
    public const MAX_DEPTH = 64;

    /**
     * Die kanonische Fassung einer Nutzlast: das, worueber VocalFlow signiert.
     *
     * Antwortet `null`, wenn sich der Wert nicht kodieren laesst — etwa an
     * einer Fliesskommazahl `INF` oder jenseits der Tiefengrenze. Ein `null`
     * bedeutet "keine Signatur bildbar", und der Aufrufer lehnt ab. Der
     * naheliegende Fehler waere, `json_encode`s `false` stillschweigend als
     * leere Zeichenkette weiterzureichen: dann verglichen zwei Aufrufe den HMAC
     * ueber "" und die Pruefung liesse eine Nutzlast durch, die niemand gelesen
     * hat.
     *
     * ## Zwei Fallstricke, an denen diese Kanonisierung sonst still bricht
     *
     * Beide haben dieselbe Bauart: `json_encode` haengt nicht nur an seinen
     * Flags, sondern an Dingen ausserhalb des Aufrufs — und Absender und
     * Empfaenger sind zwei verschiedene Rechner.
     *
     * **`serialize_precision`.** Wie viele Stellen `json_encode` einer
     * Fliesskommazahl gibt, steht in der `php.ini`. Steht sie auf `17`, wird
     * aus `0.1` die Zeichenkette `0.10000000000000001`; steht sie auf `-1`
     * (PHPs Vorgabe, und was Laravel unangetastet laesst), bleibt es `0.1`.
     * Zwei Hosts mit verschiedenen Werten bilden fuer dieselbe Nutzlast
     * verschiedene kanonische Fassungen, und **jede** Zustellung mit einer
     * Kommazahl im Rumpf scheitert dauerhaft mit 403 — also genau der Fehler,
     * den diese Klasse vermeiden soll, nur eine Ebene tiefer. Deshalb wird der
     * Wert hier fuer die Dauer des Aufrufs auf PHPs Vorgabe festgenagelt statt
     * dem Host ueberlassen.
     *
     * **`{}` gegen `[]`.** `json_decode($raw, true)` macht aus einem leeren
     * JSON-Objekt ein leeres PHP-Array, und das kodiert wieder als `[]` — aus
     * `{"metadata":{}}` wuerde `{"metadata":[]}`, und die Signatur passt nicht
     * mehr. Deshalb nimmt diese Methode den **Objekt-Graphen** entgegen
     * (`json_decode($raw, false)`), nicht das assoziative Array: dort bleibt
     * ein Objekt ein Objekt.
     *
     * Bei VocalFlow kann `{}` heute nicht auftreten, weil dort ein PHP-Array
     * kodiert wird und PHP aus einem leeren Array kein `{}` machen kann. Das
     * ist aber eine Eigenschaft des heutigen Absenders und keine des
     * Verfahrens, und sie kostet hier nichts.
     */
    public static function canonical(mixed $decoded): ?string
    {
        // Fuer die Dauer des Aufrufs auf PHPs Vorgabe, danach zurueck. Global
        // umzustellen waere ein Eingriff in die ganze Anwendung fuer einen
        // Zweck, der zwei Zeilen weit reicht.
        $previous = ini_get('serialize_precision');
        $pinned = $previous !== false && ini_set('serialize_precision', '-1') !== false;

        try {
            $json = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, self::MAX_DEPTH);
        } finally {
            if ($pinned) {
                ini_set('serialize_precision', $previous);
            }
        }

        return is_string($json) ? $json : null;
    }

    /**
     * Stimmt die mitgelieferte Signatur fuer diese kanonische Fassung und
     * dieses Secret?
     *
     * Antwortet `false`, sobald eines der Stuecke fehlt. Ein leeres Secret ist
     * keine Erlaubnis, sondern ein Grund abzulehnen: sonst wuerde ein
     * vergessener Eintrag in der Konfiguration die Route fuer jeden oeffnen,
     * der ihre URL kennt.
     *
     * Nimmt die kanonische Fassung und nicht die Nutzlast, damit der Aufrufer
     * sie genau einmal bildet: der Controller braucht sie danach noch als
     * Kennung gegen Doppelzustellung, und zweimal zu kodieren hiesse, zwei
     * Ergebnisse zu haben, die auseinanderlaufen koennen.
     */
    public static function matchesCanonical(?string $secret, string $canonical, ?string $provided): bool
    {
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals(self::sign($secret, $canonical), $provided);
    }

    /**
     * Dasselbe, ausgehend von der dekodierten Nutzlast.
     *
     * Die bequeme Fassung fuer Aufrufer, die die kanonische Form nicht selbst
     * brauchen.
     */
    public static function matches(?string $secret, mixed $decoded, ?string $provided): bool
    {
        $canonical = self::canonical($decoded);

        if ($canonical === null) {
            return false;
        }

        return self::matchesCanonical($secret, $canonical, $provided);
    }

    /**
     * Die Signatur, die VocalFlow fuer diese kanonische Fassung gebildet
     * haette, samt Praefix.
     *
     * Oeffentlich, weil die Tests damit echte Anfragen bauen. Eine Testsuite,
     * die ihre Signatur selbst nachprogrammiert, prueft am Ende ihre eigene
     * Kopie und nicht diesen Code.
     */
    public static function sign(string $secret, string $canonical): string
    {
        return self::PREFIX.hash_hmac('sha256', $canonical, $secret);
    }
}
