<?php

namespace Goldnead\StatamicAutomations\Integrations\Notion\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\Notion\NotionData;
use Goldnead\StatamicAutomations\Integrations\Notion\NotionException;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Reads the rows of a Notion data source, with their properties as plain
 * values ({@see NotionData}).
 *
 * The node a Notion flow starts with: the gigs of "Sheets Konzerte", then the
 * entries of "Zeitplan" that belong to one gig. For the second step there is
 * `relation_contains_any`: a relation property and a list of page IDs,
 * turned into Notion's `or` of `relation.contains` filters. Written by hand,
 * that filter is a JSON block per ID.
 *
 * ## An empty ID list reads nothing
 *
 * "Rows related to any of these pages" with no pages is no rows, and the node
 * says so without asking Notion. Sent as a query without the filter, it would
 * have returned the whole data source: every timetable entry of every gig in
 * the text of one.
 *
 * ## Pagination has a cap
 *
 * Notion answers 100 rows per request. `max_pages` bounds the requests, and
 * `has_more` says when the cap cut the list short, so a flow can tell "these
 * are all" from "these are the first 1000".
 */
class QueryDataSourceAction implements AutomationAction
{
    use UsesNotionConnection;

    protected const DEFAULT_MAX_PAGES = 10;

    public static function handle(): string
    {
        return 'notion.query_data_source';
    }

    public static function label(): string
    {
        return 'Query Data Source (Notion)';
    }

    public static function description(): ?string
    {
        return 'Reads the rows of a Notion data source, filtered and sorted, with every property as a plain value.';
    }

    public static function schema(): array
    {
        return [
            self::connectionField(),
            [
                'handle' => 'data_source_id',
                'label' => 'Data source ID',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'The ID of the data source (Notion: database menu, Manage data sources, Copy data source ID). Not the database ID: since API version 2025-09-03 a database can hold several data sources.',
            ],
            [
                'handle' => 'relation_property',
                'label' => 'Related to any of: property',
                'type' => 'text',
                'required' => false,
                'help' => 'Optional. A relation property, for example Konzertkalender. Only rows whose relation contains one of the page IDs below are read.',
            ],
            [
                'handle' => 'relation_ids',
                'label' => 'Related to any of: page IDs',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'A list of page IDs or Notion links, for example {{ item.properties.Konzertkalender }}. An empty list reads no rows.',
            ],
            [
                'handle' => 'filter',
                'label' => 'Filter (JSON)',
                'type' => 'textarea',
                'required' => false,
                'tokenable' => true,
                'help' => 'Optional. A Notion filter object as JSON, for example {"property": "Status", "status": {"equals": "Fix"}}. Combined with the relation above by "and".',
            ],
            [
                'handle' => 'sorts',
                'label' => 'Sorts (JSON)',
                'type' => 'textarea',
                'required' => false,
                'tokenable' => true,
                'help' => 'Optional. A list of Notion sorts as JSON, for example [{"property": "Date", "direction": "ascending"}].',
            ],
            [
                'handle' => 'max_pages',
                'label' => 'Requests at most',
                'type' => 'number',
                'required' => false,
                'default' => self::DEFAULT_MAX_PAGES,
                'help' => 'Notion answers 100 rows per request. Default 10, so 1000 rows; has_more tells when there were more.',
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
            'has_more' => 'boolean',
            'data_source_id' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $dataSource = NotionData::id($config['data_source_id'] ?? null);

        if ($dataSource === null) {
            return ActionResult::failed('A data source ID is required (32 hexadecimal characters, with or without dashes).');
        }

        $body = [];

        $filter = $this->json($config['filter'] ?? null, 'filter');
        if ($filter instanceof ActionResult) {
            return $filter;
        }

        $sorts = $this->json($config['sorts'] ?? null, 'sorts');
        if ($sorts instanceof ActionResult) {
            return $sorts;
        }

        $property = trim((string) ($config['relation_property'] ?? ''));

        if ($property !== '') {
            $ids = NotionData::ids($config['relation_ids'] ?? null);

            if ($ids === []) {
                return ActionResult::success([
                    'pages' => [],
                    'count' => 0,
                    'has_more' => false,
                    'data_source_id' => $dataSource,
                    'note' => "No page IDs for '{$property}', so no rows are related to them. Notion was not asked.",
                ]);
            }

            $relation = array_map(fn (string $id) => ['property' => $property, 'relation' => ['contains' => $id]], $ids);
            $relation = count($relation) === 1 ? $relation[0] : ['or' => $relation];
            $filter = $filter === null ? $relation : ['and' => [$filter, $relation]];
        }

        if ($filter !== null) {
            $body['filter'] = $filter;
        }

        if ($sorts !== null) {
            $body['sorts'] = array_is_list($sorts) ? $sorts : [$sorts];
        }

        $zone = $this->timeZone($config);
        if ($zone instanceof ActionResult) {
            return $zone;
        }

        $client = $this->notion($config);
        if ($client instanceof ActionResult) {
            return $client;
        }

        $maxPages = is_numeric($config['max_pages'] ?? null) && (int) $config['max_pages'] > 0
            ? (int) $config['max_pages']
            : self::DEFAULT_MAX_PAGES;

        try {
            $answer = $client->queryDataSource($dataSource, $body, $maxPages);
        } catch (NotionException $e) {
            return ActionResult::failed($e->getMessage(), ['data_source_id' => $dataSource]);
        }

        $pages = array_map(fn (array $page) => NotionData::page($page, $zone), $answer['results']);

        return ActionResult::success([
            'pages' => $pages,
            'count' => count($pages),
            'has_more' => $answer['has_more'],
            'data_source_id' => $dataSource,
        ]);
    }

    /**
     * A JSON field as an array, null when empty, a failed result when it is
     * not JSON. Invalid JSON is not "no filter": read as none, it would
     * return every row.
     *
     * @return array<mixed>|ActionResult|null
     */
    protected function json(mixed $value, string $field): array|ActionResult|null
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return ActionResult::failed("The {$field} is not a JSON object or list. Nothing was read.");
        }

        return $decoded;
    }
}
