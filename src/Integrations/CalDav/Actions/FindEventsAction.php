<?php

namespace Goldnead\StatamicAutomations\Integrations\CalDav\Actions;

use Carbon\CarbonImmutable;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\CalDav\CalDavClient;
use Goldnead\StatamicAutomations\Integrations\CalDav\Concerns\UsesCalDavConnection;
use Goldnead\StatamicAutomations\Integrations\CalDav\Ics;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * The events of a CalDAV calendar in a time range.
 *
 * One REPORT calendar-query with Depth 1 on the collection. Each event
 * resource becomes one entry; for a recurring series with changed occurrences
 * (several VEVENTs in one resource) the entry describes the series itself,
 * the VEVENT without RECURRENCE-ID. `href` and `etag` are what
 * `caldav.upsert_description_block` writes against.
 *
 * Only reads, so a test run reads too: that is what a test run of a search is
 * for. The raw calendar text never goes into the output, so it never reaches
 * the run log; only the named fields do.
 */
class FindEventsAction implements AutomationAction
{
    use UsesCalDavConnection;

    public static function handle(): string
    {
        return 'caldav.find_events';
    }

    public static function label(): string
    {
        return 'Find Events (CalDAV)';
    }

    public static function description(): ?string
    {
        return 'Lists the events of a CalDAV calendar (iCloud, Nextcloud, Fastmail and others) in a time range.';
    }

    public static function group(): string
    {
        return 'CalDAV';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            self::connectionField(),
            [
                'handle' => 'range_start',
                'label' => 'From',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'Start of the range, anything a date parser reads: 2026-10-01, today, -7 days. Without a zone it is the site\'s time zone.',
            ],
            [
                'handle' => 'range_end',
                'label' => 'Until',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'End of the range, same formats. An event touching the range counts.',
            ],
            [
                'handle' => 'url_contains',
                'label' => 'URL contains',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Only events whose URL field contains this text (case does not matter), e.g. notion.so. Empty: all events.',
            ],
        ];
    }

    /**
     * `events` is a list of {href, etag, uid, summary, dtstart, url, url_id,
     * description}. `url_id` is the id at the end of the event's URL (32 hex
     * digits or a UUID, without dashes), which for a Notion link is the id of
     * the page.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'events' => 'array',
            'count' => 'integer',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $range = $this->range($config['range_start'] ?? null, $config['range_end'] ?? null);

        if (is_string($range)) {
            return ActionResult::failed($range);
        }

        [$client, $problem] = $this->calDavClient($config);

        if ($client === null) {
            return ActionResult::failed((string) $problem);
        }

        $connection = $client->connection();

        try {
            $response = $client->report($range[0], $range[1]);
        } catch (\Throwable $e) {
            return ActionResult::failed((string) $connection->mask('The calendar could not be read: '.$e->getMessage()));
        }

        if (! $response->successful()) {
            return ActionResult::failed("The calendar answered the query with HTTP {$response->status()}.", ['status' => $response->status()]);
        }

        $resources = CalDavClient::multistatus($response->body());

        if ($resources === null) {
            return ActionResult::failed('The calendar answered with something that is not a CalDAV multistatus.');
        }

        $needle = strtolower(trim((string) ($config['url_contains'] ?? '')));
        $events = [];

        foreach ($resources as $resource) {
            $vevents = Ics::events($resource['ics']);

            if ($vevents === []) {
                continue;
            }

            if ($needle !== '' && ! $this->anyUrlContains($vevents, $needle)) {
                continue;
            }

            $master = collect($vevents)->first(fn (array $e) => ! isset($e['RECURRENCE-ID'])) ?? $vevents[0];
            $url = $master['URL'] ?? collect($vevents)->pluck('URL')->filter()->first();

            $events[] = [
                'href' => $resource['href'],
                'etag' => $resource['etag'],
                'uid' => $master['UID'] ?? null,
                'summary' => $master['SUMMARY'] ?? null,
                'dtstart' => $master['DTSTART'] ?? null,
                'url' => $url,
                'url_id' => Ics::urlId($url),
                'description' => $master['DESCRIPTION'] ?? null,
            ];
        }

        usort($events, fn (array $a, array $b) => strcmp((string) $a['dtstart'], (string) $b['dtstart']));

        return ActionResult::success($connection->mask([
            'events' => $events,
            'count' => count($events),
        ]));
    }

    /** @param  list<array<string, string>>  $vevents */
    protected function anyUrlContains(array $vevents, string $needle): bool
    {
        foreach ($vevents as $event) {
            if (str_contains(strtolower($event['URL'] ?? ''), $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: string, 1: string}|string the UTC bounds, or why not */
    protected function range(mixed $start, mixed $end): array|string
    {
        if (! is_string($start) || trim($start) === '' || ! is_string($end) || trim($end) === '') {
            return 'Both ends of the range are required.';
        }

        try {
            $from = CarbonImmutable::parse(trim($start), config('app.timezone'));
            $until = CarbonImmutable::parse(trim($end), config('app.timezone'));
        } catch (\Throwable) {
            return "The range '{$start}' to '{$end}' is not something a date parser reads.";
        }

        if ($until->lessThanOrEqualTo($from)) {
            return 'The end of the range lies before its start.';
        }

        return [$from->utc()->format('Ymd\THis\Z'), $until->utc()->format('Ymd\THis\Z')];
    }
}
