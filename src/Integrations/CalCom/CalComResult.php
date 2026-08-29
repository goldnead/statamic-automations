<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom;

use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowResult;

/**
 * Was ein Aufruf der cal.com-API ergeben hat.
 *
 * Bewusst ein eigener Typ und kein blosses Array, aus demselben Grund wie
 * {@see VocalFlowResult}:
 * wer `$result['ok']` liest und sich vertippt, bekommt `null`, was in PHP als
 * "nicht erfolgreich" durchgeht. Der Aufruf gilt dann als gescheitert, obwohl
 * er lief, und niemand sieht den Tippfehler.
 *
 * `status` ist der HTTP-Code oder `0`, wenn es gar nicht bis zu einer Antwort
 * kam (Netz weg, Zeitueberschreitung, TLS).
 *
 * `versionMismatch` ist die Eigenheit dieses Dienstes und der Grund, warum
 * dieser Typ nicht einfach der von VocalFlow ist. cal.com verlangt je Endpunkt
 * eine andere `cal-api-version` und antwortet bei der falschen mit **404**,
 * nicht mit 400. Ein Aufrufer, der nur den Code sieht, sucht dann nach einem
 * Termin, den es angeblich nicht gibt, waehrend in Wahrheit die Kopfzeile
 * falsch ist. Das Flag haelt die beiden 404 auseinander, damit die
 * Fehlermeldung an der richtigen Stelle suchen laesst.
 */
class CalComResult
{
    /**
     * @param  array<mixed>  $data  Der `data`-Zweig der Antwort, bei Erfolg.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly array $data = [],
        public readonly ?string $error = null,
        public readonly bool $versionMismatch = false,
        public readonly bool $recognised = true,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function success(int $status, array $data): self
    {
        return new self(ok: true, status: $status, data: $data);
    }

    public static function failure(int $status, string $error, bool $versionMismatch = false, bool $recognised = true): self
    {
        return new self(
            ok: false,
            status: $status,
            error: $error,
            versionMismatch: $versionMismatch,
            recognised: $recognised,
        );
    }

    /**
     * Hat **cal.com** die Anfrage abgelehnt, weil die Sache nicht existiert?
     *
     * Drei Bedingungen, und alle drei sind noetig, weil ein 404 aus drei ganz
     * verschiedenen Richtungen kommen kann:
     *
     *   - `status === 404`, das Offensichtliche.
     *   - `! $versionMismatch`: eine falsche `cal-api-version` antwortet
     *     ebenfalls 404. Wer die beiden verwechselt, sucht nach einem Termin,
     *     der existiert.
     *   - `$recognised`: die Antwort war cal.coms eigene Fehlerform. Ein 404
     *     von einem Proxy, einem CDN oder einer falsch eingetragenen Adresse
     *     ist HTML und heisst nicht "diese Terminart gibt es nicht", sondern
     *     "wir haben mit cal.com gar nicht gesprochen". Diese beiden
     *     auseinanderzuhalten entscheidet darueber, ob jemand seine
     *     Einrichtung prueft oder seine Adresse.
     *
     * Wer "gibt es nicht" meint, fragt deshalb hier und nicht den Code ab.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404 && ! $this->versionMismatch && $this->recognised;
    }
}
