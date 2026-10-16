<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\CountingSink;
use Goldnead\StatamicAutomations\Support\HostGuard;
use Goldnead\StatamicAutomations\Support\OAuth2TokenException;
use Goldnead\StatamicAutomations\Support\OAuth2TokenService;
use Goldnead\StatamicAutomations\Support\SizeLimitExceeded;
use Goldnead\StatamicAutomations\Support\UnsafeHostException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Statamic\Assets\AssetUploader;
use Statamic\Assets\Asset as AssetItem;
use Statamic\Assets\AssetContainer as AssetContainerItem;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\YAML;

/**
 * Downloads a file from a URL straight into an asset container.
 *
 * The body is streamed to a local temporary file through a counting sink and
 * from there to the container's disk, so a large recording never sits in
 * memory, and the transfer stops at the first byte over the limit. Redirects
 * are followed by hand (at most five hops) so every hop passes the HostGuard
 * and the connection's credential never leaves the origin it was meant for.
 *
 * It runs inside the normal queued run; nothing here is a job of its own.
 * The container is reached through Statamic's container and filesystem API
 * only, which is why a private container from another addon works without
 * this one knowing about it.
 */
class FetchAssetFromUrlAction implements AutomationAction
{
    public const MAX_REDIRECTS = 5;

    public static function handle(): string
    {
        return 'asset.fetch_from_url';
    }

    public static function label(): string
    {
        return 'Fetch file from URL';
    }

    public static function description(): ?string
    {
        return 'Downloads a file from a URL into an asset container, streamed and with a size limit.';
    }

