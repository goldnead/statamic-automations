<?php

namespace Goldnead\StatamicAutomations\Integrations\VocalFlow\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\VocalFlow\VocalFlowClient;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Schreibt einem Studenten in VocalFlow ein Paket gut.
 *
 * Der zweite Schritt im Onboarding, nach {@see CreateStudentAction}: das
 * Guthaben, mit dem der Student seine Stunden bucht. VocalFlow legt dazu einen
 * Kauf an, verknuepft ihn mit einer Sitzungsart und setzt das Ablaufdatum.
 *
 * ## Zwei Paketarten, die sich verschieden verhalten
 *
 * `one_time` ist ein Kontingent: `total_sessions` Stunden, die bis
 * `expires_months` Monate nach dem Kauf abgerufen werden koennen.
 * `subscription` ist ein Abonnement: es laeuft nicht ab, und was zaehlt, ist
 * `monthly_credits`, also wie viele Stunden pro Monat nachwachsen.
 *
 * Die Felder gelten deshalb nicht fuer beide gleich, und VocalFlow ignoriert
 * das jeweils andere still. Das ist der haeufigste Fehlgriff an diesem Knoten:
 * ein Abonnement mit `total_sessions` 10 und ohne `monthly_credits` wird
 * angelegt, sieht richtig aus und gibt dem Studenten null Stunden im Monat.
 *
 * ## Nicht von sich aus wiederholbar
 *
 * Anders als das Anlegen eines Studenten legt jeder Aufruf hier einen **neuen**
 * Kauf an. Ein Ablauf, der zweimal laeuft, schreibt zweimal gut.
 *
 * Dagegen gibt es `idempotency_key`, und er ist bewusst ein Feld und kein
 * automatisch gebildeter Wert. Ein aus der Nutzlast abgeleiteter Schluessel
 * waere fuer denselben Studenten mit demselben Paket immer derselbe — und
 * verschluckte damit den zweiten echten Kauf desselben Pakets, was ein voellig
 * normaler Vorgang ist. Der Wert, der hier hingehoert, ist der, der den
 * **Kaufvorgang** benennt: die Bestellnummer, die Zahlungs-Kennung, die
 * Rechnungsnummer. Der Ablauf weiss ihn, dieser Knoten nicht.
 *
 * Bleibt das Feld leer, wird kein Schluessel geschickt und jeder Lauf schreibt
 * gut. Das ist die richtige Vorgabe: still einen Schluessel zu erfinden hiesse,
 * gelegentlich einen bezahlten Kauf zu verschlucken, und das faellt niemandem
 * auf ausser dem Kunden.
 */
class GrantPackageAction implements AutomationAction
{
    public function __construct(protected VocalFlowClient $client) {}

    public static function handle(): string
    {
        return 'vocalflow.grant_package';
    }

    public static function label(): string
    {
        return 'Grant Package (VocalFlow)';
    }

