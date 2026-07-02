<?php

namespace Goldnead\StatamicAutomations\Integrations\Marketing\Triggers;

class SubscriberUnsubscribedTrigger extends SubscriberConfirmedTrigger
{
    public static function handle(): string
    {
        return 'marketing.unsubscribed';
    }

    public static function label(): string
    {
        return 'Subscriber Unsubscribed';
    }

    public static function description(): ?string
    {
        return 'Triggered when someone unsubscribes from a marketing mailing list.';
    }
}
