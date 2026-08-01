<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Adjust a LeadHub contact's lead score by a signed delta.
 *
 * Pairs with the `contact_score_changed` trigger: a flow can react to a score
 * change and, elsewhere, nudge a score itself (e.g. +10 when a high-intent
 * action happens). All LeadHub access goes through the guarded LeadHubAdapter
 * seam, so the node degrades to a clear "LeadHub not installed" result rather
 * than fataling when the sibling addon is absent.
 */
class ChangeScoreAction implements AutomationAction
{
    public function __construct(protected LeadHubAdapter $adapter) {}

    public static function handle(): string
    {
        return 'leadhub.change_score';
    }

    public static function label(): string
    {
        return 'Change Lead Score';
    }

    public static function description(): ?string
    {
        return 'Adjusts a LeadHub contact\'s lead score by a signed delta (e.g. +10 or -5).';
    }

    public static function group(): string
    {
        return 'LeadHub';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'contact_id',
                'label' => 'Contact',
                'type' => 'data_reference',
                'source' => 'contact',
                'required' => true,
                'tokenable' => true,
                'help' => 'A reference to the contact. Defaults to {{ contact_id }} from the triggering event when available.',
            ],
            [
                'handle' => 'delta',
                'label' => 'Score change',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'Signed integer: positive adds points, negative subtracts (e.g. 10 or -5). Supports tokens.',
            ],
            [
                'handle' => 'reason',
                'label' => 'Reason',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Optional note recorded with the score change.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'contact_id' => 'string',
            'delta' => 'integer',
            'new_score' => 'integer',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $contactRef = $config['contact_id'] ?? $context->get('contact_id') ?? $context->get('contact.id');
        $rawDelta = $config['delta'] ?? null;
        $reason = $config['reason'] ?? null;

        if (empty($contactRef)) {
            return ActionResult::failed('Contact reference is required.');
        }

        if ($rawDelta === null || $rawDelta === '' || ! is_numeric($rawDelta)) {
            return ActionResult::failed('A numeric score delta is required.');
        }

        $delta = (int) $rawDelta;

        if ($context->isTestMode() && ! config('automations.test_mode.persist_leadhub_changes', false)) {
            return ActionResult::success([
                'preview' => ['contact_id' => (string) $contactRef, 'delta' => $delta, 'reason' => $reason],
                'note' => 'Test mode — LeadHub score change skipped.',
            ]);
        }

        $result = $this->adapter->adjustScore((string) $contactRef, $delta, $reason !== null ? (string) $reason : null);

        if (! $result['ok']) {
            return ActionResult::failed($result['error'] ?? 'LeadHub change-score failed.');
        }

        $newScore = $result['result'] ?? null;

        return ActionResult::success([
            'contact_id' => (string) $contactRef,
            'delta' => $delta,
            'new_score' => is_numeric($newScore) ? (int) $newScore : $newScore,
        ]);
    }
}
