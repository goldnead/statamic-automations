<?php

namespace Goldnead\StatamicAutomations\Support;

use Illuminate\Support\Facades\Route;

/**
 * Die eine Stelle, die weiss, wie der Serien-Ausstiegs-Link aussieht.
 *
 * Gefragt wird sie vom Marketing-Addon, das die Mail rendert und die Route
 * hier nicht kennen soll. Genauso wie Marketing seinen Abmelde-Link ueber
 * PreferenceLink aufloest statt einen Pfad hart einzutragen: wer den Weg
 * kennt, wird gefragt — ein zweiter hartkodierter Pfad faellt beim naechsten
 * Umzug der Route lautlos um.
 *
 * `null` heisst: hier gibt es keinen Link. Die Routen koennen abgeschaltet
 * sein, und ein Token oder eine UUID koennen fehlen. Der Aufrufer laesst die
 * Zeile dann weg, statt eine Attrappe in eine Mail zu schreiben.
 */
class SequenceOptOutLink
{
    public const ROUTE = 'automations.sequence.opt-out';

    public function url(string $automationUuid, string $token): ?string
    {
        if (trim($automationUuid) === '' || trim($token) === '' || ! Route::has(self::ROUTE)) {
            return null;
        }

        return route(self::ROUTE, ['token' => $token, 'sequence' => $automationUuid], true);
    }
}
