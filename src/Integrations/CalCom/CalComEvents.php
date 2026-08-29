<?php

namespace Goldnead\StatamicAutomations\Integrations\CalCom;

use Goldnead\StatamicAutomations\Listeners\HandleCommerceEvent;

/**
 * cal.coms `triggerEvent` => der Auslöser in diesem Addon.
 *
 * ## Die Handle-Regel, fuer einen Dienst statt eines Pakets
 *
 * Fuer die Nachbar-Addons gilt: `<Paketname ohne statamic-Praefix, Bindestriche
 * zu Unterstrichen>.<was geschehen ist, snake_case, Vergangenheit>`
 * ({@see HandleCommerceEvent}).
 *
 * cal.com ist kein Paket, es gibt also keinen Paketnamen, aus dem sich das
 * Praefix ableiten liesse. An seine Stelle tritt der Name des Dienstes, durch
 * dieselbe Muehle gedreht: kleingeschrieben, alles, was kein Buchstabe und
 * keine Ziffer ist, zu einem Unterstrich. Aus `cal.com` wird `cal_com`.
 *
 * Der Punkt in `cal.com` ist der Grund, warum das hier steht. Er ist im
 * Handle-Raum das Trennzeichen zwischen Praefix und Ereignis, und ein Handle
 * `cal.com.booking_created` haette drei Abschnitte statt zwei — jeder Leser und
 * jeder Parser, der vorne abschneidet, saehe dann das Praefix `cal`. Deshalb
 * wird der Punkt behandelt wie der Bindestrich in einem Paketnamen.
 *
 * Der Ereignisteil kommt aus cal.coms eigener Benennung, nur kleingeschrieben:
 * `BOOKING_CREATED` wird zu `booking_created`. Alle fuenf Namen stehen ohnehin
 * schon in der Vergangenheit, es ist also nichts umzudeuten — und ein Handle,
 * das genau so heisst wie das Ereignis beim Dienst, erspart jedem, der die
 * beiden Listen nebeneinanderlegt, eine Uebersetzungstabelle.
 *
 * Handles sind endgueltig. Was in einem gespeicherten Ablauf steht, laesst sich
 * nicht umbenennen, ohne den Ablauf zu zerreissen. Deshalb die Regel und nicht
 * der Geschmack.
 */
class CalComEvents
{
    /**
     * cal.com `triggerEvent` => Auslöser-Handle.
     *
     * @var array<string, string>
     */
    public const TRIGGERS = [
        'BOOKING_CREATED' => 'cal_com.booking_created',
        'BOOKING_CANCELLED' => 'cal_com.booking_cancelled',
        'BOOKING_RESCHEDULED' => 'cal_com.booking_rescheduled',
        'BOOKING_REQUESTED' => 'cal_com.booking_requested',
        'BOOKING_REJECTED' => 'cal_com.booking_rejected',
    ];

    /**
     * Das Handle zu einem `triggerEvent`, oder null fuer alles, wofuer dieses
     * Addon keinen Auslöser hat.
     */
    public static function handleFor(string $triggerEvent): ?string
    {
        return self::TRIGGERS[$triggerEvent] ?? null;
    }
}
