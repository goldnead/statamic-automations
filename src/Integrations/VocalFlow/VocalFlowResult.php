<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow;

/**
 * Was ein Aufruf der Partner-API ergeben hat.
 *
 * Bewusst ein eigener Typ und kein blosses Array: eine Aktion, die
 * `$result['ok']` liest, kann sich vertippen und bekommt dann `null`, was in
 * PHP als "nicht erfolgreich" durchgeht — der Aufruf gilt als gescheitert,
 * obwohl er lief, und der Ablauf bricht ab, ohne dass jemand den Tippfehler
 * sieht.
 *
 * `status` ist der HTTP-Code oder `0`, wenn es gar nicht bis zu einer Antwort
 * kam (Netz weg, Zeitueberschreitung, TLS). Die Unterscheidung zaehlt fuer die
 * Fehlermeldung: "VocalFlow war nicht erreichbar" und "VocalFlow hat die
 * Anfrage abgelehnt" schickt man an verschiedene Stellen.
 */
class VocalFlowResult
{
    /**
     * @param  array<string, mixed>  $data  Der `data`-Zweig der Antwort, bei Erfolg.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly array $data = [],
        public readonly ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(int $status, array $data): self
    {
        return new self(ok: true, status: $status, data: $data);
    }

    public static function failure(int $status, string $error): self
    {
        return new self(ok: false, status: $status, error: $error);
    }
}
