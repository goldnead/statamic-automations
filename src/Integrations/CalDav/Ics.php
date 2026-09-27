<?php

namespace Goldnead\StatamicAutomations\Integrations\CalDav;

use Carbon\CarbonInterface;

/**
 * The ICS helpers the CalDAV actions need, and nothing else. No network.
 *
 * Ported from the gig calendar of anders-band.de, where it has written into a
 * shared iCloud calendar since September 2026. Deliberately no vobject
 * library: the events are made by people in a calendar app, and apart from
 * DESCRIPTION, DTSTAMP and LAST-MODIFIED no line may change. So the text is
 * worked on line by line. Every logical line keeps its physical lines, and
 * only those three properties are written anew.
 *
 * What bit there and is covered by a test here:
 *
 * - Folding counts octets, not characters, and never cuts a UTF-8 sequence.
 * - TEXT values escape `\`, `;`, `,` and newlines (RFC 5545 3.3.11).
 * - The written text always ends lines with CRLF. It is not copied from the
 *   original: an XML parser turns the CRLF inside `calendar-data` into LF.
 * - DESCRIPTION inside a VALARM belongs to the alarm and is left alone.
 * - Parameters on the property (`DESCRIPTION;LANGUAGE=de`, `ALTREP=…`) stay.
 * - A resource can hold several VEVENTs (a recurring series with changed
 *   occurrences, each with a RECURRENCE-ID); each gets the block.
 */
