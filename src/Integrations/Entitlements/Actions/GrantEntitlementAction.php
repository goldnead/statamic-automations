<?php

namespace Goldnead\StatamicAutomations\Integrations\Entitlements\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\EntitlementsAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Gives somebody access to a product.
 *
 * Only registered when the entitlements addon is detected.
 *
 * ## Running twice
 *
 * Safe, and safe by construction rather than by care. A grant is identified by
 * (subject, product, source, source reference), and the addon holds that tuple
 * with a unique index, so a second run with the same four values returns the
 * existing grant instead of writing another. Leaving "Source reference" empty
 * is therefore the safe setting: every run of this flow for this person and
 * product is the same grant.
 *
 * Filling it with something that changes per run, a payment id or a run id, is
 * how you deliberately ask for a second, separate grant. That is the right
 * thing for a repeat purchase and the wrong thing for a retry, and only the
 * person building the flow can tell those apart, which is why the field exists
 * and why its help text says so.
 *
 * ## What it does not do: reopen a grant that was closed
 *
 * The addon returns an existing grant untouched, and an existing grant may be
 * revoked or expired. A revoked one stays revoked on purpose, so that a
 * redelivered webhook cannot undo a refund. Running this action against one
 * therefore changes nothing at all.
 *
 * That is the failure that would otherwise be silent: the call succeeds, the
 * flow carries on, and the person has no access. So this node **fails** when
 * the grant it ends up with grants no access and will not start granting it on
 * its own. A grant whose start date is still ahead is not a failure and says so
 * through `provisional`.
 *
 * ## What to branch on
 *
 * `grants_access` for "does this person have it now". `created` for "did this
 * run write the row", which is a narrower question than it looks: confirming a
 * double opt-in turns an existing pending grant active without writing a row,
 * so `created` is false on the very run that opened access.
 *
 * For "send the welcome mail exactly once", neither is the right hook. Use the
 * `entitlements.granted` trigger, which the addon fires once per transition
 * however access came about, including that confirmation.
 */
class GrantEntitlementAction implements AutomationAction
{
    public function __construct(protected EntitlementsAdapter $adapter) {}

    public static function handle(): string
    {
        return 'entitlements.grant';
    }

    public static function label(): string
    {
        return 'Grant Access';
    }

    public static function description(): ?string
    {
        return 'Gives a subject access to a product. Running it twice with the same settings does not grant twice.';
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
                'help' => 'The morph alias of whoever gets access, for example user or contact. It is stored as written, so keep it stable.',
            ],
            [
                'handle' => 'subject_id',
                'label' => 'Subject',
                'type' => 'data_reference',
                'source' => 'user',
                'required' => true,
                'help' => 'The id of whoever gets access, for example {{ user.id }}. A grant can belong to somebody with no account, so for a subject type your site identifies by address, {{ payment.email }} is a legitimate id.',
            ],
            [
                'handle' => 'product_slug',
                'label' => 'Product',
                'type' => 'text',
                'required' => true,
                'help' => 'The product slug access is granted for.',
            ],
            [
                'handle' => 'source',
                'label' => 'Source',
                'type' => 'text',
                'required' => true,
                'default' => 'automation',
                'help' => 'Where this grant came from. Part of what makes a grant unique.',
            ],
            [
                'handle' => 'source_ref',
                'label' => 'Source reference',
                'type' => 'text',
                'required' => false,
                'help' => 'Leave empty unless you want repeat runs to create separate grants. Filling it with a value that changes per run, such as {{ payment.id }}, is how a repeat purchase becomes a second grant.',
            ],
            [
                'handle' => 'expires_at',
                'label' => 'Expires at',
                'type' => 'text',
                'required' => false,
                'help' => 'When access ends, as a date or a date expression. Leave empty for access that does not expire.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'created' => 'boolean',
            'grants_access' => 'boolean',
            'state' => 'string',
            'provisional' => 'boolean',
            'entitlement' => [
                'id' => 'string',
                'product_slug' => 'string',
                'source' => 'string',
                'source_ref' => 'string',
                'expires_at' => 'string',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $subjectType = trim((string) ($config['subject_type'] ?? ''));
        $productSlug = trim((string) ($config['product_slug'] ?? ''));
        $source = trim((string) ($config['source'] ?? '')) ?: 'automation';
        $sourceRef = $this->trimmedOrNull($config['source_ref'] ?? null);
        $expiresAt = $this->trimmedOrNull($config['expires_at'] ?? null);
        $subjectId = trim((string) ($config['subject_id'] ?? $context->get('user.id') ?? ''));

        // Static configuration, validated before the test-mode branch: a node
        // without a product or a subject type is a broken node, and a test run
        // exists to say so.
        if ($subjectType === '') {
            return ActionResult::failed('Subject type is required.');
        }

        if ($productSlug === '') {
            return ActionResult::failed('Product is required.');
        }

        if ($context->isTestMode() && ! config('automations.test_mode.persist_entitlement_changes', false)) {
            return ActionResult::success([
                'preview' => [
                    'subject' => $subjectType.':'.$subjectId,
                    'product_slug' => $productSlug,
                    'source' => $source,
                    'source_ref' => $sourceRef,
                    'expires_at' => $expiresAt,
                ],
                'note' => 'Test mode — no access was granted.',
            ]);
        }

        // The subject id is a data reference and is checked after the test-mode
        // branch on purpose: see ActionResult::missingDataReference().
        if ($subjectId === '') {
            return ActionResult::missingDataReference('subject_id', 'Subject', '{{ user.id }}');
        }

        $result = $this->adapter->grant($subjectType, $subjectId, $productSlug, $source, $sourceRef, $expiresAt);

        if (! ($result['ok'] ?? false)) {
            return ActionResult::failed($result['error'] ?? 'Granting access failed.', [
                'subject' => $subjectType.':'.$subjectId,
                'product_slug' => $productSlug,
            ]);
        }

        $output = [
            'created' => $result['created'] ?? false,
            'grants_access' => $result['grants_access'] ?? false,
            'state' => $result['state'] ?? null,
            'provisional' => $result['provisional'] ?? false,
            'entitlement' => $result['entitlement'] ?? [],
        ];

        // The write went through and the person still has nothing, and nothing
        // that happens on its own will change that. Reporting success here is
        // the quiet failure this node exists to avoid.
        if (! $output['grants_access'] && ! $output['provisional']) {
            return ActionResult::failed(
                sprintf(
                    'A grant for %s already exists and is %s, so this changed nothing. '
                    .'The addon does not reopen a closed grant, deliberately: restoring access after a '
                    .'revocation is its own decision. Use a new source reference for a separate grant.',
                    $productSlug,
                    $output['state'] ?? 'not granting access',
                ),
                $output,
            );
        }

        return ActionResult::success($output);
    }

    protected function trimmedOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
