<?php

namespace Goldnead\StatamicAutomations\Integrations\Entitlements\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\EntitlementsAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Takes away somebody's access to a product.
 *
 * Only registered when the entitlements addon is detected.
 *
 * Withdraws every grant this subject holds for this product, not the first one
 * found. Several grants for the same person and product are legitimate — the
 * same course won by opt-in and later bought — and revoking one of two would
 * report success while leaving access in place.
 *
 * ## Running twice
 *
 * Safe. Revoking an already revoked grant is a no-op held by a conditional
 * update in the addon, and it is not counted: `revoked` is the number of grants
 * this run actually changed, so a second run reports 0 and a notification
 * behind it stays quiet.
 *
 * ## Revoking nothing is not an error
 *
 * `matched: 0` means this subject holds no grant for this product at all. That
 * is a normal outcome, somebody cancelling something they never had, and the
 * action succeeds. A flow that needs to react to it branches on the count.
 *
 * `matched` counts every grant for the pair, including ones that were already
 * revoked or have expired. It is "how many rows exist", not "how many were
 * open". `revoked` is the one that says what this run changed.
 *
 * The reason is required here because it is required at the addon's API, and
 * for the reason the addon gives: a revocation nobody can explain six months
 * later is a revocation somebody undoes.
 */
class RevokeEntitlementAction implements AutomationAction
{
    public function __construct(protected EntitlementsAdapter $adapter) {}

    public static function handle(): string
    {
        return 'entitlements.revoke';
    }

    public static function label(): string
    {
        return 'Revoke Access';
    }

    public static function description(): ?string
    {
        return 'Withdraws every grant a subject holds for a product, with a reason that is kept on the record.';
    }

    public static function group(): string
    {
        return 'Entitlements';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'subject_type',
                'label' => 'Subject type',
                'type' => 'text',
                'required' => true,
                'default' => 'user',
                'help' => 'The morph alias of whoever loses access, for example user or contact. It has to match the one the grant was written with.',
            ],
            [
                'handle' => 'subject_id',
                'label' => 'Subject',
                'type' => 'data_reference',
                'source' => 'user',
                'required' => true,
                'help' => 'The id of whoever loses access, for example {{ user.id }}. It has to be the same pair the grant was written with.',
            ],
            [
                'handle' => 'product_slug',
                'label' => 'Product',
                'type' => 'text',
                'required' => true,
                'help' => 'The product slug access is withdrawn for.',
            ],
            [
                'handle' => 'reason',
                'label' => 'Reason',
                'type' => 'text',
                'required' => true,
                'help' => 'Why access is being withdrawn. Kept on the grant and shown wherever it is read back.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'revoked' => 'integer',
            'matched' => 'integer',
            'product_slug' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $subjectType = trim((string) ($config['subject_type'] ?? ''));
        $productSlug = trim((string) ($config['product_slug'] ?? ''));
        $reason = trim((string) ($config['reason'] ?? ''));
        $subjectId = trim((string) ($config['subject_id'] ?? $context->get('user.id') ?? ''));

        if ($subjectType === '') {
            return ActionResult::failed('Subject type is required.');
        }

        if ($productSlug === '') {
            return ActionResult::failed('Product is required.');
        }

        if ($reason === '') {
            return ActionResult::failed('A reason is required to revoke access.');
        }

        if ($context->isTestMode() && ! config('automations.test_mode.persist_entitlement_changes', false)) {
            return ActionResult::success([
                'preview' => [
                    'subject' => $subjectType.':'.$subjectId,
                    'product_slug' => $productSlug,
                    'reason' => $reason,
                ],
                'note' => 'Test mode — no access was withdrawn.',
            ]);
        }

        if ($subjectId === '') {
            return ActionResult::missingDataReference('subject_id', 'Subject', '{{ user.id }}');
        }

        $result = $this->adapter->revoke($subjectType, $subjectId, $productSlug, $reason);

        if (! ($result['ok'] ?? false)) {
            return ActionResult::failed($result['error'] ?? 'Revoking access failed.', [
                'subject' => $subjectType.':'.$subjectId,
                'product_slug' => $productSlug,
                // How far it got before it broke off. Whoever cleans up needs
                // this first, and a bare "failed" does not carry it.
                'revoked' => $result['revoked'] ?? 0,
            ]);
        }

        return ActionResult::success([
            'revoked' => $result['revoked'] ?? 0,
            'matched' => $result['matched'] ?? 0,
            'product_slug' => $productSlug,
        ]);
    }
}
