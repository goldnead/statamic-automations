<?php

namespace Goldnead\StatamicAutomations\Integrations\CalDav\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\CalDav\Concerns\UsesCalDavConnection;
use Goldnead\StatamicAutomations\Integrations\CalDav\Ics;
use Goldnead\StatamicAutomations\Integrations\CalDav\MarkerMissingException;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Keeps a block of text inside the DESCRIPTION of a calendar event up to
 * date, between two markers, and leaves everything else in the event alone.
 *
 * What people typed into the description before or after the block stays.
 * Only DESCRIPTION, DTSTAMP and LAST-MODIFIED change; the rest of the event
 * goes back byte for byte, VALARM included (see {@see Ics}).
 *
 * ## Read fresh, write only against what was read
 *
 * The event is fetched right before the write, never taken from an earlier
 * search, and written with `If-Match` on the ETag of that fetch. Someone
 * editing the event in their calendar app in between makes the server answer
 * 412, and this node reports `conflict` and fails, so the engine's retry reads
 * again and writes on the new state. Nothing is ever written over a change it
 * has not seen.
 *
 * ## What `status` says
 *
 * - `written`: the event was changed, and the server accepted it (2xx).
 * - `unchanged`: the block already stands there exactly so. No PUT went out,
 *   which is also why running this every few minutes costs nothing.
 * - `skipped_empty`: the block text is empty. Nothing is written, so a source
 *   that has nothing (yet) does not wipe a block that has content.
 * - `conflict`: 412, somebody was faster. The node fails.
 * - `error`: the node fails; `reason` says why. `marker_missing` means the
 *   description holds the start marker but not the end marker. There is then
 *   no telling where the block ends, and rather than cut off what someone
 *   typed after it, nothing is written. A person fixes the markers.
 *
 * Every VEVENT of the resource gets the block, on purpose: a changed
 * occurrence of a series (RECURRENCE-ID) has its own DESCRIPTION, and that is
 * what the calendar shows on that date.
 *
 * A test run reads the event and reports what it would do (`would_write` or
 * `unchanged`), without a PUT.
 */
class UpsertDescriptionBlockAction implements AutomationAction
{
    use UsesCalDavConnection;

    public const DEFAULT_MARKER_START = '--- automation: start ---';

    public const DEFAULT_MARKER_END = '--- automation: end ---';

    public static function handle(): string
    {
        return 'caldav.upsert_description_block';
    }

    public static function label(): string
    {
        return 'Update Event Description Block (CalDAV)';
    }

