<?php

namespace Goldnead\StatamicAutomations\Integrations\Notion\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Notion\NotionData;
use Goldnead\StatamicAutomations\Integrations\Notion\NotionException;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Reads Notion pages by ID, with their properties as plain values.
 *
 * The step after a relation or a rollup: a gig's Venue, Hotel or contact is a
 * list of page IDs, and the address or phone number sits on the pages behind
 * them. The IDs can come straight from a token
 * (`{{ item.properties.Venue }}`), as a list or as text.
 *
 * ## A page that cannot be read fails the node
 *
 * Notion answers 404 for a page that exists but is not shared with the
 * integration, the most common setup mistake. Leaving it out quietly would
 * make a venue without an address look like a venue nobody entered. With
 * `skip_missing` the node goes green anyway and names the IDs under
 * `missing`.
 */
class GetPagesAction implements AutomationAction
{
    use UsesNotionConnection;

    /** Pages per node run; each is one request. */
    public const MAX_IDS = 100;

    public static function handle(): string
    {
        return 'notion.get_pages';
    }

    public static function label(): string
    {
        return 'Get Pages (Notion)';
    }

    public static function description(): ?string
    {
        return 'Reads Notion pages by ID, for example the pages behind a relation, with every property as a plain value.';
    }

    public static function schema(): array
    {
        return [
            self::connectionField(),
            [
                'handle' => 'ids',
                'label' => 'Page IDs',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'A list of page IDs or Notion links, for example {{ item.properties.Venue }}. At most 100.',
            ],
            [
                'handle' => 'skip_missing',
                'label' => 'Skip pages that cannot be read',
                'type' => 'toggle',
                'default' => false,
                'help' => 'Off: a page Notion does not return (not shared with the integration, deleted) fails the node. On: it is left out and listed under missing.',
            ],
            self::timeZoneField(),
        ];
    }

    /** @return array<string, mixed> */
    public static function outputSchema(): array
    {
        return [
            'pages' => 'array',
            'count' => 'integer',
            'missing' => 'array',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $ids = NotionData::ids($config['ids'] ?? null);

        if ($ids === []) {
            return ActionResult::success([
                'pages' => [],
                'count' => 0,
                'missing' => [],
                'note' => 'No page IDs were given, so there was nothing to read.',
            ]);
        }

        if (count($ids) > self::MAX_IDS) {
            return ActionResult::failed('At most '.self::MAX_IDS.' pages per node, '.count($ids).' were given. Nothing was read.');
        }

        $zone = $this->timeZone($config);
        if ($zone instanceof ActionResult) {
            return $zone;
        }

        $client = $this->notion($config);
        if ($client instanceof ActionResult) {
            return $client;
        }

        $skipMissing = filter_var($config['skip_missing'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $pages = [];
        $missing = [];

        foreach ($ids as $id) {
            try {
                $pages[] = NotionData::page($client->page($id), $zone);
            } catch (NotionException $e) {
                if ($skipMissing && $e->isNotFound()) {
                    $missing[] = $id;

                    continue;
                }

                return ActionResult::failed($e->getMessage(), ['page_id' => $id, 'read' => count($pages)]);
            }
        }

        return ActionResult::success([
            'pages' => $pages,
            'count' => count($pages),
            'missing' => $missing,
        ]);
    }
}
