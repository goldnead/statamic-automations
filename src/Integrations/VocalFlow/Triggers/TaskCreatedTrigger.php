<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\Concerns\FlattensVocalFlowEvents;

/**
 * Eine Aufgabe wurde angelegt.
 *
 * **`student.uuid` ist hier leer.** VocalFlow legt `student_id` nur bei
 * {@see TaskAssignedTrigger} in das `task`-Objekt; bei diesem Ereignis fehlt es
 * ganz. `student.email` und `student.name` sind gefuellt, weil sie aus dem
 * eigenen `student`-Objekt kommen. Wer eine Person ansprechen will, nimmt die
 * Adresse — die Partner-API von VocalFlow tut das ebenfalls.
 *
 * **Hinweis zum Betrieb:** VocalFlow dispatcht heute beim Anlegen einer Aufgabe
 * `task.assigned` und nicht dieses Ereignis. Dass hier nichts ankommt, ist dann
 * eine Luecke auf der anderen Seite und kein Fehler dieses Auslösers. Wer
 * heute auf "es gibt eine neue Aufgabe" reagieren will, nimmt
 * {@see TaskAssignedTrigger}.
 */
class TaskCreatedTrigger implements AutomationTrigger
{
    use FlattensVocalFlowEvents;

    public static function handle(): string
    {
        return 'vocalflow.task_created';
    }

    public static function label(): string
    {
        return 'Task Created (VocalFlow)';
    }

    public static function description(): ?string
    {
        return 'Triggered when a practice task is created in VocalFlow.';
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
        return $this->isEvent($event, 'task.created')
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
