<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * A thin indirection for credentials used inside automations.
 *
 * Automations reference a secret by name — {{ secret.stripe_key }} — instead
 * of embedding the value in a node config (which would be persisted and shown
 * in the CP). The actual value is resolved here from the configured map,
 * which itself should pull from the environment, never from version control.
 *
 *     // config/automations.php
 *     'secrets' => [
 *         'stripe_key' => env('STRIPE_KEY'),
 *     ],
 */
class SecretStore
{
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->map());
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->map()[$name] ?? $default;
    }

    /**
     * Names only — never the values — for display in the CP.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->map());
    }

    /**
     * @return array<string, mixed>
     */
    protected function map(): array
    {
        return (array) config('automations.secrets', []);
    }
}
