<?php

namespace Goldnead\StatamicAutomations\Thumbnails;

use Goldnead\StatamicAutomations\Contracts\MailThumbnailRenderer;
use Spatie\Browsershot\Browsershot;

/**
 * A real browser, when there is one.
 *
 * `spatie/browsershot` is a `suggest`, not a `require`: the class is only
 * touched behind a `class_exists` guard. The sandbox stays on unless Chromium
 * cannot have it (running as root, or `no_sandbox` set). The HTML arrives
 * already reduced by {@see MailHtmlSanitizer}; JavaScript is off and the
 * network is cut, so the browser draws the mail and fetches nothing.
 */
class BrowsershotMailRenderer implements MailThumbnailRenderer
{
    /** @var list<string> */
    protected const NAMES = ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'chrome'];

    /** @var list<string> */
    protected const MAC_PATHS = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
    ];

    public function __construct(protected string $chromePath) {}

    public static function available(): bool
    {
        return class_exists(Browsershot::class) && self::chromium() !== null;
    }

    /** The configured binary wins; if it is named but not executable the answer is nothing. */
    public static function chromium(): ?string
    {
        $configured = config('automations.thumbnails.chrome_path');

        if (is_string($configured) && $configured !== '') {
            return is_executable($configured) ? $configured : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            foreach (self::NAMES as $name) {
                $candidate = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;

                if ($dir !== '' && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        foreach (self::MAC_PATHS as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Chromium flags that cut the browser off from the network. The mail is
     * loaded from a local file, so it needs none; a dead proxy catches every
     * http(s) request, IP literals included, and only the app's own host is
     * let past it (`proxy-bypass-list`), for images the mail serves from there.
     *
     * @return list<string>
     */
    public static function chromiumArguments(): array
    {
        // `<-loopback>` removes Chromium's implicit "never proxy localhost", so
        // a service on 127.0.0.1 is as unreachable as one on the internet.
        $bypass = '<-loopback>';
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            $bypass .= ';'.$host;
        }

        return ['proxy-server=127.0.0.1:9', 'proxy-bypass-list='.$bypass];
    }

    /**
     * Whether Chromium runs without its sandbox. `automations.thumbnails.no_sandbox`
     * wins; left null, the sandbox stays on except as root, where Chromium
     * refuses to start with it (the usual container case).
     */
    public static function sandboxDisabled(): bool
    {
        $configured = config('automations.thumbnails.no_sandbox');

        if ($configured !== null) {
            return (bool) $configured;
        }

        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    public static function timeout(): int
    {
        return max(1, (int) config('automations.thumbnails.timeout', 20));
    }

    public function renderHtml(string $html, int $width, int $height): ?string
    {
        // Browsershot is a `suggest`, so analysis does not see the class.
        $shot = Browsershot::html($html) // @phpstan-ignore class.notFound
            ->setChromePath($this->chromePath)
            ->windowSize($width, $height)
            ->deviceScaleFactor(1)
            ->disableJavascript()
            ->addChromiumArguments(self::chromiumArguments())
            ->timeout(self::timeout())
            ->waitUntilNetworkIdle(false);

        if (self::sandboxDisabled()) {
            $shot->noSandbox();
        }

        return $shot->screenshot();
    }
}
