<?php

// 2.23.0: CalDAV via a connection. The ICS logic is ported with its tests from
// the gig calendar of anders-band.de (GigKalenderTest), where it writes into a
// shared iCloud calendar that people edit by hand on their Macs.

use Carbon\CarbonImmutable;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Integrations\CalDav\Actions\FindEventsAction;
use Goldnead\StatamicAutomations\Integrations\CalDav\Actions\UpsertDescriptionBlockAction;
use Goldnead\StatamicAutomations\Integrations\CalDav\CalDavClient;
use Goldnead\StatamicAutomations\Integrations\CalDav\Ics;
use Goldnead\StatamicAutomations\Integrations\CalDav\MarkerMissingException;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\HostGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const CALDAV_PAGE = '3c8739f36bad809f90ebd3762307e5a1';
const CALDAV_PASSWORD = 'abcd-efgh-ijkl-mnop';
const CALDAV_START = '--- aus Notion (automatisch) ---';
const CALDAV_END = '--- Ende Notion ---';

beforeEach(function () {
    app(HostGuard::class)->resolveUsing(fn () => ['17.253.144.10']);
    $this->ics = '';
    $this->putStatus = 204;
});

/** As the Mac makes it: CRLF, the URL folded over two lines. */
function bevernIcs(?string $description = null, array $extraEvents = []): string
{
    $lines = [
        'BEGIN:VCALENDAR',
        'CALSCALE:GREGORIAN',
        'PRODID:-//Apple Inc.//macOS 26.6.2//EN',
        'VERSION:2.0',
        'BEGIN:VEVENT',
        'CREATED:20260826T113907Z',
        'DTEND;VALUE=DATE:20260912',
        'DTSTAMP:20260910T075252Z',
        'DTSTART;VALUE=DATE:20260911',
        'LAST-MODIFIED:20260910T075252Z',
        'SEQUENCE:3',
        'SUMMARY:c 37639 Bevern\,. Schloss',
        'UID:A0719B06-E43C-49BE-9F97-BD1FD5F59E27',
        'URL;VALUE=URI:https://app.notion.com/p/anders-band/Sheet-Bevern-Schlossin',
        ' nenhof-2026-3c8739f36bad809f90ebd3762307e5a1?source=copy_link',
        'X-APPLE-CREATOR-IDENTITY:com.apple.calendar',
        'BEGIN:VALARM',
        'ACTION:DISPLAY',
        'DESCRIPTION:Erinnerung',
        'TRIGGER:-PT15M',
        'END:VALARM',
        'END:VEVENT',
        ...$extraEvents,
        'END:VCALENDAR',
    ];

    if ($description !== null) {
        array_splice($lines, 10, 0, Ics::fold('DESCRIPTION:'.Ics::escape($description)));
    }

    return implode("\r\n", $lines)."\r\n";
}

function caldavConnection(string $handle = 'icloud'): AutomationConnection
{
    return AutomationConnection::create([
        'handle' => $handle,
        'name' => 'iCloud',
        'base_url' => 'https://caldav.example.test/123/calendars/anders/',
        'auth_type' => 'basic',
        'auth_config' => ['username' => 'band@example.test', 'password' => CALDAV_PASSWORD],
    ]);
}

/** calendar-data comes as CDATA with raw CRLF, like iCloud; the XML parser makes LF of it. */
function multistatusXml(array $resources): string
{
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n<multistatus xmlns=\"DAV:\">";
    foreach ($resources as [$href, $etag, $ics]) {
        $xml .= "<response><href>{$href}</href><propstat><prop><getetag>{$etag}</getetag>"
            .'<calendar-data xmlns="urn:ietf:params:xml:ns:caldav"><![CDATA['.$ics.']]></calendar-data></prop>'
            .'<status>HTTP/1.1 200 OK</status></propstat></response>';
    }

    return $xml.'</multistatus>';
}

