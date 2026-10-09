<?php

namespace Goldnead\StatamicAutomations\Integrations\Entitlements\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\EntitlementsAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Gate in a flow: does this address hold a product *now*, and if the answer is
 * not the one the flow was built on, end the run.
 *
 * Only registered when the entitlements addon is detected.
 *
 * ## Why a node and not a condition
 *
 * A condition reads the trigger context, which is the state at the moment the
 * run started. A flow that waits ten days and then mails "you do not have the
 * plan yet" needs the state ten days later. This node asks the addon at the
 * moment it runs.
 *
 * ## Two modes
 *
 *  - `require`: continue only if the person holds the product.
 *  - `forbid`: continue only if the person does not.
 *
 * When the condition does not hold the run is **stopped**, not failed. The two
 * are different on purpose: a stop says "the condition did not hold" and is an
 * ordinary outcome; a failure says "the check could not be made" and is
 * something to look at. A flow log that cannot tell them apart leaves a missing
 * mail without an explanation.
 *
 * ## It never passes by default
 *
 * If the addon is absent, the address is unreadable, or the addon throws, the
 * node **fails**. A `forbid` that passed because the check broke would send a
 * mail to somebody who must not get it.
 *
 * ## What counts as access
 *
 * The addon's definition, through its own `decide()`: active or in its grace
 * period. A revoked, expired, scheduled or pending grant is not access. An
 * address is looked up as the Statamic user who owns it and as a subject of
 * type `email`, so a buyer who never created a login is found too.
 *
 * ## Test mode
 *
 * Reads for real. Nothing is written, so a preview that tells the truth costs
 * nothing, and a preview that always passed would hide the stop.
 */
class CheckAccessAction implements AutomationAction
{
    public const MODE_REQUIRE = 'require';

    public const MODE_FORBID = 'forbid';

    public function __construct(protected EntitlementsAdapter $adapter) {}

    public static function handle(): string
    {
        return 'entitlements.check_access';
    }

    public static function label(): string
    {
        return 'Check Access';
    }

    public static function description(): ?string
    {
        return 'Looks up now whether an address holds a product and ends the run when the answer is not the one required.';
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
                'handle' => 'email',
                'label' => 'Email',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'The address to look up, for example {{ payment.email }}. Found as the account that owns it and as a subject of type email.',
            ],
            [
                'handle' => 'product_slug',
                'label' => 'Product',
                'type' => 'text',
                'required' => true,
                'help' => 'The product slug whose access is checked.',
            ],
            [
                'handle' => 'mode',
                'label' => 'Continue only if the person',
                'type' => 'select',
                'required' => true,
                'default' => self::MODE_REQUIRE,
                'options' => [
                    self::MODE_REQUIRE => 'has the access',
                    self::MODE_FORBID => 'does not have the access',
                ],
                'help' => 'When the condition does not hold, the run ends here. That is a stop, not a failure.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'has_access' => 'boolean',
            'state' => 'string',
            'product_slug' => 'string',
            'mode' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $email = mb_strtolower(trim((string) ($config['email'] ?? '')));
        $productSlug = trim((string) ($config['product_slug'] ?? ''));
        $mode = trim((string) ($config['mode'] ?? self::MODE_REQUIRE)) ?: self::MODE_REQUIRE;

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ActionResult::failed('A valid "email" is required.');
        }

        if ($productSlug === '') {
            return ActionResult::failed('Product is required.');
        }

        if (! in_array($mode, [self::MODE_REQUIRE, self::MODE_FORBID], true)) {
            return ActionResult::failed(sprintf('Unknown mode "%s": use "require" or "forbid".', $mode));
        }

        $result = $this->adapter->hasAccess($email, $productSlug);

        if (! ($result['ok'] ?? false)) {
            return ActionResult::failed($result['error'] ?? 'Checking access failed.', [
                'product_slug' => $productSlug,
                'mode' => $mode,
            ]);
        }

        $hasAccess = (bool) ($result['has_access'] ?? false);
        $output = [
            'has_access' => $hasAccess,
            'state' => $result['state'] ?? null,
            'product_slug' => $productSlug,
            'mode' => $mode,
        ];

        if ($mode === self::MODE_REQUIRE && ! $hasAccess) {
            return ActionResult::stopped(sprintf(
                '%s does not hold "%s"%s, so the run ends here.',
                $email,
                $productSlug,
                $output['state'] ? ' (grant is '.$output['state'].')' : '',
            ));
        }

        if ($mode === self::MODE_FORBID && $hasAccess) {
            return ActionResult::stopped(sprintf(
                '%s already holds "%s", so the run ends here.',
                $email,
                $productSlug,
            ));
        }

        return ActionResult::success($output);
    }
}
