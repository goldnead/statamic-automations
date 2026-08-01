<?php

namespace Goldnead\StatamicAutomations\Licensing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Addon;

/**
 * Lightweight license manager for the Pro tier.
 *
 * Two operating modes:
 *   - "config": the addon trusts a list of valid keys configured locally
 *     (good for self-hosted forks and development).
 *   - "remote": the addon checks the configured key against a remote
 *     verification endpoint and caches the result for `cache_ttl` minutes.
 *
 * Licensing resolves in this order:
 *   1. Statamic Marketplace edition — when the addon is licensed through the
 *      Statamic Marketplace, the active edition is read natively via
 *      Addon::edition(). The "pro" edition unlocks Pro features. This is the
 *      supported path for marketplace customers — Statamic + the licensing
 *      utility handle validation, no addon config required.
 *   2. Local "config" mode — trusts a list of valid keys configured locally
 *      (good for self-hosted forks and development).
 *   3. Remote mode — checks the key against a verification endpoint, cached
 *      for `cache_ttl` minutes.
 */
class LicenseManager
{
    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_NETWORK_ERROR = 'network_error';

    public const STATUS_NO_KEY = 'no_key';

    /**
     * This addon's Composer package name, used to read its Marketplace edition.
     */
    public const PACKAGE = 'goldnead/statamic-automations';

    /**
     * Quick yes/no check used at code-gating sites.
     */
    public function isPro(): bool
    {
        // Prefer Statamic's native Marketplace edition when available.
        $edition = $this->marketplaceEdition();
        if ($edition !== null) {
            return $edition === 'pro';
        }

        return $this->status()['status'] === self::STATUS_VALID;
    }

    /**
     * The addon's active Statamic Marketplace edition (e.g. "free" | "pro"),
     * or null when the addon isn't registered (e.g. in isolated unit tests).
     */
    public function marketplaceEdition(): ?string
    {
        try {
            $addon = Addon::get(self::PACKAGE);

            return $addon?->edition();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{status: string, mode: string, expires_at?: ?string, features?: array<int,string>, message?: ?string}
     */
    public function status(): array
    {
        // When licensed through the Marketplace, surface the edition directly.
        $edition = $this->marketplaceEdition();
        if ($edition !== null) {
            return [
                'status' => $edition === 'pro' ? self::STATUS_VALID : self::STATUS_NO_KEY,
                'mode' => 'marketplace',
                'features' => $edition === 'pro'
                    ? (array) config('automations.license.features', ['custom_actions', 'custom_triggers'])
                    : [],
                'message' => 'Edition: '.$edition,
            ];
        }

        $key = (string) config('automations.license.key', '');
        $mode = (string) config('automations.license.mode', 'config');

        if ($key === '') {
            return [
                'status' => self::STATUS_NO_KEY,
                'mode' => $mode,
                'message' => 'No license key configured.',
            ];
        }

        return $mode === 'remote'
            ? $this->verifyRemote($key)
            : $this->verifyConfig($key);
    }

    /**
     * Returns the list of feature handles unlocked for the current license,
     * e.g. ['custom_actions', 'custom_triggers'].
     *
     * @return array<int,string>
     */
    public function features(): array
    {
        $status = $this->status();

        return $status['features'] ?? [];
    }

    public function gates(string $feature): bool
    {
        // Features that don't require Pro are always allowed.
        $proFeatures = [
            'custom_actions' => 'custom_actions_requires_pro',
        ];

        $configKey = $proFeatures[$feature] ?? null;
        if ($configKey === null) {
            return true;
        }

        if (! (bool) config("automations.features.{$configKey}", false)) {
            return true; // gating is disabled for this feature
        }

        return $this->isPro();
    }

    /**
     * Clear the cached remote-verification result.
     */
    public function flush(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * @return array{status: string, mode: string, expires_at?: ?string, features?: array<int,string>, message?: ?string}
     */
    protected function verifyConfig(string $key): array
    {
        $allowed = (array) config('automations.license.allowed_keys', []);
        $features = (array) config('automations.license.features', ['custom_actions', 'custom_triggers']);

        if (in_array($key, $allowed, true)) {
            return [
                'status' => self::STATUS_VALID,
                'mode' => 'config',
                'expires_at' => null,
                'features' => array_values($features),
            ];
        }

        return [
            'status' => self::STATUS_INVALID,
            'mode' => 'config',
            'message' => 'License key is not in the configured allowed list.',
        ];
    }

    /**
     * @return array{status: string, mode: string, expires_at?: ?string, features?: array<int,string>, message?: ?string}
     */
    protected function verifyRemote(string $key): array
    {
        $endpoint = (string) config('automations.license.endpoint', '');
        if ($endpoint === '') {
            return [
                'status' => self::STATUS_NETWORK_ERROR,
                'mode' => 'remote',
                'message' => 'No license verification endpoint configured.',
            ];
        }

        $ttl = (int) config('automations.license.cache_ttl_minutes', 360);

        return Cache::remember(
            $this->cacheKey(),
            now()->addMinutes(max(1, $ttl)),
            function () use ($endpoint, $key) {
                try {
                    $response = Http::timeout(5)
                        ->acceptJson()
                        ->post($endpoint, ['key' => $key]);

                    if (! $response->successful()) {
                        return [
                            'status' => self::STATUS_NETWORK_ERROR,
                            'mode' => 'remote',
                            'message' => "License endpoint returned {$response->status()}.",
                        ];
                    }

                    $data = $response->json();
                    $valid = (bool) ($data['valid'] ?? false);
                    $expired = (bool) ($data['expired'] ?? false);

                    if ($expired) {
                        return [
                            'status' => self::STATUS_EXPIRED,
                            'mode' => 'remote',
                            'expires_at' => $data['expires_at'] ?? null,
                            'message' => 'License has expired.',
                        ];
                    }

                    return [
                        'status' => $valid ? self::STATUS_VALID : self::STATUS_INVALID,
                        'mode' => 'remote',
                        'expires_at' => $data['expires_at'] ?? null,
                        'features' => (array) ($data['features'] ?? ['custom_actions', 'custom_triggers']),
                        'message' => $data['message'] ?? null,
                    ];
                } catch (\Throwable $e) {
                    return [
                        'status' => self::STATUS_NETWORK_ERROR,
                        'mode' => 'remote',
                        'message' => $e->getMessage(),
                    ];
                }
            },
        );
    }

    protected function cacheKey(): string
    {
        return 'statamic-automations.license.'.md5((string) config('automations.license.key', ''));
    }
}