    public static function group(): string
    {
        return 'Statamic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'url',
                'label' => 'URL',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'Tokens allowed, e.g. {{ nodes.recording.download_url }}.',
            ],
            [
                'handle' => 'connection',
                'label' => 'Connection',
                'type' => 'select',
                'options_source' => 'connections',
                'required' => false,
                'help' => 'Optional. Sends the connection\'s headers or OAuth2 token, to the URL\'s own host only.',
            ],
            [
                'handle' => 'container',
                'label' => 'Asset container',
                'type' => 'select',
                'options_source' => 'statamic.asset_containers',
                'required' => true,
            ],
            [
                'handle' => 'folder',
                'label' => 'Folder',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Optional, relative to the container root.',
            ],
            [
                'handle' => 'filename',
                'label' => 'File name',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Optional. Defaults to the last part of the URL path.',
            ],
            [
                'handle' => 'max_size_mb',
                'label' => 'Maximum size (MB)',
                'type' => 'number',
                'required' => false,
                'help' => 'Optional. Defaults to automations.assets.fetch.max_size_mb.',
            ],
            [
                'handle' => 'overwrite',
                'label' => 'Overwrite an existing file',
                'type' => 'toggle',
                'default' => false,
                'help' => 'Off: a taken name gets -1, -2 and so on.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function outputSchema(): array
    {
        return [
            'asset_id' => 'string',
            'path' => 'string',
            'size' => 'integer',
            'mime_type' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        try {
            $plan = $this->plan($config);
        } catch (\InvalidArgumentException $e) {
            return ActionResult::failed(__($e->getMessage()));
        }

        if ($context->isTestMode()) {
            return ActionResult::success([
                'preview' => [
                    'url' => $plan['url'],
                    'connection' => $plan['connection'],
                    'container' => $plan['container'],
                    'folder' => $plan['folder'],
                    'filename' => $plan['filename'],
                    'max_size_mb' => $plan['max_size_mb'],
                    'overwrite' => $plan['overwrite'],
                ],
                'note' => __('Test mode — nothing downloaded.'),
            ]);
        }

        $connection = null;

        if ($plan['connection'] !== null) {
            $connection = AutomationConnection::query()->where('handle', $plan['connection'])->first();

            if ($connection === null) {
                return ActionResult::failed("The connection '{$plan['connection']}' does not exist.");
            }
        }

        /** @var AssetContainerItem|null $container */
        $container = AssetContainer::find($plan['container']);

        if ($container === null) {
            return ActionResult::failed("The asset container '{$plan['container']}' does not exist.");
        }

        $temp = null;
        $resource = null;

        try {
            $temp = tempnam(sys_get_temp_dir(), 'autofetch_');
            $resource = $temp === false ? false : fopen($temp, 'w+b');

            if ($resource === false) {
                return ActionResult::failed(__('A temporary file could not be created.'));
            }

            $this->download($plan, $connection, $resource);

            return ActionResult::success($this->store($container, $plan, $resource));
        } catch (\Throwable $e) {
            $message = $this->describe($e);

            return ActionResult::failed($connection ? (string) $connection->mask($message) : $message);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }

            if (is_string($temp) && file_exists($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Validates the config locally and returns what the run needs. Throws
     * with the message that ends up in the run log.
     *
     * @param  array<string, mixed>  $config
     * @return array{url: string, connection: ?string, container: string, folder: string, filename: string, max_bytes: int, max_size_mb: float|int, timeout: float|int, overwrite: bool}
     */
    protected function plan(array $config): array
    {
        $url = trim((string) ($config['url'] ?? ''));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($url === '' || ! in_array($scheme, ['http', 'https'], true) || (string) parse_url($url, PHP_URL_HOST) === '') {
            throw new \InvalidArgumentException('A valid http or https URL is required.');
        }

        $container = trim((string) ($config['container'] ?? ''));

        if ($container === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $container)) {
            throw new \InvalidArgumentException('A valid asset container is required.');
        }

        $connection = trim((string) ($config['connection'] ?? ''));

        if ($connection !== '' && ! preg_match('/^[A-Za-z0-9_-]+$/', $connection)) {
            throw new \InvalidArgumentException('The connection handle is not valid.');
        }

        $maxMb = $this->positive($config['max_size_mb'] ?? null, config('automations.assets.fetch.max_size_mb', 500), 'The maximum size');
        $timeout = $this->positive(null, config('automations.assets.fetch.timeout', 300), 'The timeout');

        $folder = $this->folder((string) ($config['folder'] ?? ''));

        $name = trim((string) ($config['filename'] ?? ''));
        $fromUrl = $name === '';

        if ($fromUrl) {
            // The URL as given, not where a redirect ends up.
            $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        }

        $this->assertSafeSegments($name, 'The file name');

        if (preg_match('#[/\\\\]#', $name)) {
            throw new \InvalidArgumentException('The file name may not contain a path.');
        }

        $filename = $this->safeFilename($name);

        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new \InvalidArgumentException('No file name could be derived. Set one in the node.');
        }

        return [
            'url' => $url,
            'connection' => $connection === '' ? null : $connection,
            'container' => $container,
            'folder' => $folder,
            'filename' => $filename,
            'max_bytes' => (int) floor($maxMb * 1048576),
            'max_size_mb' => $maxMb,
            'timeout' => $timeout,
            'overwrite' => filter_var($config['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** A finite number above zero: the node's value, else the config default. */
    protected function positive(mixed $value, mixed $default, string $what): float|int
    {
        $number = ($value === null || $value === '') ? $default : $value;

        if (! is_numeric($number) || ! is_finite((float) $number) || (float) $number <= 0) {
            throw new \InvalidArgumentException("{$what} must be a number greater than zero.");
        }

        return $number + 0;
    }

    protected function folder(string $folder): string
    {
        $folder = trim($folder);

        if ($folder === '') {
            return '';
        }

        if (preg_match('#^([/\\\\]|[A-Za-z]:)#', $folder)) {
            throw new \InvalidArgumentException('The folder must be relative to the container root.');
        }

        $this->assertSafeSegments($folder, 'The folder');

        $segments = array_filter(preg_split('#[/\\\\]+#', $folder) ?: [], fn (string $segment) => $segment !== '');

        return implode('/', array_map(fn (string $segment) => $this->safeFilename($segment), $segments));
    }

    /**
     * No `.` or `..` segment and no absolute path, raw or after up to three
     * rounds of URL decoding (a proxy or disk driver that decodes first would
     * walk out of the folder).
     */
    protected function assertSafeSegments(string $value, string $what): void
    {
        $decoded = $value;

        for ($round = 0; $round < 4; $round++) {
            if (str_contains($decoded, "\0")) {
                throw new \InvalidArgumentException("{$what} contains an invalid character.");
            }

            if ($round > 0 && preg_match('#^([/\\\\]|[A-Za-z]:)#', $decoded)) {
                throw new \InvalidArgumentException("{$what} may not be an absolute path.");
            }

            foreach (preg_split('#[/\\\\]#', $decoded) ?: [] as $segment) {
                if ($segment === '.' || $segment === '..') {
                    throw new \InvalidArgumentException("{$what} may not contain '.' or '..' segments.");
                }
            }

            $decoded = rawurldecode($decoded);
        }
    }

    /** The same cleaning Statamic applies to an uploaded file's name. */
    protected function safeFilename(string $name): string
    {
        return (string) AssetUploader::getSafeFilename($name);
    }

    /**
     * Streams the answer into `$resource`, following redirects by hand.
     *
     * @param  array<string, mixed>  $plan
     * @param  resource  $resource
     */
    protected function download(array $plan, ?AutomationConnection $connection, $resource): void
    {
        // The credential is for the service the connection was set up for.
        // The URL may come from an earlier node, so it is checked before any
        // token is fetched or header built, not only on redirects.
        if ($connection !== null && $this->origin($plan['url']) !== $this->origin((string) $connection->base_url)) {
            throw new \RuntimeException('The URL does not belong to the connection\'s base URL, so the connection is not used for it.');
        }

        $oauth = $connection?->usesOAuth2() ? app(OAuth2TokenService::class) : null;
        $renewed = false;

        if ($oauth) {
            $connection->useAccessToken($oauth->token($connection));
        }

        while (true) {
            $outcome = $this->follow($plan, $connection, $resource);

            // A 401 on an OAuth2 connection: drop the token, fetch one new
            // and download once more. A second 401 is final.
            if ($outcome === 401 && $oauth && ! $renewed) {
                $renewed = true;
                $connection->useAccessToken($oauth->token($connection, fresh: true));

                continue;
            }

            if ($outcome === 401 && $renewed) {
                throw new \RuntimeException('The server answered with HTTP 401 again after the access token was renewed.');
            }

            return;
        }
    }

    /**
     * One pass over the redirect chain. Returns 401 when the server refused
     * the credential, otherwise null once the file is in `$resource`.
     *
     * @param  array<string, mixed>  $plan
     * @param  resource  $resource
     */
    protected function follow(array $plan, ?AutomationConnection $connection, $resource): ?int
    {
        $max = $plan['max_bytes'];
        $current = $plan['url'];
        $origin = $this->origin($current);
        $headers = $connection ? $connection->requestHeaders() : [];
        $seen = [$this->canonical($current) => true];

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $pinned = app(HostGuard::class)->guard($current);
            } catch (UnsafeHostException $e) {
                throw new \RuntimeException($e->getMessage());
            }

            ftruncate($resource, 0);
            rewind($resource);

            $sink = new CountingSink($resource, $max);

            $response = Http::withOptions(array_merge($pinned, [
                'sink' => $sink,
                // Before the first body byte: a stated length over the limit
                // ends the transfer without writing anything.
                'on_headers' => function (ResponseInterface $response) use ($max): void {
                    if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300
                        && is_numeric($length = $response->getHeaderLine('Content-Length')) && (float) $length > $max) {
                        throw new SizeLimitExceeded("The file announces {$length} bytes, more than the limit of {$max}.");
                    }
                },
            ]))
                ->withHeaders($headers)
                ->withoutRedirecting()
                ->timeout(max(1, (int) $plan['timeout']))
                ->get($current);

            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($hop === self::MAX_REDIRECTS) {
                    throw new \RuntimeException('Too many redirects (more than '.self::MAX_REDIRECTS.').');
                }

                $current = $this->redirectTarget($current, $response->header('Location'));

                if (isset($seen[$key = $this->canonical($current)])) {
                    throw new \RuntimeException('The redirects form a loop.');
                }

                $seen[$key] = true;

                // The credential belongs to the origin it was set up for. Once
                // the chain has left it, it is gone for good, even if a later
                // hop comes back.
                if ($headers !== [] && $this->origin($current) !== $origin) {
                    $headers = [];
                }

                continue;
            }

            if ($status === 401 && $connection !== null && $headers !== []) {
                return 401;
            }

            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException("The server answered with HTTP {$status}.");
            }

            $length = $response->header('Content-Length');

            if (is_numeric($length) && (float) $length > $max) {
                throw new SizeLimitExceeded("The file announces {$length} bytes, more than the limit of {$max}.");
            }

            fflush($resource);

            return null;
        }

        throw new \RuntimeException('Too many redirects (more than '.self::MAX_REDIRECTS.').');
    }

    protected function redirectTarget(string $current, string $location): string
    {
        $location = trim($location);

        if ($location === '') {
            throw new \RuntimeException('The server redirected without a Location.');
        }

        try {
            return (string) UriResolver::resolve(new Uri($current), new Uri($location));
        } catch (\InvalidArgumentException) {
            throw new \RuntimeException('The server sent an invalid redirect Location.');
        }
    }

    /** scheme, lowercased host and effective port, which is what makes two URLs one origin. */
    protected function origin(string $url): string
    {
        $parts = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return $scheme.'://'.strtolower(trim((string) ($parts['host'] ?? ''), '[]')).':'
            .($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    protected function canonical(string $url): string
    {
        $parts = parse_url($url) ?: [];

        return $this->origin($url).($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * Puts the downloaded file into the container and reads the result back
     * from the asset. Any failure leaves nothing behind: new files go, and an
     * overwritten file and its meta come back as they were.
     *
     * @param  array<string, mixed>  $plan
     * @param  resource  $resource
     * @return array{asset_id: string, path: string, size: int, mime_type: string}
     */
    protected function store(AssetContainerItem $container, array $plan, $resource): array
    {
        $handle = $container->handle();
        $disk = $container->disk();
        $original = ltrim(($plan['folder'] !== '' ? $plan['folder'].'/' : '').$plan['filename'], '/');

        $lock = Cache::lock('statamic-automations:asset-fetch:'.$handle.':'.sha1($original), 300);

        try {
            $lock->block(60);
        } catch (LockTimeoutException) {
            throw new \RuntimeException('Another download to the same file is still running.');
        }

        $created = [];
        $backups = [];
        $restore = [];

        try {
            $path = $plan['overwrite'] ? $original : $this->freePath($disk, $handle, $original);

            $meta = $this->metaPath($handle, $path);

            if ($plan['overwrite'] && $disk->exists($path)) {
                $token = bin2hex(random_bytes(6));
                $dir = dirname($path) === '.' ? '' : dirname($path).'/';

                foreach ([$path, $meta] as $existing) {
                    if (! $disk->exists($existing)) {
                        continue;
                    }

                    $backup = $dir.'.fetch-backup-'.$token.'-'.basename($existing);
                    $disk->copy($existing, $backup);

                    if (! $disk->exists($backup) || $disk->size($backup) !== $disk->size($existing)) {
                        $backups[] = $backup;

                        throw new \RuntimeException('The existing file could not be backed up.');
                    }

                    $backups[] = $backup;
                    $restore[$existing] = $backup;
                }

                // Fresh meta for the new content, not the old one's.
                if (isset($restore[$meta])) {
                    $disk->delete($meta);
                }
            }

            $existed = isset($restore[$path]);

            rewind($resource);

            if ($disk->put($path, $resource) === false || ! $disk->exists($path)) {
                if (! $existed) {
                    $created[] = $path;
                }

                throw new \RuntimeException('The file could not be written to the container.');
            }

            if (! $existed) {
                $created[] = $path;
            }

            if (! isset($restore[$meta])) {
                $created[] = $meta;
            }

            $asset = $this->makeAsset($handle, $path);
            $asset->writeMeta($asset->generateMeta());

            if ($asset->save() === false) {
                throw new \RuntimeException('The asset could not be saved.');
            }

            $this->verifyStored($disk, $asset);

            $output = [
                'asset_id' => (string) $asset->id(),
                'path' => (string) $asset->path(),
                'size' => (int) $asset->size(),
                'mime_type' => (string) $asset->mimeType(),
            ];

            // Committed: from here nothing may roll back. A backup that cannot
            // be deleted is only leftover, never a reason to undo the new file.
            foreach ($backups as $backup) {
                try {
                    $this->deleteBackup($disk, $backup);
                } catch (\Throwable) {
                    // Left in place; it is a dotfile next to the asset.
                }
            }

            return $output;
        } catch (\Throwable $e) {
            $kept = $this->rollBack($disk, $created, $restore);

            // A backup goes only once its file is confirmed back in place;
            // one that could not be restored is the only copy left.
            foreach ($backups as $backup) {
                if (! in_array($backup, $kept, true)) {
                    $disk->delete($backup);
                }
            }

            if ($kept !== []) {
                throw new \RuntimeException($e->getMessage().' The original could not be fully restored; its backup is kept at: '.implode(', ', $kept).'.', 0, $e);
            }

            throw $e;
        } finally {
            $lock->release();
        }
    }

    protected function deleteBackup($disk, string $backup): void
    {
        $disk->delete($backup);
    }

    /** The saved asset must have its file and a meta file with the core data. */
    protected function verifyStored($disk, $asset): void
    {
        $metaFile = $disk->exists($asset->metaPath()) ? YAML::parse((string) $disk->get($asset->metaPath())) : null;

        if (! is_array($metaFile) || ! isset($metaFile['size'])) {
            throw new \RuntimeException('The asset meta file was not written.');
        }
    }

    /**
     * Removes what this run created and puts the old files back.
     *
     * @param  array<int, string>  $created
     * @param  array<string, string>  $restore  existing path => its backup
     * @return array<int, string> the backups whose restore failed or could not be confirmed
     */
    protected function rollBack($disk, array $created, array $restore): array
    {
        foreach ($created as $path) {
            try {
                $disk->delete($path);
            } catch (\Throwable) {
                // Keep going: the rest of the cleanup matters more.
            }
        }

        $kept = [];

        foreach ($restore as $path => $backup) {
            try {
                $restored = $this->restoreFile($disk, $backup, $path);
            } catch (\Throwable) {
                $restored = false;
            }

            if (! $restored) {
                $kept[] = $backup;
            }
        }

        return $kept;
    }

    /** Copies a backup back over its file and confirms it arrived. */
    protected function restoreFile($disk, string $backup, string $path): bool
    {
        if ($disk->copy($backup, $path) === false) {
            return false;
        }

        return $disk->exists($path) && $disk->size($path) === $disk->size($backup);
    }

    /** The first of `name`, `name-1`, `name-2` … that is free on the disk. */
    protected function freePath($disk, string $handle, string $original): string
    {
        $info = pathinfo($original);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'].'/';
        $stem = $info['filename'];
        $extension = isset($info['extension']) ? '.'.$info['extension'] : '';

        for ($i = 0; $i < 10000; $i++) {
            $path = $i === 0 ? $original : $dir.$stem.'-'.$i.$extension;

            if (! $disk->exists($path) && ! $disk->exists($this->metaPath($handle, $path))) {
                return $path;
            }
        }

        throw new \RuntimeException('No free file name was found.');
    }

    protected function metaPath(string $handle, string $path): string
    {
        return $this->makeAsset($handle, $path)->metaPath();
    }

    protected function makeAsset(string $handle, string $path): AssetItem
    {
        /** @var AssetItem $asset */
        $asset = Asset::make();

        return $asset->container($handle)->path($path);
    }

    protected function describe(\Throwable $e): string
    {
        for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof SizeLimitExceeded) {
                return $previous->getMessage();
            }
        }

        if ($e instanceof OAuth2TokenException) {
            return $e->getMessage();
        }

        return $e->getMessage() !== '' ? __($e->getMessage()) : $e::class;
    }
}
