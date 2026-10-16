<?php

use Goldnead\StatamicAutomations\Automations;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Nodes\Actions\FetchAssetFromUrlAction;
use Goldnead\StatamicAutomations\ServiceProvider;
use Goldnead\StatamicAutomations\Support\HostGuard;
use Goldnead\StatamicAutomations\Tests\Support\FakeRequest;
use Goldnead\StatamicAutomations\Tests\Support\FakeWeb;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

require_once __DIR__.'/../Support/FakeWeb.php';

const FETCH_SECRET = 'fetch-token-7f3a91c2';

beforeEach(function (): void {
    $this->hostsSeen = [];
    app(HostGuard::class)->resolveUsing(function (string $host) {
        $this->hostsSeen[] = $host;

        return $host === 'private.example.test' ? ['10.0.0.5'] : ['93.184.216.34'];
    });

    $this->roots = [];

    foreach (['media', 'vault'] as $handle) {
        $this->roots[$handle] = sys_get_temp_dir().'/fetch-test-'.$handle.'-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->roots[$handle]);
        config(["filesystems.disks.fetch_{$handle}" => ['driver' => 'local', 'root' => $this->roots[$handle], 'url' => "/{$handle}"]]);
        AssetContainer::make($handle)->disk("fetch_{$handle}")->title(ucfirst($handle))->save();
    }
});

afterEach(function (): void {
    foreach ($this->roots as $root) {
        File::deleteDirectory($root);
    }
});

function fetchAction(): FetchAssetFromUrlAction
{
    return app(FetchAssetFromUrlAction::class);
}

function fetchConfig(array $overrides = []): array
{
    return array_merge(['url' => 'https://files.example.test/rec/call.mp4', 'container' => 'media'], $overrides);
}

/** Every file below a container root, relative, dotfiles and .meta included. */
function filesIn(string $root): array
{
    return collect(File::allFiles($root, true))
        ->map(fn ($file) => str_replace('\\', '/', $file->getRelativePathname()))
        ->sort()
        ->values()
        ->all();
}

function fetchConnection(array $overrides = []): AutomationConnection
{
    return AutomationConnection::create(array_merge([
        'handle' => 'zoomdl',
        'name' => 'Zoom download',
        'base_url' => 'https://files.example.test',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => FETCH_SECRET],
    ], $overrides));
}

it('is a built-in Statamic action that supports test mode', function (): void {
    expect(FetchAssetFromUrlAction::handle())->toBe('asset.fetch_from_url');
    expect(FetchAssetFromUrlAction::group())->toBe('Statamic');
    expect(FetchAssetFromUrlAction::supportsTestMode())->toBeTrue();
    expect(app('automations')->isBuiltIn('asset.fetch_from_url'))->toBeTrue();
});

it('declares the schema and a flat output schema', function (): void {
    $schema = collect(FetchAssetFromUrlAction::schema())->keyBy('handle');

    expect($schema->keys()->all())->toBe(['url', 'connection', 'container', 'folder', 'filename', 'max_size_mb', 'overwrite']);
    expect($schema['url']['required'])->toBeTrue()->and($schema['url']['tokenable'])->toBeTrue();
    expect($schema['connection']['options_source'])->toBe('connections');
    expect($schema['container']['options_source'])->toBe('statamic.asset_containers');
    expect($schema['overwrite']['type'])->toBe('toggle')->and($schema['overwrite']['default'])->toBeFalse();
    expect(FetchAssetFromUrlAction::outputSchema())->toBe([
        'asset_id' => 'string', 'path' => 'string', 'size' => 'integer', 'mime_type' => 'string',
    ]);
});

it('previews in test mode without touching network, connections, container or disk', function (): void {
    FakeWeb::fake();

    $result = fetchAction()->execute(AutomationContext::make([], true), fetchConfig(['connection' => 'nowhere', 'container' => 'does-not-exist']));

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['preview']['filename'])->toBe('call.mp4');
    FakeWeb::assertNothingSent();
    expect($this->hostsSeen)->toBe([]);
    expect(filesIn($this->roots['media']))->toBe([]);
});

it('reports an empty URL in test mode instead of failing', function (): void {
    $result = fetchAction()->execute(AutomationContext::make([], true), fetchConfig(['url' => '']));

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['missing_inputs'])->toBe(['url']);
});

it('still validates locally in test mode', function (): void {
    $result = fetchAction()->execute(AutomationContext::make([], true), fetchConfig(['folder' => '../x']));

    expect($result->isFailed())->toBeTrue();
});