/** Stubs that read the current state, since the first matching stub wins. */
function fakeCalendar($test): void
{
    Http::fake([
        'caldav.example.test/*' => fn (Request $request) => match ($request->method()) {
            'REPORT' => Http::response(multistatusXml([['/123/calendars/anders/A0719B06.ics', '"e1"', $test->ics]]), 207),
            'GET' => Http::response($test->ics, 200, ['ETag' => '"e1"', 'Content-Type' => 'text/calendar']),
            'PUT' => Http::response('', $test->putStatus, $test->putStatus < 300 ? ['ETag' => '"e2"'] : []),
        },
    ]);
}

function upsert(array $config, bool $testMode = false): ActionResult
{
    return app(UpsertDescriptionBlockAction::class)->execute(
        AutomationContext::make([], testMode: $testMode),
        $config + ['connection' => 'icloud', 'href' => '/123/calendars/anders/A0719B06.ics', 'marker_start' => CALDAV_START, 'marker_end' => CALDAV_END],
    );
}

function findEvents(array $config, bool $testMode = false): ActionResult
{
    return app(FindEventsAction::class)->execute(
        AutomationContext::make([], testMode: $testMode),
        $config + ['connection' => 'icloud', 'range_start' => '2026-09-01', 'range_end' => '2026-10-01'],
    );
}

// --- The ICS helpers (pure) ---------------------------------------------------

it('reads the id at the end of the url path, also when the url is folded', function () {
    expect(Ics::events(bevernIcs())[0]['URL'])->toEndWith(CALDAV_PAGE.'?source=copy_link');
    expect(Ics::urlId(Ics::events(bevernIcs())[0]['URL']))->toBe(CALDAV_PAGE);

    // The id of a view in the query does not count.
    expect(Ics::urlId('https://app.notion.com/p/anders-band/Sheet-Dortmund-2026-3c9739f36bad80c8bc42e3a86d90bd69?v=38d739f36bad8000a2fd000c1234abcd'))
        ->toBe('3c9739f36bad80c8bc42e3a86d90bd69');
    expect(Ics::urlId('https://www.notion.so/3c8739f3-6bad-809f-90eb-d3762307e5a1'))->toBe(CALDAV_PAGE);
    expect(Ics::urlId('https://app.notion.com/p/anders-band?v='.CALDAV_PAGE))->toBeNull();
    expect(Ics::urlId('https://example.com/a'.str_repeat('f', 40)))->toBeNull();
    expect(Ics::urlId(null))->toBeNull();
});

it('appends and replaces the block without touching what people wrote', function () {
    $block = fn (string $inner) => CALDAV_START."\n{$inner}\n".CALDAV_END;

    expect(Ics::replaceBlock('', 'A', CALDAV_START, CALDAV_END))->toBe($block('A'));
    expect(Ics::replaceBlock("\n", 'A', CALDAV_START, CALDAV_END))->toBe($block('A'));
    expect(Ics::replaceBlock("Gästeliste:\n- Carmen\n", 'A', CALDAV_START, CALDAV_END))->toBe("Gästeliste:\n- Carmen\n\n".$block('A'));

    $withBlock = "Vorher\n\n".$block("alt\nalt")."\n\nDanach, von Hand";
    expect(Ics::replaceBlock($withBlock, 'neu', CALDAV_START, CALDAV_END))->toBe("Vorher\n\n".$block('neu')."\n\nDanach, von Hand");

    // Same content, same text: otherwise every run would PUT.
    $once = Ics::replaceBlock('Notiz', 'X', CALDAV_START, CALDAV_END);
    expect(Ics::replaceBlock($once, 'X', CALDAV_START, CALDAV_END))->toBe($once);
});

