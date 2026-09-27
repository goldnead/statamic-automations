<?php

namespace Goldnead\StatamicAutomations\Integrations\Notion\Actions;

use Goldnead\StatamicAutomations\Integrations\Notion\NotionClient;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * What the three Notion nodes share: the connection field, the display time
 * zone, and the refusal to run without a credential.
 *
 * Without a connection the nodes call nothing and go red with the reason. A
 * green node with an empty list would read as "Notion has no rows", which is
 * the one answer that is certainly wrong.
 */
trait UsesNotionConnection
{
    public static function group(): string
    {
        return 'Notion';
    }

    /** Read only, so a test run reads for real. */
    public static function supportsTestMode(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function connectionField(): array
    {
        return [
            'handle' => 'connection',
            'label' => 'Connection',
            'type' => 'text',
            'required' => false,
            'default' => 'notion',
            'help' => 'Handle of a connection (empty: notion) under Automations, Connections, with bearer auth and the token of a Notion integration. The integration must be invited to the pages it reads.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function timeZoneField(): array
    {
        return [
            'handle' => 'time_zone',
            'label' => 'Time zone for dates',
            'type' => 'text',
            'required' => false,
            'tokenable' => true,
            'help' => 'Zone of start_date, start_time, end_date and end_time on every date, for example Europe/Berlin. Empty: the display time zone of the site.',
        ];
    }

    /**
     * The client, or the failed result that says why there is none.
     *
     * @param  array<string, mixed>  $config
     */
    protected function notion(array $config): NotionClient|ActionResult
    {
        $handle = trim((string) ($config['connection'] ?? '')) ?: 'notion';
        $connection = NotionClient::connection($handle);

        if ($connection === null) {
            return ActionResult::failed(
                "There is no connection '{$handle}'. Set one up under Automations, Connections: base URL https://api.notion.com, "
                    .'bearer auth with the token of a Notion integration. Nothing was read.',
            );
        }

        $client = new NotionClient($connection);

        if ($connection->auth_type !== 'bearer') {
            return ActionResult::failed(
                "The connection '{$handle}' uses '{$connection->auth_type}' auth. The Notion nodes only send a bearer token, "
                    .'so a credential meant for another service never goes to Notion. Nothing was read.',
            );
        }

        if (! $client->hasBearerToken()) {
            return ActionResult::failed("The connection '{$handle}' has no token. Nothing was read.");
        }

        return $client;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function timeZone(array $config): string|ActionResult
    {
        $zone = trim((string) ($config['time_zone'] ?? ''));

        if ($zone === '') {
            return (string) (config('statamic.system.display_timezone') ?: config('app.timezone', 'UTC'));
        }

        // A typo here would shift every time by hours without a word.
        return in_array($zone, \DateTimeZone::listIdentifiers(), true)
            ? $zone
            : ActionResult::failed("'{$zone}' is not a time zone. Use a name like Europe/Berlin.");
    }
}
