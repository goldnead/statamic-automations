<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowClient;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Legt einen Studenten in VocalFlow an.
 *
 * Der erste der beiden Schritte im Onboarding: jemand hat gekauft, und bevor er
 * ein Paket gutgeschrieben bekommen kann, muss es ihn in VocalFlow geben.
 * Danach kommt {@see GrantPackageAction}.
 *
 * **Gefahrlos wiederholbar.** VocalFlow sucht selbst zuerst nach der Adresse
 * und legt nur an, wenn es keinen Studenten mit dieser gibt. Ein zweiter Lauf
 * desselben Ablaufs erzeugt also kein zweites Konto, sondern liefert dasselbe
 * zurueck. Ob angelegt oder gefunden wurde, steht in `{{ node.created }}`.
 *
 * VocalFlow ordnet einem neuen Studenten ausserdem einen Coach zu. Gibt es
 * keinen, wird der Student trotzdem angelegt und `{{ node.coach_assigned }}`
 * ist `false`. Das ist kein Fehler, aber der Grund, warum das Feld hier
 * durchgereicht wird: ein Student ohne Coach kann nichts buchen, und ein Ablauf
 * soll darauf verzweigen koennen, statt dass es erst auffaellt, wenn sich
 * jemand beschwert.
 */
class CreateStudentAction implements AutomationAction
{
    public function __construct(protected VocalFlowClient $client) {}

    public static function handle(): string
    {
        return 'vocalflow.create_student';
    }

    public static function label(): string
    {
        return 'Create Student (VocalFlow)';
    }

    public static function description(): ?string
    {
        return 'Creates a student in VocalFlow, or returns the existing one with the same email address.';
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
        return [
            [
                'handle' => 'email',
                'label' => 'Email',
                'type' => 'data_reference',
                'source' => 'student',
                'required' => true,
                'help' => 'The address VocalFlow keys the student on. Usually {{ student.email }} or {{ user.email }}.',
            ],
            [
                'handle' => 'name',
                'label' => 'Name',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'The display name for the new student. Ignored when a student with this address already exists.',
            ],
        ];
    }

    /**
     * Was dieser Knoten nach unten weitergibt.
     *
     * Spiegelt den Erfolgspfad von `execute()`. `id` und `uuid` sind zwei
     * verschiedene Kennungen desselben Kontos, und sie sind nicht
     * austauschbar: `id` ist die laufende Nummer, `uuid` der Wert, mit dem
     * VocalFlow intern verknuepft.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'id' => 'integer',
            'uuid' => 'string',
            'email' => 'string',
            'name' => 'string',
            'created' => 'boolean',
            'coach_assigned' => 'boolean',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $email = $config['email'] ?? $context->get('student.email');
        $name = $config['name'] ?? null;

        // Statische Konfiguration. Ein Knoten ohne Namen ist falsch
        // eingerichtet, und ein Testlauf hat das zu sagen — deshalb vor dem
        // Testmodus-Zweig.
        if (! is_string($name) || trim($name) === '') {
            return ActionResult::failed('A name is required.');
        }

        // Die Datenreferenz wird absichtlich erst nach diesem Zweig geprueft:
        // siehe ActionResult::missingDataReference().
        if ($context->isTestMode() && ! config('automations.test_mode.persist_vocalflow_changes', false)) {
            return ActionResult::success([
                'preview' => ['email' => $email, 'name' => trim($name)],
                'note' => 'Test mode — no student created in VocalFlow.',
            ]);
        }

        if (! is_string($email) || trim($email) === '') {
            return ActionResult::missingDataReference('email', 'Email', '{{ student.email }}');
        }

        // Kleingeschrieben, weil VocalFlow die Adresse ohne Ruecksicht auf
        // Gross- und Kleinschreibung sucht, sie aber so speichert, wie sie
        // ankommt. Wer sie einmal so und einmal so schickt, legt kein zweites
        // Konto an, bekommt aber zwei verschiedene Schreibweisen in zwei
        // Protokollen und sucht spaeter nach der falschen.
        $email = strtolower(trim($email));

        if (! $this->client->isConfigured()) {
            return ActionResult::failed('VocalFlow is not configured: set the partner URL and the partner secret before using this action.');
        }

        $result = $this->client->createStudent($email, trim($name));

        if (! $result->ok) {
            return ActionResult::failed($result->error ?? 'Creating the VocalFlow student failed.');
        }

        return ActionResult::success([
            'id' => $result->data['id'] ?? null,
            'uuid' => $result->data['uuid'] ?? null,
            'email' => $result->data['email'] ?? $email,
            'name' => $result->data['name'] ?? trim($name),
            'created' => (bool) ($result->data['created'] ?? false),
            'coach_assigned' => (bool) ($result->data['coach_assigned'] ?? false),
        ]);
    }
}
