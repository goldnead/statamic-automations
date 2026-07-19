<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Support\ExtractsStatamicAsset;

class AssetUploadedTrigger implements AutomationTrigger
{
    use ExtractsStatamicAsset;

    public static function handle(): string
    {
        return 'asset_uploaded';
    }

    public static function label(): string
    {
        return 'Asset Uploaded';
    }

    public static function description(): ?string
    {
        return 'Triggered when a new file is uploaded to an asset container.';
    }

    public static function group(): string
    {
        return 'Statamic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'container',
                'label' => 'Asset Container',
                'type' => 'select',
                'options_source' => 'statamic.asset_containers',
                'required' => false,
                'help' => 'Leave empty to match any container.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'asset' => [
                'id' => 'string', 'filename' => 'string', 'basename' => 'string',
                'container' => 'string', 'url' => 'string', 'data' => 'array',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        return $this->assetMatchesScope($this->extractAsset($event), $config);
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make(['asset' => $this->extractAsset($event)]);
    }
}
