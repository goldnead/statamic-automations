<?php

namespace Goldnead\StatamicAutomations\Integrations\Marketing;

/**
 * Thin adapter that delegates to goldnead/statamic-marketing's services when
 * that addon is installed. All references are resolved lazily by class name
 * so this file is safe to load without the package present.
 */
class MarketingAdapter
{
    public static function available(): bool
    {
        return class_exists('Goldnead\\Marketing\\Services\\SubscriptionService');
    }

    /**
     * Die E-Mail-Adresse hinter einem Marketing-Token.
     *
     * Der Token ist der dauerhafte Schluessel einer Anmeldung — derselbe, den
     * der Abmelde-Link und die Selbstbedienungs-Seite tragen. Fuer den
     * Serien-Ausstieg ist er die einzige Kennung, die eine Mail mitbringen
     * kann: sie kennt keinen angemeldeten Benutzer, und eine E-Mail-Adresse
     * offen in der URL waere eine Einladung, fremde Leute auszutragen.
     *
     * `null`, wenn Marketing nicht installiert ist oder der Token zu nichts
     * gehoert. Der Aufrufer macht daraus ein 404 — nicht die Auskunft, ob es
     * den Token gibt.
     */
    public function emailForToken(string $token): ?string
    {
        if (! static::available() || trim($token) === '') {
            return null;
        }

        $model = 'Goldnead\\Marketing\\Models\\Subscription';

        if (! class_exists($model)) {
            return null;
        }

        $subscription = $model::query()->where('token', $token)->first();

        return $subscription?->email;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function listOptions(): array
    {
        if (! static::available()) {
            return [];
        }

        try {
            return app('Goldnead\\Marketing\\Contracts\\Repositories\\MailingListRepository')
                ->all()
                ->map(fn ($list) => ['value' => $list->handle, 'label' => $list->name])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Subscribe an email to a list (honours the list's double opt-in).
     *
     * @return array{uuid: string, status: string}|null null when the list is unknown or the addon is absent.
     */
    public function subscribe(string $listHandle, string $email, array $attributes = []): ?array
    {
        if (! static::available()) {
            return null;
        }

        $list = app('Goldnead\\Marketing\\Contracts\\Repositories\\MailingListRepository')->find($listHandle);

        if (! $list) {
            return null;
        }

        $subscription = app('Goldnead\\Marketing\\Services\\SubscriptionService')->subscribe(
            $list,
            $email,
            $attributes,
            ['source' => 'automation'],
        );

        return ['uuid' => $subscription->uuid, 'status' => $subscription->status];
    }

    /**
     * @return array{uuid: string, status: string}|null null when no matching subscription exists.
     */
    public function unsubscribe(string $listHandle, string $email): ?array
    {
        if (! static::available()) {
            return null;
        }

        $subscriptionClass = 'Goldnead\\Marketing\\Models\\Subscription';
        $normalizerClass = 'Goldnead\\Leadhub\\Support\\EmailNormalizer';

        $subscription = $subscriptionClass::forList($listHandle)
            ->where('email_normalized', $normalizerClass::normalize($email))
            ->first();

        if (! $subscription) {
            return null;
        }

        $subscription = app('Goldnead\\Marketing\\Services\\SubscriptionService')
            ->unsubscribe($subscription, ['reason' => 'automation']);

        return ['uuid' => $subscription->uuid, 'status' => $subscription->status];
    }

    /**
     * @return array{handle: string, status: string}|null
     */
    public function findCampaign(string $handle): ?array
    {
        if (! static::available()) {
            return null;
        }

        $campaign = app('Goldnead\\Marketing\\Contracts\\Repositories\\CampaignRepository')->find($handle);

        return $campaign ? ['handle' => $campaign->handle, 'status' => $campaign->status, 'sendable' => $campaign->isSendable()] : null;
    }

    /** Queue a campaign for immediate delivery. Throws on invalid campaigns. */
    public function queueCampaign(string $handle): bool
    {
        if (! static::available()) {
            return false;
        }

        $campaign = app('Goldnead\\Marketing\\Contracts\\Repositories\\CampaignRepository')->find($handle);

        if (! $campaign) {
            return false;
        }

        app('Goldnead\\Marketing\\Services\\CampaignSender')->queue($campaign);

        return true;
    }
}
