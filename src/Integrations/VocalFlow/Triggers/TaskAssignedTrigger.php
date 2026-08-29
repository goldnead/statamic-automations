<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\Concerns\FlattensVocalFlowEvents;

/**
 * Eine Aufgabe wurde einem Studenten zugewiesen.
 *
 * Das reichhaltigste der Aufgaben-Ereignisse: nur hier legt VocalFlow
 * `task.type`, `task.student_id` und `task.estimated_duration_minutes` in die
 * Nutzlast. `student.uuid` traegt deshalb **hier**, und bei
 * {@see TaskCreatedTrigger} nicht.
 *
 * Nicht zu verwechseln mit {@see TaskCreatedTrigger}: zugewiesen wird eine
 * Aufgabe an eine Person, angelegt wird sie ueberhaupt erst. VocalFlow feuert
 * beide, und wer beide gleich behandelt, schickt zwei Mails fuer eine Aufgabe.
 */
class TaskAssignedTrigger implements AutomationTrigger
{
    use FlattensVocalFlowEvents;

    public static function handle(): string
    {
        return 'vocalflow.task_assigned';
    }

    public static function label(): string
    {
        return 'Task Assigned (VocalFlow)';
    }

    public static function description(): ?string
    {
        return 'Triggered when a practice task is assigned to a student in VocalFlow.';
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
        return $this->isEvent($event, 'task.assigned')
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
