<?php

namespace Goldnead\StatamicAutomations\Integrations\Payments\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Concerns\FlattensPayments;

/**
 * A checkout was refused before it started: block list, rate limit or
 * captcha. Nothing was written and no provider was called.
 *
 * This is a trigger for an alert to a person, not for a customer mail. The
 * address in it is whatever the visitor typed, and a block list exists
 * precisely because some of those are not people worth writing to.
 */
class CheckoutBlockedTrigger implements AutomationTrigger
{
    use FlattensPayments;

    public static function handle(): string
    {
        return 'payments.checkout_blocked';
    }

    public static function label(): string
    {
        return 'Checkout Blocked';
    }

    public static function description(): ?string
    {
        return 'Triggered when a checkout is refused by the block list, the rate limit or the captcha. For alerts to your team.';
    }

    public static function group(): string
    {
        return 'Payments';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'reason',
                'label' => 'Reason',
                'type' => 'select',
                'options' => [
                    ['value' => 'blocked_email', 'label' => 'Blocked address'],
                    ['value' => 'blocked_domain', 'label' => 'Blocked domain'],
                    ['value' => 'blocked_ip', 'label' => 'Blocked IP address'],
                    ['value' => 'rate_limited', 'label' => 'Too many attempts'],
                    ['value' => 'captcha', 'label' => 'Captcha failed'],
                ],
                'required' => false,
                'help' => 'Leave empty for every reason.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'blocked' => [
                'reason' => 'string',
                'email' => 'string',
                'ip' => 'string',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $reason = $this->filterValue($config, 'reason');

        return $reason === null || $this->stringOf($this->propertyOf($event, 'reason')) === $reason;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'blocked' => [
                'reason' => $this->stringOf($this->propertyOf($event, 'reason')),
                'email' => $this->stringOf($this->propertyOf($event, 'email')),
                'ip' => $this->stringOf($this->propertyOf($event, 'ip')),
            ],
        ]);
    }
}
