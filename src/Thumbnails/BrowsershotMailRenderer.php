<?php

namespace Goldnead\StatamicAutomations\Thumbnails;

use Goldnead\StatamicAutomations\Contracts\MailThumbnailRenderer;
use Spatie\Browsershot\Browsershot;

/**
 * A real browser, when there is one.
 *
 * `spatie/browsershot` is a `suggest`, not a `require`: the class is only
 * touched behind a `class_exists` guard. `noSandbox()` because the usual place
 * this runs is a container as root, where Chromium refuses to start sandboxed.
 * The mail is rendered from a string, so the browser never fetches anything
 * but the mail's own images.
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

    public function renderHtml(string $html, int $width, int $height): ?string
    {
        // Browsershot is a `suggest`, so analysis does not see the class.
        return Browsershot::html($html) // @phpstan-ignore class.notFound
            ->setChromePath($this->chromePath)
            ->windowSize($width, $height)
            ->deviceScaleFactor(1)
            ->noSandbox()
            ->timeout(30)
            ->waitUntilNetworkIdle(false)
            ->screenshot();
    }
}
