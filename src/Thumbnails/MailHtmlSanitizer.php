<?php

namespace Goldnead\StatamicAutomations\Thumbnails;

/**
 * Makes a mail's HTML safe to hand to a browser that sits on a server.
 *
 * The browser photographs what an editor wrote. It must not fetch anything
 * for it: a tracking pixel would fire on every save, and `http://169.254.…`
 * or a `file://` path would turn the picture into a window onto the host.
 * So the HTML is reduced *before* the browser sees it (the browser is also
 * cut off from the network, see {@see BrowsershotMailRenderer}, but an IP
 * literal and a local file do not need a resolver, hence both layers).
 *
 * An allow-list, not a block-list: a loadable URL survives only if it is
 * `data:`, `cid:` or on the app's own host. Everything else, including
 * relative paths and protocol-relative `//host/…`, becomes a transparent
 * pixel. Scripts, frames, plugins, stylesheets and `<base>` go entirely.
 * Links (`href`) are left alone: nothing loads from them.
 *
 * Regex over tags rather than a DOM parse on purpose: `loadHTML` rewrites
 * mail markup (tables, conditional comments) and the picture would no longer
 * show the mail.
 */
class MailHtmlSanitizer
{
    public const PIXEL = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

    /** Attributes whose value the browser fetches by itself. */
    protected const LOADING_ATTRIBUTES = ['src', 'background', 'poster', 'data', 'lowsrc', 'dynsrc', 'longdesc', 'icon', 'manifest'];

    public static function clean(string $html, ?string $allowedHost): string
    {
        // Containers first, with their content: a script's body is not markup.
        $html = (string) preg_replace('#<(script|iframe|object|applet|frameset|frame|noscript|svg|math)\b[^>]*>.*?</\1\s*>#is', '', $html);
        // Whatever is left of them (unclosed, self-closing), and tags that load or redirect.
        $html = (string) preg_replace('#<(script|iframe|object|applet|embed|frame|frameset|link|base|param|source|track|svg|math|image|use)\b[^>]*>#is', '', $html);
        $html = (string) preg_replace('#<meta\b[^>]*http-equiv\s*=\s*["\']?\s*refresh[^>]*>#is', '', $html);

        // CSS escapes (`u\72l(`) would hide a url() from the checks below. Real
        // mail CSS has none, so a style block or attribute that contains one is
        // dropped whole. image-set() takes a bare string instead of url(), so
        // it goes too.
        $html = (string) preg_replace_callback(
            '#<style\b[^>]*>.*?</style\s*>|\bstyle\s*=\s*("[^"]*"|\'[^\']*\')#is',
            fn (array $m): string => str_contains($m[0], '\\') ? '' : $m[0],
            $html,
        );
        $html = (string) preg_replace('#(?:-webkit-)?image-set\s*\([^)]*\)#i', 'none', $html);

        // CSS: imports go, every url() is checked, wherever it sits (style block or attribute).
        $html = (string) preg_replace('#@import\b[^;{}]*;?#i', '', $html);
        $html = (string) preg_replace_callback(
            '#url\(\s*(["\']?)(.*?)\1\s*\)#is',
            fn (array $m): string => self::allowed($m[2], $allowedHost) ? $m[0] : 'url('.self::PIXEL.')',
            $html,
        );

        // Attributes, only inside tags so running text is never touched.
        return (string) preg_replace_callback('#<[a-z][^>]*>#is', function (array $tag) use ($allowedHost): string {
            $attributes = implode('|', array_map('preg_quote', self::LOADING_ATTRIBUTES));

            $out = (string) preg_replace_callback(
                '#\b('.$attributes.')\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is',
                function (array $m) use ($allowedHost): string {
                    $value = trim($m[2], '"\'');

                    return self::allowed($value, $allowedHost) ? $m[0] : $m[1].'="'.self::PIXEL.'"';
                },
                $tag[0],
            );

            // srcset lists several URLs; keep it only if every one is allowed.
            return (string) preg_replace_callback(
                '#\bsrcset\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is',
                function (array $m) use ($allowedHost): string {
                    foreach (explode(',', trim($m[1], '"\'')) as $candidate) {
                        $url = preg_split('/\s+/', trim($candidate))[0] ?? '';

                        if ($url !== '' && ! self::allowed($url, $allowedHost)) {
                            return '';
                        }
                    }

                    return $m[0];
                },
                $out,
            );
        }, $html);
    }

    /**
     * Whether a browser may fetch this URL: `data:`, `cid:`, an anchor, or
     * http(s) on the app's own host. Entities are decoded and control
     * characters dropped first, because that is how a browser reads it.
     */
    protected static function allowed(string $url, ?string $allowedHost): bool
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
        $url = (string) preg_replace('/[\x00-\x20]+/', '', $url);

        if ($url === '' || str_starts_with($url, '#')) {
            return true;
        }

        if (preg_match('#^(data:|cid:)#i', $url) === 1) {
            return true;
        }

        if ($allowedHost === null || $allowedHost === '' || preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $allowedHost) === 0;
    }
}
