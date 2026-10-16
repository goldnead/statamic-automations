<?php

namespace Goldnead\StatamicAutomations\Registries;

use InvalidArgumentException;

/**
 * Starting points for a connection: a service's base URL, auth setup and
 * operations as a plain PHP array. Picking one in the CP fills the form and,
 * on save, creates the operations along with the connection. After that they
 * are ordinary rows; nothing stays bound to the template.
 *
 * A template is `['name' => …, 'description' => …, 'connection' => […],
 * 'operations' => [[…], …]]`. `connection` holds the form values to prefill
 * (handle, name, base_url, auth_type, auth_config, test_path, timeout);
 * each operation holds the fields of an operation row.
 */
class ConnectionTemplateRegistry
{
    /** @var array<string, array<string, mixed>> */
    protected array $templates = [];

    /** @param  array<string, mixed>  $template */
    public function register(string $handle, array $template): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $handle)) {
            throw new InvalidArgumentException("Connection template handle '{$handle}' must be snake_case.");
        }

        foreach (['name', 'connection', 'operations'] as $key) {
            if (! array_key_exists($key, $template)) {
                throw new InvalidArgumentException("Connection template '{$handle}' needs a '{$key}'.");
            }
        }

        if (! is_array($template['connection']) || ! is_array($template['operations'])) {
            throw new InvalidArgumentException("Connection template '{$handle}': 'connection' and 'operations' must be arrays.");
        }

        $this->templates[$handle] = $template;
    }

    public function has(string $handle): bool
    {
        return isset($this->templates[$handle]);
    }

    /** @return array<string, mixed>|null */
    public function get(string $handle): ?array
    {
        return $this->templates[$handle] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->templates;
    }

    public function flush(): void
    {
        $this->templates = [];
    }
}
