<?php

namespace Goldnead\StatamicAutomations\Thumbnails;

use Goldnead\StatamicAutomations\Contracts\MailThumbnailRenderer;
use Goldnead\StatamicAutomations\Http\Controllers\EmailTemplatePreviewController;
use Goldnead\StatamicAutomations\Models\Automation;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The picture of a `send_email` node's mail, shown as a tile on the canvas.
 *
 * Nothing is stored on the node. The file name carries an HMAC over automation,
 * node key and a fingerprint of what the mail is made of (template, subject,
 * body), so an edited mail has no picture until it is rendered again and a
 * save from the editor cannot overwrite one. `automations/thumbs/{uuid}/` holds
 * one folder per automation. The disk is public and a disabled automation's
 * mails are not, hence the HMAC: not a secret link, but not a listing either.
 *
 * Rendered from sample data, like the preview in the node stack, never from a
 * real contact. **Known gap:** the template's content is not part of the
 * fingerprint, only its slug. Editing a template does not refresh the tile
 * until the node itself changes; there is no force command yet.
 */
class MailThumbnails
{
    public const TYPE = 'send_email';

    public static function enabled(): bool
    {
        return (bool) config('automations.thumbnails.enabled', true);
    }

    /** Whether the bound renderer can produce a picture at all. */
    public static function available(): bool
    {
        return ! app(MailThumbnailRenderer::class) instanceof NullMailRenderer;
    }

    public static function detectRenderer(): MailThumbnailRenderer
    {
        if (BrowsershotMailRenderer::available()) {
            return new BrowsershotMailRenderer((string) BrowsershotMailRenderer::chromium());
        }

        return new NullMailRenderer;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('automations.thumbnails.disk', 'public'));
    }

    public static function width(): int
    {
        return max(1, (int) config('automations.thumbnails.width', 640));
    }

    public static function height(): int
    {
        return max(1, (int) config('automations.thumbnails.height', 400));
    }

    /** @param  array<string, mixed>  $config */
    public static function fingerprint(array $config): string
    {
        return sha1(json_encode([
            'template' => (string) ($config['template'] ?? ''),
            'subject' => (string) ($config['subject'] ?? ''),
            'body' => (string) ($config['body'] ?? ''),
        ]) ?: '');
    }

    /** @param  array<string, mixed>  $config */
    public static function path(Automation $automation, string $nodeKey, array $config): string
    {
        $mac = substr(hash_hmac('sha256', $automation->uuid.'/'.$nodeKey.'/'.self::fingerprint($config), (string) config('app.key')), 0, 16);

        return self::directory($automation).'/'.$nodeKey.'-'.$mac.'.png';
    }

    /** The host the mail's own images may come from: the app's. */
    public static function appHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /** Delete every picture of an automation, for when the automation itself goes. */
    public static function forget(Automation $automation): void
    {
        if ((string) $automation->uuid === '') {
            return;
        }

        try {
            self::disk()->deleteDirectory(self::directory($automation));
        } catch (\Throwable $e) {
            Log::warning('statamic-automations: could not delete mail thumbnails of '.$automation->handle.': '.$e->getMessage());
        }
    }

    public static function directory(Automation $automation): string
    {
        return 'automations/thumbs/'.$automation->uuid;
    }

    /**
     * The public URL of a node's current picture, or null when it has none
     * (not a mail, switched off, not rendered yet, or the mail changed since).
     *
     * @param  array<string, mixed>|null  $config
     */
    public static function url(Automation $automation, string $type, string $nodeKey, ?array $config): ?string
    {
        if ($type !== self::TYPE || ! self::enabled()) {
            return null;
        }

        $path = self::path($automation, $nodeKey, $config ?? []);

        return self::disk()->exists($path) ? self::disk()->url($path) : null;
    }

    /**
     * Photograph every mail node whose picture is missing or out of date.
     * Returns how many pictures were written.
     */
    public function render(Automation $automation): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $automation->loadMissing('nodes');
        $renderer = app(MailThumbnailRenderer::class);
        $written = 0;
        $current = [];

        foreach ($automation->nodes as $node) {
            if ($node->type !== self::TYPE) {
                continue;
            }

            $config = is_array($node->config) ? $node->config : [];
            $path = self::path($automation, $node->node_key, $config);
            $current[] = $path;

            if (self::disk()->exists($path)) {
                continue;
            }

            try {
                $html = app(EmailTemplatePreviewController::class)->htmlForConfig($config);

                if ($html === null) {
                    continue;
                }

                $html = MailHtmlSanitizer::clean($html, self::appHost());

                $png = $renderer->renderHtml($html, self::width(), self::height());
            } catch (\Throwable $e) {
                Log::warning('statamic-automations: mail thumbnail for '.$automation->handle.'/'.$node->node_key.' failed: '.$e->getMessage());

                continue;
            }

            if ($png === null) {
                continue;
            }

            self::disk()->put($path, $png);
            $written++;

        }

        // Pictures of mails that changed or nodes that are gone: not served any
        // more (the name carries the hash), and not kept on a public disk either.
        foreach (self::disk()->files(self::directory($automation)) as $file) {
            if (! in_array($file, $current, true)) {
                self::disk()->delete($file);
            }
        }

        return $written;
    }
}
