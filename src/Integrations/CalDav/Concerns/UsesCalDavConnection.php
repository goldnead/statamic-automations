<?php

namespace Goldnead\StatamicAutomations\Integrations\CalDav\Concerns;

use Goldnead\StatamicAutomations\Integrations\CalDav\CalDavClient;
use Goldnead\StatamicAutomations\Models\AutomationConnection;

/**
 * The connection a CalDAV node names, in the current brand.
 *
 * No connection is the cal.com case "no API key": the node does nothing and
 * says so, rather than calling into the void.
 */
trait UsesCalDavConnection
{
    /** @return array<string, mixed> */
    protected static function connectionField(): array
    {
        return [
            'handle' => 'connection',
            'label' => 'Connection',
            'type' => 'select',
            'options_source' => 'connections',
            'required' => true,
            'help' => 'A connection under Automations → Connections whose base URL is the calendar collection and whose authentication is Basic (account and app password).',
        ];
    }

    /**
     * The client, or the reason there is none.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: CalDavClient|null, 1: string|null}
     */
    protected function calDavClient(array $config): array
    {
        $handle = trim((string) ($config['connection'] ?? ''));

        if ($handle === '') {
            return [null, 'No CalDAV connection is configured for this step: pick one under Connection. Nothing was read or written.'];
        }

        if (! AutomationConnection::schemaReady()) {
            return [null, 'The connection tables do not exist yet: run php artisan migrate. Nothing was read or written.'];
        }

        $connection = AutomationConnection::query()->where('handle', $handle)->first();

        if ($connection === null) {
            return [null, "The connection '{$handle}' does not exist. Nothing was read or written."];
        }

        return [new CalDavClient($connection), null];
    }
}