it('downloads into the container and returns the four outputs', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::response('hello recording', 200, ['Content-Type' => 'video/mp4'])]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['folder' => 'zoom/2026']));

    expect($result->isSuccess())->toBeTrue();
    expect($result->output)->toMatchArray(['path' => 'zoom/2026/call.mp4', 'size' => 15]);
    expect($result->output['asset_id'])->toBe('media::zoom/2026/call.mp4');
    expect($result->output['mime_type'])->not->toBe('');
    expect(file_get_contents($this->roots['media'].'/zoom/2026/call.mp4'))->toBe('hello recording');
    expect(file_exists($this->roots['media'].'/zoom/2026/.meta/call.mp4.yaml'))->toBeTrue();
});

it('works the same on a second container that is not the default', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::response('vault data', 200)]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['container' => 'vault', 'filename' => 'secret.bin']));

    expect($result->isSuccess())->toBeTrue();
    expect(file_exists($this->roots['vault'].'/secret.bin'))->toBeTrue();
    expect(Asset::find('vault::secret.bin'))->not->toBeNull();
});

it('uses the basename of the original URL, not of the redirect target', function (): void {
    FakeWeb::fake([
        'files.example.test/start/original.txt' => FakeWeb::response('', 302, ['Location' => 'https://files.example.test/final/other.bin']),
        'files.example.test/final/other.bin' => FakeWeb::response('x', 200),
    ]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['url' => 'https://files.example.test/start/original.txt']));

    expect($result->output['path'])->toBe('original.txt');
});

it('reserves the first free name when overwrite is off', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::sequence()->push('one')->push('two')->push('three')]);

    $paths = collect(range(1, 3))->map(fn () => fetchAction()->execute(AutomationContext::make(), fetchConfig())->output['path'])->all();

    expect($paths)->toBe(['call.mp4', 'call-1.mp4', 'call-2.mp4']);
    expect(file_get_contents($this->roots['media'].'/call.mp4'))->toBe('one');
});

it('never lets a run asking for another name end up on a path that was just taken', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::sequence()->push('one')->push('two')->push('three')]);

    $paths = [
        fetchAction()->execute(AutomationContext::make(), fetchConfig())->output['path'],
        fetchAction()->execute(AutomationContext::make(), fetchConfig())->output['path'],
        fetchAction()->execute(AutomationContext::make(), fetchConfig(['filename' => 'call-1.mp4']))->output['path'],
    ];

    expect($paths)->toBe(['call.mp4', 'call-1.mp4', 'call-1-1.mp4']);
    expect(file_get_contents($this->roots['media'].'/call-1.mp4'))->toBe('two');
});

it('serialises runs that ask for different names on one container lock', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::response('x')]);
    config(['cache.default' => 'array']);

    $impatient = new class extends FetchAssetFromUrlAction
    {
        protected int $lockWait = 0;
    };

    $lock = cache()->lock('statamic-automations:asset-fetch:media', 60);
    expect($lock->get())->toBeTrue();

    // Two different wanted names, same container: both wait on the one lock.
    foreach (['call.mp4', 'call-1.mp4'] as $name) {
        $result = $impatient->execute(AutomationContext::make(), fetchConfig(['filename' => $name]));

        expect($result->isFailed())->toBeTrue();
        expect($result->error)->toContain('still running');
    }

    $lock->release();
    expect($impatient->execute(AutomationContext::make(), fetchConfig(['filename' => 'call-1.mp4']))->isSuccess())->toBeTrue();
    expect(filesIn($this->roots['media']))->toBe(['.meta/call-1.mp4.yaml', 'call-1.mp4']);
});

it('replaces the file when overwrite is on', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::sequence()->push('old')->push('newer')]);

    fetchAction()->execute(AutomationContext::make(), fetchConfig());
    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['overwrite' => true]));

    expect($result->output)->toMatchArray(['path' => 'call.mp4', 'size' => 5]);
    expect(file_get_contents($this->roots['media'].'/call.mp4'))->toBe('newer');
    expect(filesIn($this->roots['media']))->toBe(['.meta/call.mp4.yaml', 'call.mp4']);
});