final class Ics
{
    /**
     * The id at the end of a URL's path: 32 hex digits, or a UUID with its
     * dashes (returned without them), lowercased. The query and the fragment
     * do not count, `?v=…` is often the id of something else.
     *
     * Useful for Notion links (`…/Page-Title-3c8739f36bad809f90ebd3762307e5a1`),
     * but nothing here knows about Notion.
     */
    public static function urlId(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $path = rtrim((string) parse_url(trim($url), PHP_URL_PATH), '/');

        if (preg_match('/(?:^|[^0-9a-f])([0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $path, $m)) {
            return strtolower(str_replace('-', '', $m[1]));
        }

        return null;
    }

    /**
     * Each VEVENT's own properties, decoded, first occurrence of a name wins.
     * Properties of a VALARM inside it are not among them.
     *
     * @return list<array<string, string>>
     */
    public static function events(string $ics): array
    {
        $lines = self::lines($ics);
        $events = [];

        foreach (self::eventProperties($lines) as $indexes) {
            $properties = [];

            foreach ($indexes as $i) {
                $name = self::name($lines[$i]['text']);
                $properties[$name] ??= self::unescape(self::value($lines[$i]['text']));
            }

            $events[] = $properties;
        }

        return $events;
    }

    /** The first value of a property in the first VEVENT, decoded. */
    public static function property(string $ics, string $name): ?string
    {
        return self::events($ics)[0][strtoupper($name)] ?? null;
    }

    /**
     * The calendar text with the block set into the DESCRIPTION of every
     * VEVENT, or null when nothing would change (then nothing is written).
     *
     * @throws MarkerMissingException when a DESCRIPTION holds the start
     *                                marker without the end marker
     */
    public static function withDescriptionBlock(
        string $ics,
        string $block,
        string $markerStart,
        string $markerEnd,
        CarbonInterface $now,
    ): ?string {
        $lines = self::lines($ics);
        $stamp = $now->copy()->utc()->format('Ymd\THis\Z');
        $changed = false;

        foreach (self::eventProperties($lines) as $end => $indexes) {
            $description = null;
            $dtstamp = null;
            $lastModified = null;

            foreach ($indexes as $i) {
                $name = self::name($lines[$i]['text']);
                $description ??= $name === 'DESCRIPTION' ? $i : null;
                $dtstamp ??= $name === 'DTSTAMP' ? $i : null;
                $lastModified ??= $name === 'LAST-MODIFIED' ? $i : null;
            }

            $old = $description === null ? '' : self::unescape(self::value($lines[$description]['text']));
            $new = self::replaceBlock($old, $block, $markerStart, $markerEnd);

            if ($new === $old) {
                continue;
            }

            $changed = true;

            // DESCRIPTION;LANGUAGE=de keeps its parameters, only the value changes.
            $head = $description === null ? 'DESCRIPTION' : self::head($lines[$description]['text']);
            $line = self::line($head.':'.self::escape($new));

            // New properties go before the first sub-component (VALARM),
            // otherwise before END:VEVENT.
            $insertAt = $end;
            for ($j = max($indexes) + 1; $j < $end; $j++) {
                if (str_starts_with(strtoupper($lines[$j]['text']), 'BEGIN:')) {
                    $insertAt = $j;
                    break;
                }
            }

            $before = [];
            foreach ([
                [$description, $line],
                [$dtstamp, self::line('DTSTAMP:'.$stamp)],
                [$lastModified, self::line('LAST-MODIFIED:'.$stamp)],
            ] as [$index, $replacement]) {
                if ($index === null) {
                    $before[] = $replacement;
                } else {
                    $lines[$index] = $replacement;
                }
            }

            if ($before !== []) {
                $lines[$insertAt]['before'] = array_merge($lines[$insertAt]['before'] ?? [], $before);
            }
        }

        return $changed ? self::join($lines) : null;
    }

    /**
     * Replace the block between the markers, or append it. What people wrote
     * before or after it stays.
     *
     * @throws MarkerMissingException
     */
    public static function replaceBlock(string $description, string $block, string $markerStart, string $markerEnd): string
    {
        $wrapped = $markerStart."\n".$block."\n".$markerEnd;

        $start = strpos($description, $markerStart);

        if ($start !== false) {
            $end = strpos($description, $markerEnd, $start + strlen($markerStart));

            // Without the end marker there is no telling where the block ends.
            // Better not to write at all than to cut off what someone typed
            // after it.
            if ($end === false) {
                throw new MarkerMissingException('The end marker is missing from the description; the event was not written.');
            }

            return substr($description, 0, $start).$wrapped.substr($description, $end + strlen($markerEnd));
        }

        return trim($description) === '' ? $wrapped : rtrim($description)."\n\n".$wrapped;
    }

    public static function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $text);
    }

    public static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\(.)/su', fn ($m) => match ($m[1]) {
            'n', 'N' => "\n",
            ',', ';', '\\' => $m[1],
            default => $m[0],
        }, $value);
    }

    /**
     * A logical line folded to at most 75 octets per physical line, without
     * cutting a UTF-8 character.
     *
     * @return list<string>
     */
    public static function fold(string $line): array
    {
        $parts = [];
        $current = '';

        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            if (strlen($current) + strlen($char) > 75) {
                $parts[] = $current;
                $current = ' ';
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * The text as logical lines, each with the physical lines it came from.
     *
     * @return list<array{phys: list<string>, text: string}>
     */
    public static function lines(string $ics): array
    {
        $physical = preg_split('/\r\n|\n|\r/', $ics) ?: [];
        if (end($physical) === '') {
            array_pop($physical);
        }

        $lines = [];
        foreach ($physical as $p) {
            if ($lines !== [] && ($p[0] ?? '') !== '' && ($p[0] === ' ' || $p[0] === "\t")) {
                $last = count($lines) - 1;
                $lines[$last]['phys'][] = $p;
                $lines[$last]['text'] .= substr($p, 1);

                continue;
            }
            $lines[] = ['phys' => [$p], 'text' => $p];
        }

        return $lines;
    }

    /**
     * Per VEVENT the indexes of its own properties (not those of a VALARM),
     * keyed by the index of its END:VEVENT line.
     *
     * @param  list<array{phys: list<string>, text: string}>  $lines
     * @return array<int, list<int>>
     */
    protected static function eventProperties(array $lines): array
    {
        $events = [];
        $stack = [];
        $current = [];

        foreach ($lines as $i => $line) {
            $text = strtoupper(rtrim($line['text']));
            if (str_starts_with($text, 'BEGIN:')) {
                $stack[] = substr($text, 6);
                if (end($stack) === 'VEVENT') {
                    $current = [];
                }

                continue;
            }
            if (str_starts_with($text, 'END:')) {
                if (array_pop($stack) === 'VEVENT' && $current !== []) {
                    $events[$i] = $current;
                }

                continue;
            }
            if (end($stack) === 'VEVENT') {
                $current[] = $i;
            }
        }

        return $events;
    }

    /** @return array{phys: list<string>, text: string} */
    protected static function line(string $text): array
    {
        return ['phys' => self::fold($text), 'text' => $text];
    }

    /**
     * @param  array<int, array{phys: list<string>, text: string, before?: list<array{phys: list<string>, text: string}>}>  $lines
     */
    protected static function join(array $lines): string
    {
        // Always CRLF (RFC 5545), never read off the original: the XML parser
        // has turned the CRLF in calendar-data into LF by the time it is here.
        $physical = [];

        foreach ($lines as $line) {
            foreach ($line['before'] ?? [] as $new) {
                array_push($physical, ...$new['phys']);
            }
            array_push($physical, ...$line['phys']);
        }

        return implode("\r\n", $physical)."\r\n";
    }

    /** Name and parameters, up to the first colon outside quotes. */
    protected static function head(string $line): string
    {
        $quoted = false;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] === '"') {
                $quoted = ! $quoted;
            } elseif ($line[$i] === ':' && ! $quoted) {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }

    protected static function value(string $line): string
    {
        return (string) substr($line, strlen(self::head($line)) + 1);
    }

    protected static function name(string $line): string
    {
        return strtoupper((preg_split('/[;:]/', $line, 2) ?: [''])[0]);
    }
}
