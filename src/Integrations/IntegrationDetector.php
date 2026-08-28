<?php

namespace Goldnead\StatamicAutomations\Integrations;

/**
 * Detects whether sister addons (Webhook Manager, LeadHub) are
 * installed by checking for the presence of well-known facade or
 * service classes.
 *
 * The exact class names can be overridden via config so that future
 * package renames or alternative implementations can plug in without
 * touching the engine.
 */
class IntegrationDetector
{
    /**
     * @var array<string, bool>
     */
    protected static array $cache = [];

    public function hasWebhookManager(): bool
    {
        return $this->detect('webhook_manager', $this->webhookManagerClasses());
    }

    public function hasLeadHub(): bool
    {
        return $this->detect('leadhub', $this->leadHubClasses());
    }

    public function hasMarketing(): bool
    {
        return $this->detect('marketing', $this->marketingClasses());
    }

    public function hasFunnels(): bool
    {
        return $this->detect('funnels', $this->funnelsClasses());
    }

    public function hasPayments(): bool
    {
        return $this->detect('payments', $this->paymentsClasses());
    }

    public function hasEntitlements(): bool
    {
        return $this->detect('entitlements', $this->entitlementsClasses());
    }

    public function hasBooking(): bool
    {
        return $this->detect('booking', $this->bookingClasses());
    }

    public function hasInvoices(): bool
    {
        return $this->detect('invoices', $this->invoicesClasses());
    }

    /**
     * Reset the cache — primarily used by tests.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * Build a snapshot of detected integrations for the CP / API.
     *
     * @return array<string, bool>
     */
    public function snapshot(): array
    {
        return [
            'webhook_manager' => $this->hasWebhookManager(),
            'leadhub' => $this->hasLeadHub(),
            'marketing' => $this->hasMarketing(),
            'funnels' => $this->hasFunnels(),
            'payments' => $this->hasPayments(),
            'entitlements' => $this->hasEntitlements(),
            'booking' => $this->hasBooking(),
            'invoices' => $this->hasInvoices(),
        ];
    }

    /**
     * @param  array<int, string>  $candidates
     */
    protected function detect(string $key, array $candidates): bool
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        foreach ($candidates as $class) {
            if (class_exists($class) || interface_exists($class)) {
                return self::$cache[$key] = true;
            }
        }

        return self::$cache[$key] = false;
    }

    /**
     * @return array<int, string>
     */
    protected function webhookManagerClasses(): array
    {
        $configured = config('automations.integrations.webhook_manager.detect', []);

        return array_filter(array_merge((array) $configured, [
            'Goldnead\\WebhookManager\\Facades\\WebhookManager',
            'Goldnead\\WebhookManager\\WebhookManager',
        ]));
    }

    /**
     * @return array<int, string>
     */
    protected function leadHubClasses(): array
    {
        $configured = config('automations.integrations.leadhub.detect', []);

        return array_filter(array_merge((array) $configured, [
            // The addon's PSR-4 namespace is Goldnead\Leadhub (lowercase "hub").
            'Goldnead\\Leadhub\\Facades\\LeadHub',
            'Goldnead\\Leadhub\\LeadHubManager',
        ]));
    }

    /**
     * @return array<int, string>
     */
    protected function funnelsClasses(): array
    {
        $configured = config('automations.integrations.funnels.detect', []);

        return array_filter(array_merge((array) $configured, [
            'Goldnead\\StatamicFunnels\\Models\\Funnel',
            'Goldnead\\StatamicFunnels\\ServiceProvider',
        ]));
    }

    /**
     * @return array<int, string>
     */
    protected function paymentsClasses(): array
    {
        $configured = config('automations.integrations.payments.detect', []);

        return array_filter(array_merge((array) $configured, [
            'Goldnead\\StatamicPayments\\Models\\Payment',
            'Goldnead\\StatamicPayments\\ServiceProvider',
        ]));
    }

    /**
     * The entitlements addon's PSR-4 namespace is `Goldnead\\Entitlements`, with
     * no `Statamic` in it — unlike its package name, `statamic-entitlements`.
     * Probing the name that reads more naturally would silently never match.
     *
     * @return array<int, string>
     */
    protected function entitlementsClasses(): array
    {
        $configured = config('automations.integrations.entitlements.detect', []);

        return array_filter(array_merge((array) $configured, [
            'Goldnead\\Entitlements\\EntitlementManager',
            'Goldnead\\Entitlements\\ServiceProvider',
        ]));
    }

    /**
     * @return array<int, string>
     */
    protected function bookingClasses(): array
    {
        $configured = config('automations.integrations.booking.detect', []);

        return array_filter(array_merge((array) $configured, [
            'Goldnead\\StatamicBooking\\Models\\Booking',
            'Goldnead\\StatamicBooking\\ServiceProvider',
        ]));
    }

    /**
     * Same trap as entitlements: the namespace is `Goldnead\\Invoices`, not
     * `Goldnead\\StatamicInvoices`.
     *
     * @return array<int, string>
     */
    protected function invoicesClasses(): array
    {
        $configured = config('automations.integrations.invoices.detect', []);

        return array_filter(array_merge((array) $configured, [
            'Goldnead\\Invoices\\InvoiceWriter',
            'Goldnead\\Invoices\\ServiceProvider',
        ]));
    }

    /**
     * @return array<int, string>
     */
    protected function marketingClasses(): array
    {
        $configured = config('automations.integrations.marketing.detect', []);

        return array_filter(array_merge((array) $configured, [
            'Goldnead\\Marketing\\Services\\SubscriptionService',
            'Goldnead\\Marketing\\ServiceProvider',
        ]));
    }
}