it('restores an overwritten file and its meta when the new download fails', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::sequence()->push('old content')->push('too big for the limit', 200)]);

    fetchAction()->execute(AutomationContext::make(), fetchConfig());
    $meta = file_get_contents($this->roots['media'].'/.meta/call.mp4.yaml');

    config(['automations.assets.fetch.max_size_mb' => 0.00001]);
    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['overwrite' => true]));

    expect($result->isFailed())->toBeTrue();
    expect(file_get_contents($this->roots['media'].'/call.mp4'))->toBe('old content');
    expect(file_get_contents($this->roots['media'].'/.meta/call.mp4.yaml'))->toBe($meta);
    expect(filesIn($this->roots['media']))->toBe(['.meta/call.mp4.yaml', 'call.mp4']);
});

/** Fails only inside store(), after the old file has been overwritten. */
function failingAfterOverwrite(bool $restoreWorks): FetchAssetFromUrlAction
{
    return $restoreWorks
        ? new class extends FetchAssetFromUrlAction
        {
            protected function verifyStored($disk, $asset): void
            {
                throw new RuntimeException('boom after overwrite');
            }
        }
    : new class extends FetchAssetFromUrlAction
    {
        protected function verifyStored($disk, $asset): void
        {
            throw new RuntimeException('boom after overwrite');
        }

        protected function restoreFile($disk, string $backup, string $path): bool
        {
            return false;
        }
    };
}

it('rolls back a failure inside store() after the overwrite', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::sequence()->push('old content')->push('new content')]);

    fetchAction()->execute(AutomationContext::make(), fetchConfig());
    $meta = file_get_contents($this->roots['media'].'/.meta/call.mp4.yaml');

    $result = failingAfterOverwrite(true)->execute(AutomationContext::make(), fetchConfig(['overwrite' => true]));

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toContain('boom after overwrite');
    expect(file_get_contents($this->roots['media'].'/call.mp4'))->toBe('old content');
    expect(file_get_contents($this->roots['media'].'/.meta/call.mp4.yaml'))->toBe($meta);
    expect(filesIn($this->roots['media']))->toBe(['.meta/call.mp4.yaml', 'call.mp4']);
});

it('does not roll back a successful overwrite when deleting a backup throws', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::sequence()->push('old content')->push('new content')]);

    fetchAction()->execute(AutomationContext::make(), fetchConfig());

    $action = new class extends FetchAssetFromUrlAction
    {
        public int $deletes = 0;

        protected function deleteBackup($disk, string $backup): void
        {
            // The first backup goes, the second one throws.
            if (++$this->deletes === 2) {
                throw new RuntimeException('cannot delete');
            }

            $disk->delete($backup);
        }
    };

    $result = $action->execute(AutomationContext::make(), fetchConfig(['overwrite' => true]));

    expect($result->isSuccess())->toBeTrue();
    expect($action->deletes)->toBe(2);
    expect(file_get_contents($this->roots['media'].'/call.mp4'))->toBe('new content');
});

it('keeps the backups and names them when the restore fails', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::sequence()->push('old content')->push('new content')]);

    fetchAction()->execute(AutomationContext::make(), fetchConfig());

    $result = failingAfterOverwrite(false)->execute(AutomationContext::make(), fetchConfig(['overwrite' => true]));

    $backups = array_values(array_filter(filesIn($this->roots['media']), fn ($file) => str_contains($file, '.fetch-backup-')));

    expect($result->isFailed())->toBeTrue();
    expect($backups)->toHaveCount(2);
    expect($result->error)->toContain('backup is kept');

    foreach ($backups as $backup) {
        expect($result->error)->toContain(basename($backup));
    }

    $contents = collect($backups)->map(fn ($file) => file_get_contents($this->roots['media'].'/'.$file))->all();
    expect($contents)->toContain('old content');
});

it('leaves no temporary file when the file cannot be opened', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::response('x', 404)]);
    $before = glob(sys_get_temp_dir().'/autofetch_*');

    fetchAction()->execute(AutomationContext::make(), fetchConfig());

    expect(glob(sys_get_temp_dir().'/autofetch_*'))->toBe($before);
});

