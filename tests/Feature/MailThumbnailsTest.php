<?php

use Goldnead\StatamicAutomations\Contracts\MailThumbnailRenderer;
use Goldnead\StatamicAutomations\Http\Resources\AutomationResource;
use Goldnead\StatamicAutomations\Jobs\RenderMailThumbnails;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Thumbnails\MailThumbnails;
use Goldnead\StatamicAutomations\Thumbnails\NullMailRenderer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * A picture of the mail on the `send_email` node, as the canvas tile.
 *
 * The picture is not stored on the node: its name carries a hash of what the
 * mail is made of, so an edited mail simply has no picture until the next
 * render, and nothing the editor sends back can overwrite it.
 */
class FakeMailRenderer implements MailThumbnailRenderer
{
    public array $seen = [];

    public function renderHtml(string $html, int $width, int $height): ?string
    {
        $this->seen[] = $html;

        return 'PNG:'.$html;
    }
}

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('automations.thumbnails.enabled', true);
    config()->set('automations.thumbnails.disk', 'public');

    $this->renderer = new FakeMailRenderer;
    $this->app->instance(MailThumbnailRenderer::class, $this->renderer);

    $this->automation = Automation::create([
        'name' => 'Willkommen',
        'handle' => 'willkommen_'.bin2hex(random_bytes(4)),
        'enabled' => true,
    ]);

    $this->mail = function (string $key, string $subject, string $body, string $type = 'send_email') {
        return AutomationNode::create([
            'automation_id' => $this->automation->id,
            'node_key' => $key,
            'type' => $type,
            'config' => ['subject' => $subject, 'body' => $body],
        ]);
    };
});

it('renders a picture for each send_email node and exposes it on the node', function (): void {
    ($this->mail)('mail_1', 'Hallo {{ subscriber.first_name }}', '<p>Hi {{ subscriber.first_name }}</p>');
    ($this->mail)('delay_1', 'x', 'x', 'delay');

    $count = app(MailThumbnails::class)->render($this->automation->fresh(['nodes']));

    expect($count)->toBe(1)
        ->and($this->renderer->seen)->toHaveCount(1)
        ->and($this->renderer->seen[0])->toContain('Hi Max');

    $nodes = (new AutomationResource($this->automation->fresh(['nodes', 'edges'])))->toArray(request())['nodes'];
    $byKey = collect($nodes)->keyBy('node_key');

    expect($byKey['mail_1']['thumbnail'])->toBeString()->toContain('/automations/thumbs/')
        ->and($byKey['delay_1']['thumbnail'])->toBeNull();
});

it('does not render again while the mail is unchanged, and again once it changed', function (): void {
    $node = ($this->mail)('mail_1', 'S', '<p>eins</p>');
    $service = app(MailThumbnails::class);

    $service->render($this->automation->fresh(['nodes']));
    $service->render($this->automation->fresh(['nodes']));

    expect($this->renderer->seen)->toHaveCount(1);

    $node->update(['config' => ['subject' => 'S', 'body' => '<p>zwei</p>']]);
    $first = Storage::disk('public')->allFiles('automations/thumbs');

    // The edited mail has no picture until it is rendered.
    $thumb = (new AutomationResource($this->automation->fresh(['nodes', 'edges'])))->toArray(request())['nodes'][0]['thumbnail'];
    expect($thumb)->toBeNull();

    $service->render($this->automation->fresh(['nodes']));

    expect($this->renderer->seen)->toHaveCount(2)
        ->and(Storage::disk('public')->allFiles('automations/thumbs'))->toHaveCount(1)
        ->and(Storage::disk('public')->allFiles('automations/thumbs'))->not->toBe($first);
});

it('does nothing and does not fail without a renderer', function (): void {
    $this->app->instance(MailThumbnailRenderer::class, new NullMailRenderer);
    ($this->mail)('mail_1', 'S', '<p>x</p>');

    $count = app(MailThumbnails::class)->render($this->automation->fresh(['nodes']));

    expect($count)->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(MailThumbnails::available())->toBeFalse();

    $node = (new AutomationResource($this->automation->fresh(['nodes', 'edges'])))->toArray(request())['nodes'][0];
    expect($node['thumbnail'])->toBeNull();
});

it('is silent when thumbnails are switched off', function (): void {
    config()->set('automations.thumbnails.enabled', false);
    ($this->mail)('mail_1', 'S', '<p>x</p>');

    expect(app(MailThumbnails::class)->render($this->automation->fresh(['nodes'])))->toBe(0)
        ->and($this->renderer->seen)->toBe([]);
});

it('skips a mail that has neither text nor template', function (): void {
    ($this->mail)('mail_1', '', '');

    expect(app(MailThumbnails::class)->render($this->automation->fresh(['nodes'])))->toBe(0);
});

it('hands the builder page the tile URL on the mail node', function (): void {
    ($this->mail)('mail_1', 'S', '<p>x</p>');
    app(MailThumbnails::class)->render($this->automation->fresh(['nodes']));
    $this->actingAsSuperUser();

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.automations.edit', $this->automation))
        ->assertOk();

    $nodes = collect($response->json('props.automation.nodes'))->keyBy('node_key');

    expect($nodes['mail_1']['thumbnail'])->toBeString()->toContain('/automations/thumbs/');
});

it('queues the render after a graph save, and only when a renderer exists', function (): void {
    Queue::fake();
    $this->actingAsSuperUser();

    $url = cp_route('statamic-automations.api.automations.update', ['automationFlow' => $this->automation->id]);
    $graph = ['nodes' => [['node_key' => 'mail_1', 'type' => 'send_email', 'config' => ['subject' => 'S', 'body' => 'x']]], 'edges' => []];

    $this->patchJson($url, $graph)->assertOk();
    Queue::assertPushed(RenderMailThumbnails::class, fn ($job) => $job->automationId === $this->automation->id);

    Queue::fake();
    $this->app->instance(MailThumbnailRenderer::class, new NullMailRenderer);
    $this->patchJson($url, $graph)->assertOk();
    Queue::assertNotPushed(RenderMailThumbnails::class);
});

it('renders through the queued job by automation id', function (): void {
    ($this->mail)('mail_1', 'S', '<p>x</p>');

    (new RenderMailThumbnails($this->automation->id))->handle(app(MailThumbnails::class));

    expect($this->renderer->seen)->toHaveCount(1);

    // A deleted automation is a job that finds nothing.
    (new RenderMailThumbnails(999999))->handle(app(MailThumbnails::class));
    expect($this->renderer->seen)->toHaveCount(1);
});
