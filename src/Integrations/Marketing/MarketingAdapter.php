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
     * Die Anmeldung hinter einem Marketing-Token: Adresse und Marke.
     *
     * Der Token ist der dauerhafte Schluessel einer Anmeldung — derselbe, den
     * der Abmelde-Link und die Selbstbedienungs-Seite tragen. Fuer den
     * Serien-Ausstieg ist er die einzige Kennung, die eine Mail mitbringen
     * kann: sie kennt keinen angemeldeten Benutzer, und eine E-Mail-Adresse
     * offen in der URL waere eine Einladung, Fremde auszutragen.
     *
     * **Ohne Marken-Scope gelesen, und die Marke kommt mit zurueck.** Der
     * Aufrufer steht unter der Marke der Automation, nicht unter der der
     * Anmeldung; mit Scope faende er den Token nie, sobald beide auseinander
     * liegen — und im CLI, wo gar keine Marke aktiv ist, ueberhaupt nie. Die
     * Pruefung, ob beide zusammengehoeren, gehoert dahin, wo beide bekannt
     * sind (SequenceOptOutController::resolve), nicht in eine Abfrage, die
     * dann einfach nichts findet und wie ein unbekannter Token aussieht.
     *
     * Ein Token adressiert genau eine Zeile ueber alle Marken hinweg — das ist
     * es, was das Lesen ohne Scope sicher macht und nicht zu einem Loch.
     *
     * @return array{email: string, brand_id: int}|null
     */
    public function subscriptionForToken(string $token): ?array
    {
        if (! static::available() || trim($token) === '') {
            return null;
        }

        $model = 'Goldnead\\Marketing\\Models\\Subscription';

        if (! class_exists($model)) {
            return null;
        }

        $subscription = $model::query()->withoutGlobalScopes()->where('token', $token)->first();

        if (! $subscription || ! $subscription->email) {
            return null;
        }

        return [
            'email' => (string) $subscription->email,
            'brand_id' => (int) ($subscription->brand_id ?? 0),
        ];
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
