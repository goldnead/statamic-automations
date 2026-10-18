<?php

namespace Goldnead\StatamicAutomations\Thumbnails;

use Goldnead\StatamicAutomations\Contracts\MailThumbnailRenderer;
use Illuminate\Support\Facades\Log;

/**
 * No browser, so no picture. Says so once per process, as a notice: it is a
 * fact about the host, not a fault.
 */
class NullMailRenderer implements MailThumbnailRenderer
{
    protected static bool $noticed = false;

    public function renderHtml(string $html, int $width, int $height): ?string
    {
        if (! self::$noticed) {
            self::$noticed = true;

            Log::notice('statamic-automations: no mail thumbnail renderer. Install spatie/browsershot and a Chromium, or set automations.thumbnails.chrome_path; mail tiles on the canvas stay off until then.');
        }

        return null;
    }
}