it('survives escaping and folding round trips and changes only three lines', function () {
    $before = bevernIcs('Gästeliste: 2x Müller; 1x Özdemir, "VIP" \\ Backslash');
    $content = "Zeitplan\n15:00 Get In\n16:30 Soundcheck\n\nAnmerkungen\nHotel Keybox 011756#; Zimmer, Frühstück ab 7 "
        .str_repeat('äöü€ ', 40);
    $now = CarbonImmutable::parse('2026-09-27 12:00:00', 'UTC');

    $after = Ics::withDescriptionBlock($before, $content, CALDAV_START, CALDAV_END, $now);

    expect($after)->not->toBeNull()->toEndWith("\r\n");

    foreach (explode("\r\n", rtrim($after, "\r\n")) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
        expect(mb_check_encoding($line, 'UTF-8'))->toBeTrue('folding cut a UTF-8 character');
    }

    expect(Ics::property($after, 'DESCRIPTION'))
        ->toBe("Gästeliste: 2x Müller; 1x Özdemir, \"VIP\" \\ Backslash\n\n".CALDAV_START."\n{$content}\n".CALDAV_END);
    expect(Ics::property($after, 'DTSTAMP'))->toBe('20260927T120000Z');
    expect(Ics::property($after, 'LAST-MODIFIED'))->toBe('20260927T120000Z');

    // Everything but DESCRIPTION, DTSTAMP and LAST-MODIFIED stays byte for
    // byte, the DESCRIPTION of the VALARM too.
    $rest = fn (string $ics) => array_values(array_filter(
        Ics::lines($ics),
        fn ($l) => ! preg_match('/^(DESCRIPTION:(?!Erinnerung)|DTSTAMP|LAST-MODIFIED)/', $l['text']),
    ));
    expect($rest($after))->toBe($rest($before));
    expect($after)->toContain("\r\nDESCRIPTION:Erinnerung\r\n");

    // Without a DESCRIPTION one is inserted, before the VALARM.
    $new = Ics::withDescriptionBlock(bevernIcs(), 'kurz', CALDAV_START, CALDAV_END, $now);
    expect(Ics::property($new, 'DESCRIPTION'))->toBe(CALDAV_START."\nkurz\n".CALDAV_END);
    expect(strpos($new, 'DESCRIPTION:---'))->toBeLessThan(strpos($new, 'BEGIN:VALARM'));

    // Second run with the same content: nothing to do.
    expect(Ics::withDescriptionBlock($after, $content, CALDAV_START, CALDAV_END, $now->addHour()))->toBeNull();
});

it('keeps parameters like LANGUAGE and ALTREP on the description', function () {
    $ics = str_replace(
        'SEQUENCE:3',
        "DESCRIPTION;LANGUAGE=de;ALTREP=\"cid:part1@example.test\":Alt\r\nSEQUENCE:3",
        bevernIcs(),
    );

    $after = Ics::withDescriptionBlock($ics, 'Neu', CALDAV_START, CALDAV_END, now());

    expect($after)->toContain('DESCRIPTION;LANGUAGE=de;ALTREP="cid:part1@example.test":Alt\n\n');
    expect(Ics::property($after, 'DESCRIPTION'))->toBe("Alt\n\n".CALDAV_START."\nNeu\n".CALDAV_END);
});

it('writes the block into every VEVENT of a recurring series', function () {
    $override = [
        'BEGIN:VEVENT',
        'DTSTAMP:20260910T075252Z',
        'DTSTART;VALUE=DATE:20260918',
        'RECURRENCE-ID;VALUE=DATE:20260918',
        'SUMMARY:Verlegt',
        'UID:A0719B06-E43C-49BE-9F97-BD1FD5F59E27',
        'DESCRIPTION:Nur diese Woche',
        'END:VEVENT',
    ];

    $after = Ics::withDescriptionBlock(bevernIcs('Serie', $override), 'Block', CALDAV_START, CALDAV_END, now());
    $events = Ics::events($after);

    expect($events)->toHaveCount(2);
    expect($events[0]['DESCRIPTION'])->toBe("Serie\n\n".CALDAV_START."\nBlock\n".CALDAV_END);
    expect($events[1]['DESCRIPTION'])->toBe("Nur diese Woche\n\n".CALDAV_START."\nBlock\n".CALDAV_END);
    expect($events[1]['RECURRENCE-ID'])->toBe('20260918');
});

it('refuses to guess where the block ends when the end marker is missing', function () {
    Ics::replaceBlock("Notiz\n\n".CALDAV_START."\nalt\n\nNachsatz von Hand", 'neu', CALDAV_START, CALDAV_END);
})->throws(MarkerMissingException::class);

