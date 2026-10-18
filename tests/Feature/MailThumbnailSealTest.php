<?php

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Contracts\MailThumbnailRenderer;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Thumbnails\BrowsershotMailRenderer;
use Goldnead\StatamicAutomations\Thumbnails\MailHtmlSanitizer;
use Goldnead\StatamicAutomations\Thumbnails\MailThumbnails;
use Illuminate\Support\Facades\Storage;

/**
 * The browser that photographs a mail is pointed at HTML an editor wrote, on a
 * host that can reach internal services. It must load nothing from outside:
 * not a tracking pixel, not `http://169.254.169.254/…`, not a file on disk.
 * Only `data:`, `cid:` and the app's own host get through; the rest becomes a
 * transparent pixel.
 */
const PIXEL = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

it('removes active content and external loaders', function (string $html, string $gone): void {
    $clean = MailHtmlSanitizer::clean($html, 'app.test');

    expect(strtolower($clean))->not->toContain($gone);
})->with([
    'script' => ['<p>a</p><script>fetch("http://evil.test")</script>', 'script'],
    'iframe' => ['<iframe src="http://evil.test"></iframe>', 'iframe'],
    'object' => ['<object data="http://evil.test/x.swf"></object>', 'evil.test'],
    'embed' => ['<embed src="http://evil.test/x">', 'embed'],
    'stylesheet link' => ['<link rel="stylesheet" href="http://evil.test/a.css">', 'evil.test'],
    'base' => ['<base href="http://evil.test/"><p>x</p>', 'base'],
    'meta refresh' => ['<meta http-equiv="refresh" content="0;url=http://evil.test">', 'refresh'],
    'css import' => ['<style>@import url("http://evil.test/a.css"); p{color:red}</style>', 'evil.test'],
]);

it('replaces a foreign image with a placeholder', function (string $tag): void {
    $clean = MailHtmlSanitizer::clean($tag, 'app.test');

    expect($clean)->toContain(PIXEL)
        ->and($clean)->not->toContain('evil')
        ->and($clean)->not->toContain('169.254');
})->with([
    'http' => ['<img src="http://evil.test/p.gif">'],
    'https single quotes' => ["<img src='https://evil.test/p.gif'>"],
    'unquoted' => ['<img src=http://evil.test/p.gif>'],
    'protocol relative' => ['<img src="//evil.test/p.gif">'],
    'metadata ip' => ['<img src="http://169.254.169.254/latest/meta-data/">'],
    'file scheme' => ['<img src="file:///etc/passwd">'],
    'relative path' => ['<img src="/etc/passwd">'],
    'userinfo trick' => ['<img src="http://app.test@evil.test/p.gif">'],
    'entity encoded' => ['<img src="http&#58;//evil.test/p.gif">'],
    'tab inside scheme' => ["<img src=\"ht\ttp://evil.test/p.gif\">"],
    'css background' => ['<div style="background:url(http://evil.test/p.gif)">x</div>'],
    'css background quoted' => ['<div style="background:url(\'http://evil.test/p.gif\')">x</div>'],
    'style block' => ['<style>td{background-image:url("http://evil.test/p.gif")}</style>'],
    'background attribute' => ['<table background="http://evil.test/p.gif"><tr><td>x</td></tr></table>'],
    'video poster' => ['<video poster="http://evil.test/p.gif"></video>'],
]);

it('drops a srcset that lists a foreign image', function (): void {
    $clean = MailHtmlSanitizer::clean('<img srcset="data:image/gif;base64,AAAA 1x, http://evil.test/b.gif 2x" src="data:image/gif;base64,AAAA">', 'app.test');

    expect($clean)->not->toContain('evil.test')->and($clean)->toContain('src="data:image/gif;base64,AAAA"');
});

it('keeps data uris, cid and the app host, and leaves text alone', function (): void {
    $html = '<p>Hallo http://evil.test im Text</p>'
        .'<img src="data:image/png;base64,AAAA">'
        .'<img src="cid:logo">'
        .'<img src="https://app.test/storage/logo.png">'
        .'<a href="http://evil.test/link">Link</a>';

    $clean = MailHtmlSanitizer::clean($html, 'app.test');

    expect($clean)->toContain('src="data:image/png;base64,AAAA"')
        ->and($clean)->toContain('src="cid:logo"')
        ->and($clean)->toContain('https://app.test/storage/logo.png')
        ->and($clean)->toContain('Hallo http://evil.test im Text')
        ->and($clean)->toContain('<a href="http://evil.test/link">');
});

it('hands the renderer only sanitized html', function (): void {
    Storage::fake('public');
    config()->set('automations.thumbnails.disk', 'public');
    config()->set('app.url', 'https://app.test');

    $seen = new class implements MailThumbnailRenderer
    {
        public array $html = [];

        public function renderHtml(string $html, int $width, int $height): ?string
        {
            $this->html[] = $html;

            return 'PNG';
        }
    };
    $this->app->instance(MailThumbnailRenderer::class, $seen);

    $automation = Automation::create(['name' => 'X', 'handle' => 'x_'.bin2hex(random_bytes(3)), 'enabled' => true]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'mail_1', 'type' => 'send_email',
        'config' => ['subject' => 'S', 'body' => '<p>hi</p><img src="http://evil.test/p.gif"><script>1</script>'],
    ]);

    app(MailThumbnails::class)->render($automation->fresh(['nodes']));

    expect($seen->html)->toHaveCount(1)
        ->and($seen->html[0])->not->toContain('evil.test')
        ->and($seen->html[0])->not->toContain('<script');
});

it('starts the browser cut off from the network, with the sandbox unless told otherwise', function (): void {
    config()->set('app.url', 'https://app.test:8443');

    $args = BrowsershotMailRenderer::chromiumArguments();

    expect($args)->toContain('proxy-server=127.0.0.1:9')
        ->and($args)->toContain('proxy-bypass-list=<-loopback>;app.test');

    config()->set('automations.thumbnails.no_sandbox', true);
    expect(BrowsershotMailRenderer::sandboxDisabled())->toBeTrue();

    config()->set('automations.thumbnails.no_sandbox', false);
    expect(BrowsershotMailRenderer::sandboxDisabled())->toBeFalse();
});

it('reads the browser timeout from config, with a short default', function (): void {
    expect(BrowsershotMailRenderer::timeout())->toBe(20);

    config()->set('automations.thumbnails.timeout', 5);
    expect(BrowsershotMailRenderer::timeout())->toBe(5);
});

it('removes every picture of an automation when it is deleted', function (): void {
    Storage::fake('public');
    config()->set('automations.thumbnails.disk', 'public');
    $this->app->instance(MailThumbnailRenderer::class, new class implements MailThumbnailRenderer
    {
        public function renderHtml(string $html, int $width, int $height): ?string
        {
            return 'PNG';
        }
    });

    $automation = Automation::create(['name' => 'Weg', 'handle' => 'weg_'.bin2hex(random_bytes(3)), 'enabled' => true]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'mail_1', 'type' => 'send_email',
        'config' => ['subject' => 'S', 'body' => '<p>x</p>'],
    ]);
    app(MailThumbnails::class)->render($automation->fresh(['nodes']));
    expect(Storage::disk('public')->allFiles('automations/thumbs'))->toHaveCount(1);

    app(AutomationRepository::class)->delete($automation->fresh());

    expect(Storage::disk('public')->allFiles('automations/thumbs'))->toBe([]);
});
