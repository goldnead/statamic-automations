<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\Concerns\FlattensVocalFlowEvents;

/**
 * Eine Aufgabe wurde geloescht.
 *
 * Gedacht fuer das Aufraeumen dahinter: die Erinnerung abbestellen, den Eintrag
 * im CRM schliessen, die Nachfassmail nicht mehr schicken.
 *
 * ## Was hier ungeprueft ist, und warum es trotzdem hier steht
 *
 * `task.deleted` steht in VocalFlows Abo-Liste, aber es gibt dort **keine
 * Ereignisklasse dazu** und niemanden, der es verschickt. Es gibt also auch
 * keine echte Nutzlast, gegen die dieser Auslöser geprueft werden koennte.
 *
 * Angenommen wird die Form der uebrigen Aufgaben-Ereignisse, weil VocalFlow
 * sie fuer alle drei anderen gleich baut und die geloeschte Aufgabe kaum eine
 * andere haette. Das ist eine begruendete Annahme und kein Wissen, und deshalb
 * steht es hier und nicht im Kleingedruckten.
 *
 * Kommt das Ereignis eines Tages in einer anderen Form, faellt das nicht still
 * aus: die Felder unter `task` waeren leer, die Rohnutzlast steht aber
 * vollstaendig unter `vocalflow.data`, und der Auslöser feuert, weil er nur auf
 * den Namen prueft. Nachzuziehen ist dann der Flattener, nicht der Anschluss.
 */
class TaskDeletedTrigger implements AutomationTrigger
{
    use FlattensVocalFlowEvents;

    public static function handle(): string
    {
        return 'vocalflow.task_deleted';
    }

    public static function label(): string
    {
        return 'Task Deleted (VocalFlow)';
    }

    public static function description(): ?string
    {
        return 'Triggered when a practice task is deleted in VocalFlow.';
    }

    public static function group(): string
    {
        return 'VocalFlow';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return self::taskFilterSchema();
    }

    public static function outputSchema(): array
    {
        return self::taskOutputSchema();
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->isEvent($event, 'task.deleted')
            && $this->matchesTaskFilters($event, $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'task' => $this->taskOf($event),
            'student' => $this->studentOf($event, 'task'),
            'coach' => $this->coachOf($event, 'task'),
            'session' => $this->taskSessionOf($event),
            'vocalflow' => $this->envelopeOf($event),
        ]);
    }
}
