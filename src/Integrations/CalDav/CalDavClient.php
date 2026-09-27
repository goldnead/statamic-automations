<?php

namespace Goldnead\StatamicAutomations\Integrations\CalDav;

use DOMDocument;
use DOMXPath;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Support\HostGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A small CalDAV client on top of a connection set up in the CP.
 *
 * The connection is the credential: its base URL is the calendar collection
 * (iCloud: `https://pXX-caldav.icloud.com/<id>/calendars/<calendar>/`), its
 * auth is basic with the account and an app password. Nothing is read from
 * env, so every brand brings its own calendar.
 *
 * Every call goes through the same fences as a connection operation: the
 * HostGuard pins the address, no redirects are followed (a credential header
 * must not follow one to another host), and an href that names another host
 * than the collection is refused rather than sent the credential.
 */
class CalDavClient
{
    public function __construct(protected AutomationConnection $connection) {}

    public function connection(): AutomationConnection
    {
        return $this->connection;
    }

    /**
     * REPORT calendar-query, Depth 1: every event resource touching the range.
     * The range is in UTC, `Ymd\THis\Z`.
     */
    public function report(string $start, string $end): Response
    {
        $body = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop><d:getetag/><c:calendar-data/></d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="{$start}" end="{$end}"/>
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>
XML;

        $url = $this->connection->url('');

        return $this->http($url)
            ->withHeaders(['Depth' => '1'])
            ->withBody($body, 'application/xml; charset=utf-8')
            ->send('REPORT', $url);
    }

    public function get(string $url): Response
    {
        return $this->http($url)->withHeaders(['Accept' => 'text/calendar'])->get($url);
    }

    /** Write only if the resource is still the one read (If-Match). */
    public function put(string $url, string $ics, string $etag): Response
    {
        return $this->http($url)
            ->withHeaders(['If-Match' => $etag])
            ->withBody($ics, 'text/calendar; charset=utf-8')
            ->send('PUT', $url);
    }

    /**
     * The absolute URL of an href, or null when it points at another host
     * (or scheme or port) than the collection.
     */
    public function resolve(string $href): ?string
    {
        $href = trim($href);
        $base = parse_url($this->connection->base_url);

        if ($href === '' || ! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            return null;
        }

        $origin = strtolower($base['scheme']).'://'.strtolower($base['host']).(isset($base['port']) ? ':'.$base['port'] : '');

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)) {
            $parts = parse_url($href);

            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                return null;
            }

            $hrefOrigin = strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');

            return $hrefOrigin === $origin ? $href : null;
        }

        // A protocol-relative `//host/…` would change the host.
        if (str_starts_with($href, '//')) {
            return null;
        }

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        return rtrim($this->connection->url(''), '/').'/'.$href;
    }

    /**
     * The event resources of a multistatus answer. A response without an
     * href, an ETag or calendar data (a 404 propstat, the collection itself)
     * is left out.
     *
     * @return list<array{href: string, etag: string, ics: string}>|null null when the answer is not usable XML
     */
    public static function multistatus(string $xml): ?array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = trim($xml) !== '' && stripos($xml, '<!DOCTYPE') === false && $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $dom->doctype !== null) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        $resources = [];
        foreach ($xpath->query('//d:response') ?: [] as $response) {
            $href = trim((string) $xpath->evaluate('string(d:href)', $response));
            $etag = trim((string) $xpath->evaluate('string(d:propstat/d:prop/d:getetag)', $response));
            $ics = (string) $xpath->evaluate('string(d:propstat/d:prop/c:calendar-data)', $response);

            if ($href === '' || $etag === '' || trim($ics) === '') {
                continue;
            }

            $resources[] = ['href' => $href, 'etag' => $etag, 'ics' => $ics];
        }

        return $resources;
    }

    protected function http(string $url): PendingRequest
    {
        return Http::withOptions(app(HostGuard::class)->guard($url))
            ->withHeaders($this->connection->requestHeaders([], ['content-type', 'if-match', 'depth']))
            ->withoutRedirecting()
            ->timeout(max(1, (int) $this->connection->timeout));
    }
}
