<?php

namespace Goldnead\StatamicAutomations\Integrations\Marketing\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Marketing\MarketingAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;

class SendCampaignAction implements AutomationAction
{
    public function __construct(protected MarketingAdapter $adapter)
    {
    }

    public static function handle(): string
    {
        return 'marketing.send_campaign';
    }

    public static function label(): string
    {
        return 'Send Campaign';
    }

    public static function description(): ?string
    {
        return 'Queues a draft or scheduled marketing campaign for immediate delivery to its whole list.';
    }

    public static function group(): string
    {
        return 'Marketing';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'campaign',
                'label' => 'Campaign handle',
                'type' => 'text',
                'required' => true,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $handle = (string) ($config['campaign'] ?? '');

        if (! $handle) {
            return ActionResult::failed('marketing.send_campaign requires a "campaign" handle.');
        }

        $campaign = $this->adapter->findCampaign($handle);

        if ($campaign === null) {
            return ActionResult::failed("Campaign [{$handle}] does not exist (or statamic-marketing is not installed).");
        }

        if ($context->isTestMode()) {
            return ActionResult::success(['preview' => true, 'campaign' => $handle, 'status' => $campaign['status']]);
        }

        if (! $campaign['sendable']) {
            return ActionResult::skipped("Campaign [{$handle}] is not sendable (status: {$campaign['status']}).");
        }

        try {
            $this->adapter->queueCampaign($handle);
        } catch (\Throwable $e) {
            return ActionResult::failed($e->getMessage());
        }

        return ActionResult::success(['campaign' => $handle, 'status' => 'sending']);
    }
}