it('rejects unsafe folders, names and limits before anything happens', function (array $override, string $needle): void {
    FakeWeb::fake();

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig($override));

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toContain($needle);
    FakeWeb::assertNothingSent();
    expect(filesIn($this->roots['media']))->toBe([]);
})->with([
    'parent folder' => [['folder' => 'a/../b'], "'..'"],
    'encoded parent' => [['folder' => 'a/%2e%2e/b'], "'..'"],
    'double encoded parent' => [['folder' => 'a/%252e%252e/b'], "'..'"],
    'absolute folder' => [['folder' => '/etc'], 'relative'],
    'dot name' => [['filename' => '..'], "'..'"],
    'path in name' => [['filename' => 'a/b.mp4'], 'path'],
    'zero limit' => [['max_size_mb' => 0], 'greater than zero'],
    'negative limit' => [['max_size_mb' => -3], 'greater than zero'],
    'infinite limit' => [['max_size_mb' => 'INF'], 'greater than zero'],
    'bad scheme' => [['url' => 'ftp://files.example.test/a.mp4'], 'http'],
    'bad container' => [['container' => '../x'], 'container'],
    'no name in URL' => [['url' => 'https://files.example.test/'], 'file name'],
]);

it('rejects a zero or invalid timeout from config', function (): void {
    config(['automations.assets.fetch.timeout' => 0]);

    expect(fetchAction()->execute(AutomationContext::make(), fetchConfig())->isFailed())->toBeTrue();
});

it('fails clearly for a missing container or connection', function (): void {
    expect(fetchAction()->execute(AutomationContext::make(), fetchConfig(['container' => 'ghost']))->error)->toContain('ghost');
    expect(fetchAction()->execute(AutomationContext::make(), fetchConfig(['connection' => 'ghost']))->error)->toContain('ghost');
});

it('fails on non-2xx answers and leaves nothing', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::response('nope', 404)]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig());

    expect($result->error)->toContain('HTTP 404');
    expect(filesIn($this->roots['media']))->toBe([]);
});

it('refuses a Content-Length over the limit before storing', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::response('x', 200, ['Content-Length' => (string) (5 * 1048576)])]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['max_size_mb' => 1]));

    expect($result->error)->toContain('limit');
    expect(filesIn($this->roots['media']))->toBe([]);
});

it('sends the connection credential and masks it everywhere on failure', function (): void {
    fetchConnection();
    FakeWeb::fake(['files.example.test/*' => FakeWeb::response('Bearer '.FETCH_SECRET.' denied', 403)]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['connection' => 'zoomdl']));

    expect(FakeWeb::recorded()->map(fn (FakeRequest $request) => $request->header('Authorization'))->all())->toBe([['Bearer '.FETCH_SECRET]]);
    expect($result->isFailed())->toBeTrue();
    expect(json_encode($result))->not->toContain(FETCH_SECRET);
});

it('sends no credential to a public host that is not the connection\'s own', function (): void {
    fetchConnection();
    FakeWeb::fake(['*' => FakeWeb::response('data')]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['connection' => 'zoomdl', 'url' => 'https://evil.example.test/rec/call.mp4']));

    expect($result->isFailed())->toBeTrue();
    FakeWeb::assertNothingSent();
    expect(filesIn($this->roots['media']))->toBe([]);
});

it('does not fetch an OAuth2 token for a foreign host either', function (): void {
    fetchConnection([
        'handle' => 'zoomoauth',
        'auth_type' => 'oauth2_client_credentials',
        'auth_config' => ['token_url' => 'https://auth.example.test/token', 'client_id' => 'cid', 'client_secret' => 'secret-aa11bb22'],
    ]);
    FakeWeb::fake(['*' => FakeWeb::response('data')]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['connection' => 'zoomoauth', 'url' => 'https://evil.example.test/x.bin']));

    expect($result->isFailed())->toBeTrue();
    FakeWeb::assertNothingSent();
});

it('is not registered when its flag is off', function (): void {
    config(['automations.builtin_nodes.fetch_asset_from_url' => false]);

    $automations = app()->build(Automations::class);
    $this->app->instance('automations', $automations);

    $method = new ReflectionMethod(app()->getProvider(ServiceProvider::class), 'registerBuiltInNodes');
    $method->setAccessible(true);
    $method->invoke(app()->getProvider(ServiceProvider::class));

    expect($automations->isBuiltIn('asset.fetch_from_url'))->toBeFalse();
    expect($automations->isBuiltIn('add_log_entry'))->toBeTrue();
});

it('follows relative redirects and checks every hop with the HostGuard', function (): void {
    FakeWeb::fake([
        'files.example.test/a/start' => FakeWeb::response('', 302, ['Location' => '../b/next']),
        'files.example.test/b/next' => FakeWeb::response('', 301, ['Location' => 'https://cdn.example.test/blob?x=1']),
        'cdn.example.test/*' => FakeWeb::response('final', 200),
    ]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['url' => 'https://files.example.test/a/start', 'filename' => 'out.bin']));

    expect($result->isSuccess())->toBeTrue();
    expect(array_values(array_unique($this->hostsSeen)))->toBe(['files.example.test', 'cdn.example.test']);
    expect(file_get_contents($this->roots['media'].'/out.bin'))->toBe('final');
});