    public static function description(): ?string
    {
        return 'Writes a block of text between two markers into the description of a calendar event, and changes nothing else.';
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
                'handle' => 'href',
                'label' => 'Event',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'The href of the event, usually {{ item.href }} from Find Events (CalDAV) inside a loop.',
            ],
            [
                'handle' => 'block_text',
                'label' => 'Block text',
                'type' => 'textarea',
                'required' => true,
                'tokenable' => true,
                'help' => 'What goes between the markers. Empty means nothing is written.',
            ],
            [
                'handle' => 'marker_start',
                'label' => 'Start marker',
                'type' => 'text',
                'required' => true,
                'default' => self::DEFAULT_MARKER_START,
                'help' => 'The line that opens the block. Change it only before the first run: a block under the old marker is not found again.',
            ],
            [
                'handle' => 'marker_end',
                'label' => 'End marker',
                'type' => 'text',
                'required' => true,
                'default' => self::DEFAULT_MARKER_END,
                'help' => 'The line that closes the block.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function outputSchema(): array
    {
        return [
            'status' => 'string',
            'reason' => 'string',
            'href' => 'string',
            'etag' => 'string',
            'written' => 'boolean',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $href = trim((string) ($config['href'] ?? ''));
        $block = trim(str_replace(["\r\n", "\r"], "\n", (string) ($config['block_text'] ?? '')));
        $start = trim((string) (($config['marker_start'] ?? '') ?: self::DEFAULT_MARKER_START));
        $end = trim((string) (($config['marker_end'] ?? '') ?: self::DEFAULT_MARKER_END));

        if (str_contains($start, "\n") || str_contains($end, "\n")) {
            return ActionResult::failed('A marker is one line.');
        }

        if (str_contains($start, $end) || str_contains($end, $start)) {
            return ActionResult::failed('Start and end marker have to be two different lines, and neither may contain the other.');
        }

        // Nothing to say is not a reason to erase what is there.
        if ($block === '') {
            return ActionResult::success(['status' => 'skipped_empty', 'reason' => null, 'href' => $href, 'etag' => null, 'written' => false]);
        }

        if ($href === '') {
            return ActionResult::failed('The event is required: pass the href from Find Events (CalDAV).');
        }

        [$client, $problem] = $this->calDavClient($config);

        if ($client === null) {
            return ActionResult::failed((string) $problem);
        }

        $connection = $client->connection();
        $url = $client->resolve($href);

        if ($url === null) {
            return ActionResult::failed(
                'The event lies on another host than the calendar of the connection; the credential is not sent there.',
                ['status' => 'error', 'reason' => 'foreign_host', 'href' => $href, 'written' => false],
            );
        }

        $failed = fn (string $message, string $status, ?string $reason, ?string $etag = null) => ActionResult::failed(
            (string) $connection->mask($message),
            ['status' => $status, 'reason' => $reason, 'href' => $href, 'etag' => $etag, 'written' => false],
        );

        try {
            $current = $client->get($url);
        } catch (\Throwable $e) {
            return $failed('The event could not be read: '.$e->getMessage(), 'error', 'request_failed');
        }

        if (! $current->successful()) {
            return $failed(
                "Reading the event answered HTTP {$current->status()}.",
                'error',
                $current->status() === 404 ? 'not_found' : 'http_'.$current->status(),
            );
        }

        $etag = trim((string) $current->header('ETag'));

        if ($etag === '') {
            return $failed('The calendar did not send an ETag for the event, so it cannot be written safely.', 'error', 'no_etag');
        }

        // A 200 is not yet a calendar event: a login page, an empty body or a
        // proxy's notice would otherwise come out as a green "unchanged" for
        // an event nobody has looked at.
        if (Ics::events($current->body()) === []) {
            return $failed('The calendar answered without an event (no VEVENT in the response).', 'error', 'not_ics', $etag);
        }

        try {
            $ics = Ics::withDescriptionBlock($current->body(), $block, $start, $end, now());
        } catch (MarkerMissingException $e) {
            return $failed($e->getMessage().' Fix the markers in the event by hand.', 'error', 'marker_missing', $etag);
        }

        if ($ics === null) {
            return ActionResult::success(['status' => 'unchanged', 'reason' => null, 'href' => $href, 'etag' => $etag, 'written' => false]);
        }

        if ($context->isTestMode()) {
            return ActionResult::success([
                'status' => 'would_write',
                'reason' => null,
                'href' => $href,
                'etag' => $etag,
                'written' => false,
                'note' => 'Test mode — the event was read, nothing was written.',
            ]);
        }

        try {
            $response = $client->put($url, $ics, $etag);
        } catch (\Throwable $e) {
            return $failed('Writing the event failed: '.$e->getMessage(), 'error', 'request_failed', $etag);
        }

        if ($response->status() === 412) {
            return $failed('The event changed after it was read (HTTP 412). Nothing was written; a retry reads it again.', 'conflict', 'etag_mismatch', $etag);
        }

        if (! $response->successful()) {
            return $failed("Writing the event answered HTTP {$response->status()}.", 'error', 'http_'.$response->status(), $etag);
        }

        return ActionResult::success([
            'status' => 'written',
            'reason' => null,
            'href' => $href,
            // Not every server names the new ETag; null then, not the old one.
            'etag' => trim((string) $response->header('ETag')) ?: null,
            'written' => true,
        ]);
    }
}
