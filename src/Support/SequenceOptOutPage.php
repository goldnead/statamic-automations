<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * The wording of the series opt-out page for ONE automation.
 *
 * A site words the page per series in config, by the automation's handle:
 *
 *     'sequence_opt_out' => ['pages' => ['welcome' => [
 *         'title' => '…', 'body' => '… :email …', 'button' => '…',
 *         'done' => '…', 'already_out' => '… :email …', 'scope_note' => '…',
 *         'link_label' => '…', 'link_url' => '/start',
 *     ]]]
 *
 * Every key is optional. What is missing, empty or not a string falls back to
 * the text the language file gives, so a site that configures nothing gets the
 * page it always had.
 *
 * ## What may come from config, and what may not
 *
 * Only config. Nothing a visitor typed, and nothing from the URL, flows into the
 * texts: the one visitor-controlled value on the page is the address, and it
 * enters through the `:email` placeholder, which the view escapes like every
 * other output.
 *
 * `link_url` is checked on top of that, because a link is the one place where
 * escaping is not enough (`javascript:` survives it). It is a path on this site
 * (`/…`) or an `https://` URL, and nothing else: no plain http, no protocol
 * relative `//host`, no other scheme, no whitespace or control character. A
 * value that fails shows no link, silently, rather than a broken one.
 */
class SequenceOptOutPage
{
    /** @var array<string, mixed> */
    protected array $texts;

    public function __construct(?string $handle, protected string $email = '')
    {
        // Not config('…pages.'.$handle): a handle may contain a dot, and dot
        // notation would read it as a path.
        $pages = (array) config('automations.sequence_opt_out.pages', []);

        $this->texts = ($handle !== null && is_array($pages[$handle] ?? null)) ? $pages[$handle] : [];
    }

    /**
     * The configured text of a key with `:email` filled in, or the fallback.
     */
    public function text(string $key, string $fallback): string
    {
        $value = $this->texts[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return strtr(trim($value), [':email' => $this->email]);
    }

    /**
     * The link under the page, or null when none is configured or the target
     * is not one we link to.
     *
     * @return array{label: string, url: string}|null
     */
    public function link(string $defaultLabel): ?array
    {
        $url = $this->texts['link_url'] ?? null;

        if (! is_string($url) || ! static::isLinkable($url = trim($url))) {
            return null;
        }

        return ['label' => $this->text('link_label', $defaultLabel), 'url' => $url];
    }

    public static function isLinkable(string $url): bool
    {
        if ($url === '' || preg_match('/[\s\x00-\x1F\x7F\\\\]/', $url) === 1) {
            return false;
        }

        // A path on this site. `//host` is a link to another host.
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        return str_starts_with(strtolower($url), 'https://')
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && ! in_array(parse_url($url, PHP_URL_HOST), [null, false, ''], true);
    }
}