it('refuses a redirect into a private address', function (): void {
    FakeWeb::fake([
        'files.example.test/*' => FakeWeb::response('', 302, ['Location' => 'https://private.example.test/secret']),
        'private.example.test/*' => FakeWeb::response('leak', 200),
    ]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['filename' => 'out.bin']));

    expect($result->isFailed())->toBeTrue();
    expect(FakeWeb::recorded()->contains(fn (FakeRequest $request) => str_contains($request->url(), 'private.example.test')))->toBeFalse();
    expect(filesIn($this->roots['media']))->toBe([]);
});

it('refuses redirect loops, missing locations and a sixth hop', function (): void {
    FakeWeb::fake(['files.example.test/loop' => FakeWeb::response('', 302, ['Location' => '/loop#frag'])]);
    expect(fetchAction()->execute(AutomationContext::make(), fetchConfig(['url' => 'https://files.example.test/loop', 'filename' => 'a']))->error)->toContain('loop');

    FakeWeb::fake(['files.example.test/nolocation' => FakeWeb::response('', 302)]);
    expect(fetchAction()->execute(AutomationContext::make(), fetchConfig(['url' => 'https://files.example.test/nolocation', 'filename' => 'a']))->error)->toContain('Location');

    FakeWeb::fake(['files.example.test/h/*' => fn (FakeRequest $request) => FakeWeb::response('', 302, ['Location' => '/h/'.(((int) basename($request->url())) + 1)])]);
    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['url' => 'https://files.example.test/h/0', 'filename' => 'a']));
    expect($result->error)->toContain('Too many redirects');
    FakeWeb::assertSentCount(6);
});

it('drops the connection headers for good after the first change of origin', function (): void {
    fetchConnection();
    FakeWeb::fake([
        'files.example.test/start' => FakeWeb::response('', 302, ['Location' => 'https://cdn.example.test/hop']),
        'cdn.example.test/hop' => FakeWeb::response('', 302, ['Location' => 'https://files.example.test/back']),
        'files.example.test/back' => FakeWeb::response('done', 200),
    ]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['connection' => 'zoomdl', 'url' => 'https://files.example.test/start', 'filename' => 'out.bin']));

    expect($result->isSuccess())->toBeTrue();

    $auth = FakeWeb::recorded()->map(fn ($pair) => [$pair->url() => $pair->header('Authorization')])->collapse();

    expect($auth['https://files.example.test/start'])->toBe(['Bearer '.FETCH_SECRET]);
    expect($auth['https://cdn.example.test/hop'])->toBe([]);
    expect($auth['https://files.example.test/back'])->toBe([]);
});

it('renews an OAuth2 token once on 401 and downloads again', function (): void {
    fetchConnection([
        'handle' => 'zoomoauth',
        'auth_type' => 'oauth2_client_credentials',
        'auth_config' => ['token_url' => 'https://auth.example.test/token', 'client_id' => 'cid', 'client_secret' => 'secret-aa11bb22'],
    ]);
    FakeWeb::fake([
        'auth.example.test/token' => FakeWeb::sequence()->push(['access_token' => 'tok-one-1111', 'expires_in' => 3600])->push(['access_token' => 'tok-two-2222', 'expires_in' => 3600]),
        'files.example.test/*' => FakeWeb::sequence()->push('tok-one-1111 rejected', 401)->push('data', 200),
    ]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['connection' => 'zoomoauth']));

    expect($result->isSuccess())->toBeTrue();
    $downloads = FakeWeb::recorded()->filter(fn ($pair) => $pair->url() === 'https://files.example.test/rec/call.mp4')->values();
    expect($downloads)->toHaveCount(2);
    expect($downloads[0]->header('Authorization'))->toBe(['Bearer tok-one-1111']);
    expect($downloads[1]->header('Authorization'))->toBe(['Bearer tok-two-2222']);
});

