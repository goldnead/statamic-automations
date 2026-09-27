<?php

namespace Goldnead\StatamicAutomations\Support;

use DOMDocument;
use DOMElement;

/**
 * An XML document as a plain array, namespaces stripped, so a dot path like
 * `multistatus.response.0.href` reaches into it the way it reaches into JSON.
 *
 * The rules, in the order they bite:
 *
 * - The root element is the first key: `<multistatus>` gives `['multistatus' => …]`.
 * - Names are local names. `d:href`, `D:href` and `href` in the default
 *   namespace are all `href`; a service that mixes two namespaces with the
 *   same local name under one parent gets them merged into a list.
 * - An element that occurs once is a value, one that repeats is a list. A
 *   path into something that may occur once or several times therefore has
 *   to know which it will be; that is the price of plain dot paths.
 * - An element with neither children nor attributes is its text, trimmed.
 *   Attributes are `@name`, text next to child elements is `#text`.
 * - CDATA counts as text.
 *
 * A document with a DOCTYPE is refused (null), as is one that is not
 * well-formed: nothing here expands entities or fetches anything.
 */
final class XmlToArray
{
    /**
     * @return array<string, mixed>|null null when the text is not usable XML
     */
    public static function parse(string $xml): ?array
    {
        if (trim($xml) === '') {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $dom->doctype !== null || ! $dom->documentElement instanceof DOMElement) {
            return null;
        }

        $root = $dom->documentElement;

        return [(string) $root->localName => self::element($root)];
    }

    protected static function element(DOMElement $element): mixed
    {
        $out = [];
        $repeated = [];
        $text = '';

        foreach ($element->attributes ?? [] as $attribute) {
            $out['@'.$attribute->localName] = (string) $attribute->value;
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $name = (string) $child->localName;
                $value = self::element($child);

                if (! array_key_exists($name, $out)) {
                    $out[$name] = $value;
                } elseif (isset($repeated[$name])) {
                    $out[$name][] = $value;
                } else {
                    $out[$name] = [$out[$name], $value];
                    $repeated[$name] = true;
                }

                continue;
            }

            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $text .= (string) $child->nodeValue;
            }
        }

        if ($out === []) {
            return trim($text);
        }

        if (trim($text) !== '') {
            $out['#text'] = trim($text);
        }

        return $out;
    }
}