it('parses a multistatus and gets LF out of the CRLF in calendar data', function () {
    $resources = CalDavClient::multistatus(multistatusXml([['/x.ics', '"e"', bevernIcs('A')]]));

    expect($resources)->toHaveCount(1);
    expect($resources[0]['href'])->toBe('/x.ics');
    expect($resources[0]['ics'])->not->toContain("\r\n");
    expect(CalDavClient::multistatus('not xml'))->toBeNull();
});

// --- caldav.find_events -----------------------------------------------------------

it('finds events with REPORT, depth 1 and the connection credential', function () {
    caldavConnection();
    $this->ics = bevernIcs('Gästeliste: 2x');
    fakeCalendar($this);

    $result = findEvents([]);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['count'])->toBe(1);
    expect($result->output['events'][0])->toBe([
        'href' => '/123/calendars/anders/A0719B06.ics',
        'etag' => '"e1"',
        'uid' => 'A0719B06-E43C-49BE-9F97-BD1FD5F59E27',
        'summary' => 'c 37639 Bevern,. Schloss',
        'dtstart' => '20260911',
        'url' => 'https://app.notion.com/p/anders-band/Sheet-Bevern-Schlossinnenhof-2026-'.CALDAV_PAGE.'?source=copy_link',
        'url_id' => CALDAV_PAGE,
        'description' => 'Gästeliste: 2x',
    ]);

    // No raw calendar text in the output, so none in the run log.
    expect(json_encode($result->output))->not->toContain('BEGIN:VCALENDAR');

    Http::assertSent(fn (Request $r) => $r->method() === 'REPORT'
        && $r->url() === 'https://caldav.example.test/123/calendars/anders/'
        && $r->header('Depth') === ['1']
        && str_contains($r->body(), '<c:time-range start="20260901T000000Z" end="20261001T000000Z"/>')
        && $r->header('Authorization') === ['Basic '.base64_encode('band@example.test:'.CALDAV_PASSWORD)]);
});

it('filters by url and reads in test mode too', function () {
    caldavConnection();
    $this->ics = bevernIcs();
    fakeCalendar($this);

    expect(findEvents(['url_contains' => 'NOTION.com'], testMode: true)->output['count'])->toBe(1);
    expect(findEvents(['url_contains' => 'example.org'])->output['count'])->toBe(0);
});

it('does nothing and says so without a connection', function () {
    Http::fake();

    $missing = findEvents(['connection' => '']);
    $unknown = findEvents(['connection' => 'nope']);
    $upsert = upsert(['connection' => 'nope', 'block_text' => 'x']);

    expect($missing->isFailed())->toBeTrue();
    expect($missing->error)->toContain('No CalDAV connection');
    expect($unknown->error)->toContain("'nope' does not exist");
    expect($upsert->isFailed())->toBeTrue();
    Http::assertNothingSent();
});

it('fails on a range it cannot read or that runs backwards', function () {
    caldavConnection();
    Http::fake();

    expect(findEvents(['range_start' => 'gestern irgendwann'])->isFailed())->toBeTrue();
    expect(findEvents(['range_start' => '2026-10-02', 'range_end' => '2026-10-01'])->error)->toContain('before its start');
    Http::assertNothingSent();
});

// --- caldav.upsert_description_block ----------------------------------------------

it('does not PUT when nothing changes, and PUTs with If-Match when it does', function () {
    caldavConnection();
    $block = "Programm\nWeihnachtsprogramm";
    $this->ics = bevernIcs("Gästeliste: 2x\n\n".CALDAV_START."\n{$block}\n".CALDAV_END);
    fakeCalendar($this);

    $unchanged = upsert(['block_text' => $block]);
    expect($unchanged->isSuccess())->toBeTrue();
    expect($unchanged->output['status'])->toBe('unchanged');
    expect($unchanged->output['written'])->toBeFalse();
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');

    $this->ics = bevernIcs('Gästeliste: 2x');
    $written = upsert(['block_text' => $block]);

    expect($written->output['status'])->toBe('written');
    expect($written->output['written'])->toBeTrue();
    expect($written->output['etag'])->toBe('"e2"');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
        && $r->url() === 'https://caldav.example.test/123/calendars/anders/A0719B06.ics'
        && $r->header('If-Match') === ['"e1"']
        && str_starts_with($r->header('Content-Type')[0], 'text/calendar')
        && str_contains($r->body(), 'DESCRIPTION:Gästeliste: 2x\n\n'.CALDAV_START));
});