    public static function description(): ?string
    {
        return 'Credits a session package to a VocalFlow student.';
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
                'help' => 'The student to credit. Usually {{ student.email }} or {{ node.email }} from the Create Student node above.',
            ],
            [
                'handle' => 'session_type_id',
                'label' => 'Session type (ID)',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'The VocalFlow session type UUID the package is good for. Read it off the session type in VocalFlow; it does not change when the session type is renamed.',
            ],
            [
                'handle' => 'package_type',
                'label' => 'Package type',
                'type' => 'select',
                'options' => ['one_time', 'subscription'],
                'default' => 'one_time',
                'required' => true,
                'help' => 'one_time is a quota that expires. subscription renews monthly and never expires.',
            ],
            [
                'handle' => 'total_sessions',
                'label' => 'Sessions',
                'type' => 'number',
                'default' => 1,
                'required' => true,
                'help' => 'How many sessions the package is worth. For a subscription this is the headline figure only; what the student actually gets each month is Monthly credits.',
            ],
            [
                'handle' => 'monthly_credits',
                'label' => 'Monthly credits',
                'type' => 'number',
                'required' => false,
                'help' => 'Subscriptions only: how many sessions come back each month. Ignored for a one_time package.',
            ],
            [
                'handle' => 'expires_months',
                'label' => 'Expires after (months)',
                'type' => 'number',
                'required' => false,
                'help' => 'One-time packages only: how long the sessions stay usable. VocalFlow defaults to 12. Ignored for a subscription, which does not expire.',
            ],
            [
                'handle' => 'idempotency_key',
                'label' => 'Idempotency key',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Optional. Name the purchase, not the package: an order number or payment ID, for example {{ payment.id }}. With it, a re-run credits nothing a second time. Without it, every run credits again.',
            ],
        ];
    }

    /**
     * Was dieser Knoten nach unten weitergibt.
     *
     * `created` ist die interessante Auskunft: `false` heisst, dass VocalFlow
     * den Kauf unter demselben `idempotency_key` schon kannte und nichts
     * Zweites angelegt hat.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'id' => 'string',
            'created' => 'boolean',
            'email' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $email = $config['email'] ?? $context->get('student.email');

        $sessionTypeId = $config['session_type_id'] ?? null;
        $packageType = $config['package_type'] ?? 'one_time';
        $totalSessions = $config['total_sessions'] ?? null;

        // Statische Konfiguration, alle drei vor dem Testmodus-Zweig: ein
        // Knoten ohne Sitzungsart oder mit einer unsinnigen Paketart ist falsch
        // eingerichtet, und ein Testlauf hat das zu sagen.
        //
        // Was hier ausdruecklich **nicht** geprueft wird, ist die Form der
        // Sitzungsart-Kennung. Sie ist bei VocalFlow eine UUID, aber das ist
        // deren Regel und nicht unsere: eine Vorpruefung darauf wuerde eines
        // Tages eine gueltige Kennung ablehnen, weil VocalFlow das Format
        // geaendert hat. VocalFlow prueft es selbst und antwortet mit 422, und
        // diese Antwort kommt lesbar im Protokoll an.
        if (! is_string($sessionTypeId) || trim($sessionTypeId) === '') {
            return ActionResult::failed('A session type ID is required.');
        }

        if (! in_array($packageType, ['one_time', 'subscription'], true)) {
            return ActionResult::failed('The package type must be one_time or subscription.');
        }

        if (! is_numeric($totalSessions) || (int) $totalSessions < 0) {
            return ActionResult::failed('The number of sessions is required and cannot be negative.');
        }

        $payload = $this->payloadFor($sessionTypeId, $packageType, (int) $totalSessions, $config);
        $key = $config['idempotency_key'] ?? null;
        $key = is_string($key) && trim($key) !== '' ? trim($key) : null;

        // Die Datenreferenz wird absichtlich erst nach diesem Zweig geprueft:
        // siehe ActionResult::missingDataReference().
        if ($context->isTestMode() && ! config('automations.test_mode.persist_vocalflow_changes', false)) {
            return ActionResult::success([
                'preview' => ['email' => $email, 'idempotency_key' => $key] + $payload,
                'note' => 'Test mode — no package credited in VocalFlow.',
            ]);
        }

        if (! is_string($email) || trim($email) === '') {
            return ActionResult::missingDataReference('email', 'Email', '{{ student.email }}');
        }

        $email = strtolower(trim($email));

        if (! $this->client->isConfigured()) {
            return ActionResult::failed('VocalFlow is not configured: set the partner URL and the partner secret before using this action.');
        }

        $result = $this->client->grantPackage($email, $payload, $key);

        if (! $result->ok) {
            return ActionResult::failed($result->error ?? 'Crediting the VocalFlow package failed.');
        }

        return ActionResult::success([
            'id' => $result->data['id'] ?? null,
            'created' => (bool) ($result->data['created'] ?? false),
            'email' => $email,
        ]);
    }

    /**
     * Die Nutzlast fuer VocalFlow, mit den Feldern, die zur Paketart gehoeren.
     *
     * Die beiden Zusatzfelder werden nur mitgeschickt, wenn sie fuer diese
     * Paketart etwas bedeuten. Sie immer mitzuschicken waere harmlos, weil
     * VocalFlow das jeweils andere ignoriert — aber dann stuende im
     * Ablaufprotokoll bei jedem Abonnement ein `expires_months`, das nie
     * gewirkt hat, und der naechste, der einen Fehler sucht, glaubt es.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function payloadFor(string $sessionTypeId, string $packageType, int $totalSessions, array $config): array
    {
        $payload = [
            'session_type_id' => trim($sessionTypeId),
            'total_sessions' => $totalSessions,
            'package_type' => $packageType,
        ];

        if ($packageType === 'subscription') {
            $credits = $config['monthly_credits'] ?? null;

            if (is_numeric($credits) && (int) $credits >= 0) {
                $payload['monthly_credits'] = (int) $credits;
            }

            return $payload;
        }

        $months = $config['expires_months'] ?? null;

        // VocalFlow verlangt mindestens 1 und setzt ohne Angabe selbst 12.
        // Eine 0 hier durchzureichen hiesse, eine 422 zu provozieren fuer
        // etwas, das der Betrieb offensichtlich als "keine Angabe" gemeint hat.
        if (is_numeric($months) && (int) $months >= 1) {
            $payload['expires_months'] = (int) $months;
        }

        return $payload;
    }
}