it('fails after a second 401 without leaking tokens', function (): void {
    fetchConnection([
        'handle' => 'zoomoauth',
        'auth_type' => 'oauth2_client_credentials',
        'auth_config' => ['token_url' => 'https://auth.example.test/token', 'client_id' => 'cid', 'client_secret' => 'secret-aa11bb22'],
    ]);
    FakeWeb::fake([
        'auth.example.test/token' => FakeWeb::sequence()->push(['access_token' => 'tok-one-1111', 'expires_in' => 3600])->push(['access_token' => 'tok-two-2222', 'expires_in' => 3600]),
        'files.example.test/*' => FakeWeb::response('no', 401),
    ]);

    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['connection' => 'zoomoauth']));

    expect($result->isFailed())->toBeTrue();
    expect(json_encode($result))->not->toContain('tok-one-1111')->not->toContain('tok-two-2222')->not->toContain('secret-aa11bb22');
    expect(FakeWeb::recorded()->filter(fn ($pair) => str_contains($pair->url(), 'call.mp4'))->count())->toBe(2);
});

/** A fake server that writes `$megabytes` straight into the sink, 64 KiB at a time. */
function streamingHandler(int $megabytes): Closure
{
    return function ($request, array $options) use ($megabytes) {
        $total = $megabytes * 1048576;
        $headers = ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $total];
        $response = new PsrResponse(200, $headers);

        if (isset($options['on_headers'])) {
            $options['on_headers']($response);
        }

        $block = str_repeat('a', 65536);

        for ($written = 0; $written < $total; $written += 65536) {
            $options['sink']->write($block);
        }

        return new FulfilledPromise($response);
    };
}

it('streams 50 MiB with flat memory', function (): void {
    Http::globalOptions(['handler' => streamingHandler(50)]);
    config(['automations.assets.fetch.max_size_mb' => 100]);

    memory_reset_peak_usage();
    $before = memory_get_peak_usage(true);
    $result = fetchAction()->execute(AutomationContext::make(), fetchConfig(['filename' => 'big.bin']));
    $delta = memory_get_peak_usage(true) - $before;

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['size'])->toBe(50 * 1048576);
    expect($result->output['path'])->toBe('big.bin');
    expect($result->output['asset_id'])->toBe('media::big.bin');
    expect($result->output['mime_type'])->not->toBe('');
    expect(filesize($this->roots['media'].'/big.bin'))->toBe(50 * 1048576);
    expect(file_exists($this->roots['media'].'/.meta/big.bin.yaml'))->toBeTrue();
    expect($delta)->toBeLessThan(8 * 1048576);
});

function fetchAutomationRun(array $nodeConfig): AutomationRun
{
    $automation = Automation::create(['name' => 'Fetch', 'handle' => 'fetch-'.bin2hex(random_bytes(3)), 'enabled' => true]);

    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'entry_saved', 'config' => []]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'fetch', 'type' => 'asset.fetch_from_url', 'config' => $nodeConfig]);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'fetch']);

    return AutomationRun::create([
        'automation_id' => $automation->id,
        'trigger_node_key' => 't',
        'status' => AutomationRun::STATUS_QUEUED,
        'context' => [],
        'is_test' => false,
    ]);
}

it('runs end to end through RunAutomation and the WorkflowRunner', function (): void {
    FakeWeb::fake(['files.example.test/*' => FakeWeb::response('payload', 200)]);
    $run = fetchAutomationRun(fetchConfig());

    app()->call([new RunAutomation($run->id, []), 'handle']);

    $run->refresh();
    expect($run->status)->toBe(AutomationRun::STATUS_SUCCESS);
    $nodeRun = AutomationNodeRun::where('automation_run_id', $run->id)->where('node_key', 'fetch')->first();
    expect($nodeRun->status)->toBe('success');
    expect(file_get_contents($this->roots['media'].'/call.mp4'))->toBe('payload');
});

it('fails the node and the run with a size message and leaves no file behind at 1 MiB', function (): void {
    Http::globalOptions(['handler' => streamingHandler(5)]);
    $run = fetchAutomationRun(fetchConfig(['max_size_mb' => 1, 'filename' => 'toobig.bin']));

    app()->call([new RunAutomation($run->id, []), 'handle']);

    $run->refresh();
    expect($run->status)->toBe(AutomationRun::STATUS_FAILED);
    expect($run->error_message)->toContain('limit');
    $nodeRun = AutomationNodeRun::where('automation_run_id', $run->id)->where('node_key', 'fetch')->first();
    expect($nodeRun->status)->toBe('failed');
    expect($nodeRun->error_message)->toContain('limit');
    expect(filesIn($this->roots['media']))->toBe([]);
    expect(glob(sys_get_temp_dir().'/autofetch_*'))->toBe([]);
});