it('always writes CRLF even though the XML parser hands out LF', function () {
    caldavConnection();
    // The resource as it came out of a REPORT: LF only.
    $this->ics = str_replace("\r\n", "\n", bevernIcs('Gästeliste: 2x'));
    fakeCalendar($this);

    upsert(['block_text' => 'Neu']);

    $puts = Http::recorded(fn (Request $r) => $r->method() === 'PUT');
    expect($puts)->toHaveCount(1);
    $body = $puts->first()[0]->body();

    expect($body)->toEndWith("END:VCALENDAR\r\n");
    expect(preg_match('/(?<!\r)\n/', $body))->toBe(0, 'bare LF in the PUT');
    expect(preg_match('/\r(?!\n)/', $body))->toBe(0, 'bare CR in the PUT');
    expect($body)->toStartWith("BEGIN:VCALENDAR\r\nCALSCALE:GREGORIAN\r\n");
    expect($body)->toContain("URL;VALUE=URI:https://app.notion.com/p/anders-band/Sheet-Bevern-Schlossin\r\n nenhof-2026-");
});

it('reports marker_missing and writes nothing when the end marker is gone', function () {
    caldavConnection();
    $this->ics = bevernIcs("Notiz\n\n".CALDAV_START."\nalt\n\nNachsatz von Hand");
    fakeCalendar($this);

    $result = upsert(['block_text' => 'neu']);

    expect($result->isFailed())->toBeTrue();
    expect($result->output['status'])->toBe('error');
    expect($result->output['reason'])->toBe('marker_missing');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
});

it('skips an empty block without reading or writing', function () {
    caldavConnection();
    Http::fake();

    $result = upsert(['block_text' => "  \n "]);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['status'])->toBe('skipped_empty');
    Http::assertNothingSent();
});

it('reports a 412 as conflict and fails so a retry reads again', function () {
    caldavConnection();
    $this->ics = bevernIcs('Alt');
    $this->putStatus = 412;
    fakeCalendar($this);

    $result = upsert(['block_text' => 'Neu']);

    expect($result->isFailed())->toBeTrue();
    expect($result->output['status'])->toBe('conflict');
    expect($result->output['written'])->toBeFalse();
});

it('reads but does not write in test mode', function () {
    caldavConnection();
    $this->ics = bevernIcs('Alt');
    fakeCalendar($this);

    $result = upsert(['block_text' => 'Neu'], testMode: true);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['status'])->toBe('would_write');
    Http::assertSent(fn (Request $r) => $r->method() === 'GET');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
});

it('does not send the credential to an href on another host', function () {
    caldavConnection();
    Http::fake();

    foreach (['https://evil.example.test/x.ics', '//evil.example.test/x.ics', 'http://caldav.example.test/x.ics'] as $href) {
        $result = upsert(['block_text' => 'x', 'href' => $href]);

        expect($result->isFailed())->toBeTrue();
        expect($result->output['reason'])->toBe('foreign_host');
    }

    Http::assertNothingSent();
});

it('refuses markers that are the same or contain each other', function () {
    caldavConnection();
    Http::fake();

    expect(upsert(['block_text' => 'x', 'marker_start' => '###', 'marker_end' => '###'])->isFailed())->toBeTrue();
    expect(upsert(['block_text' => 'x', 'marker_start' => '# start', 'marker_end' => '# start end'])->isFailed())->toBeTrue();
    Http::assertNothingSent();
});

it('registers both nodes and lists connections as their options', function () {
    $this->actingAsSuperUser();
    caldavConnection();

    $registry = app(NodeRegistry::class);
    expect($registry->has('caldav.find_events'))->toBeTrue();
    expect($registry->has('caldav.upsert_description_block'))->toBeTrue();
    expect(AutomationConnection::options())->toBe([['value' => 'icloud', 'label' => 'iCloud']]);
});
