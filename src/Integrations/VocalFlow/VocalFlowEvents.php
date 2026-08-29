<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow;

use Goldnead\StatamicAutomations\Integrations\CalCom\CalComEvents;
use Goldnead\StatamicAutomations\Listeners\HandleCommerceEvent;

/**
 * VocalFlows `event` => der Auslöser in diesem Addon.
 *
 * ## Die Handle-Regel, und warum sie hier `vocalflow` ergibt und nicht
 * `vocal_flow`
 *
 * Die Regel lautet: `<Dienst- oder Paketname, klein, alles
 * Nicht-Alphanumerische zu einem Unterstrich>.<was geschehen ist, snake_case,
 * Vergangenheit>` ({@see HandleCommerceEvent}, {@see CalComEvents}).
 *
 * Auf `VocalFlow` angewandt: kleinschreiben ergibt `vocalflow`. Ein
 * nicht-alphanumerisches Zeichen, das zu ersetzen waere, kommt darin nicht vor
 * — anders als bei `cal.com`, wo genau der Punkt der Grund fuer den Unterstrich
 * war. Also `vocalflow`.
 *
 * Der Zug in Richtung `vocal_flow` ist echt, weil man die Wortgrenze sieht. Ihm
 * nachzugeben hiesse aber, eine zweite Regel einzufuehren: "Binnenmajuskeln
 * werden zu Unterstrichen". Diese Regel gibt es hier nicht, und sie waere keine
 * mechanische, sondern eine mit Ermessen — bei `VocalFlow` ist die Grenze
 * eindeutig, bei einem kuenftigen `HubSpot`, `PayPal` oder `iCal` faengt die
 * Diskussion an.
 *
 * Entscheidend ist der Nachbar, der schon steht: das Paket `statamic-leadhub`
 * heisst im Handle-Raum `leadhub.` und nicht `lead_hub.`, obwohl die Wortgrenze
 * dort genauso sichtbar ist. Ein Praefix `vocal_flow` waere gegen diesen
 * Praezedenzfall, und zwei Regeln nebeneinander sind schlechter als eine, die
 * an einer Stelle etwas unschoen aussieht.
 *
 * Der Ereignisteil kommt aus VocalFlows eigener Benennung, nur mit Unterstrich
 * statt Punkt: `session.created` wird zu `session_created`. Alle Namen stehen
 * dort ohnehin schon in der Vergangenheit. Ein Handle, das genau so heisst wie
 * das Ereignis beim Dienst, erspart jedem, der die beiden Listen
 * nebeneinanderlegt, eine Uebersetzungstabelle.
 *
 * Handles sind endgueltig. Was in einem gespeicherten Ablauf steht, laesst sich
 * nicht umbenennen, ohne den Ablauf zu zerreissen. Deshalb die Regel und nicht
 * der Geschmack.
 *
 * ## Was VocalFlow heute wirklich schickt
 *
 * Die sechs Namen unten sind aus VocalFlows Quelltext erhoben und sind die
 * Namen, auf die sich dort ein Abo anlegen laesst
 * (`WebhookSubscription::EVENT_*`). Ob hinter jedem Namen heute auch ein
 * Absender steht, ist eine andere Frage. Der Stand, nachgeschlagen im
 * VocalFlow-Quelltext:
 *
 * | Ereignis            | Klasse | am versendenden Zuhoerer | wird ausgeloest |
 * |---------------------|--------|--------------------------|-----------------|
 * | `session.created`   | ja     | ja                       | **ja**          |
 * | `session.completed` | ja     | **nein**                 | ja              |
 * | `task.assigned`     | ja     | ja                       | **ja**          |
 * | `task.created`      | ja     | ja                       | **nein**        |
 * | `task.updated`      | ja     | ja                       | **nein**        |
 * | `task.deleted`      | nein   | —                        | nein            |
 *
 * Zwei von sechs kommen also heute wirklich an. Die Gruende sind je Zeile
 * verschieden: bei `session.completed` haengt der versendende Zuhoerer nicht am
 * Ereignis, bei `task.created` und `task.updated` haengt er, aber die
 * Ereignisklasse wird nirgends erzeugt (beim Anlegen einer Aufgabe wirft
 * VocalFlow `TaskAssigned`), und `task.deleted` hat ueberhaupt keine Klasse.
 *
 * Das ist trotzdem kein Grund, die Auslöser wegzulassen. Der Name ist der
 * Vertrag: er steht in VocalFlows Abo-Liste, und wer die Luecke auf der
 * anderen Seite schliesst, soll den Auslöser hier vorfinden statt ihn dann
 * erst zu vermissen. Umgekehrt ist ein Auslöser, der nie feuert, hier nur ein
 * Eintrag im Editor und kostet nichts.
 *
 * ## Was fehlt, und warum es nicht heimlich nachgetragen wurde
 *
 * `session.updated` gibt es bei VocalFlow, es wird ausgeloest **und**
 * ausgeliefert, und es ist damit das einzige Ereignis, mit dem sich heute
 * "Session verlegt" oder "Session abgesagt" bauen liesse. Seine Nutzlast liegt
 * vollstaendig vor (`SessionUpdated::getModelData`), es waere also nichts zu
 * raten.
 *
 * Es steht hier trotzdem nicht, weil es nicht in der Liste stand, gegen die
 * dieser Anschluss beauftragt wurde. Ein Handle ist endgueltig, und einen
 * siebten aus eigenem Antrieb zu vergeben ist keine Kleinigkeit, die man
 * nebenbei mitnimmt. Dasselbe gilt fuer `session.deleted`, das es gibt, dessen
 * Ausloeser in VocalFlow aber ein leerer Rumpf ist.
 */
class VocalFlowEvents
{
    /**
     * VocalFlow `event` => Auslöser-Handle.
     *
     * @var array<string, string>
     */
    public const TRIGGERS = [
        'session.created' => 'vocalflow.session_created',
        'session.completed' => 'vocalflow.session_completed',
        'task.created' => 'vocalflow.task_created',
        'task.updated' => 'vocalflow.task_updated',
        'task.assigned' => 'vocalflow.task_assigned',
        'task.deleted' => 'vocalflow.task_deleted',
    ];

    /**
     * Die veroeffentlichte Session.
     *
     * Steht ausserhalb von {@see self::TRIGGERS}, weil sie nicht ueber
     * denselben Kanal kommt: eigener Endpunkt, kein `event`-Feld in der
     * Nutzlast, kein HMAC sondern ein Bearer-Token. Der Ereignisname existiert
     * bei VocalFlow gar nicht, er wird hier vergeben, damit der Umschlag
     * dieselbe Form hat wie bei den sechs anderen und ein Verzweigungsknoten
     * `vocalflow.event` einheitlich lesen kann.
     */
    public const SESSION_PUBLISHED_EVENT = 'session.published';

    public const SESSION_PUBLISHED_HANDLE = 'vocalflow.session_published';

    /**
     * Das Handle zu einem `event`, oder null fuer alles, wofuer dieses Addon
     * keinen Auslöser hat.
     */
    public static function handleFor(string $event): ?string
    {
        return self::TRIGGERS[$event] ?? null;
    }
}
