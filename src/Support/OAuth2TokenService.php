<?php

namespace Goldnead\StatamicAutomations\Support;

use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Gets and keeps the access token of a connection that authenticates with
 * the OAuth2 client credentials grant.
 *
 * The token is cached per connection until `expires_in` minus a safety
 * margin, encrypted: with a file or database cache it would otherwise sit
 * there in the clear. The cache key carries a hash of the stored setup, so
 * changing the client or the token URL never serves the old token.
 *
 * Every failure is an {@see OAuth2TokenException} whose message has the
 * secrets masked out. There is no fallback to an unauthenticated request.
 */
class OAuth2TokenService
{
    /** Seconds before the stated expiry at which a token counts as expired. */
    public const SAFETY_MARGIN = 60;

    /** Used when the endpoint states no usable `expires_in`. */
    public const DEFAULT_LIFETIME = 300;

    public function __construct(protected HostGuard $guard) {}

    /**
     * A valid access token for the connection.
     *
     * @throws OAuth2TokenException
     */
    public function token(AutomationConnection $connection, bool $fresh = false): string
    {
        $key = $this->cacheKey($connection);

        if ($fresh) {
            $this->forget($connection);
        } elseif ($key !== null && ($cached = $this->cached($key)) !== null) {
            return $cached;
        }

        [$token, $lifetime] = $this->request($connection);

        // Tell the mask about it before anything can fail or be logged.
        $connection->useAccessToken($token);

        $ttl = $lifetime - self::SAFETY_MARGIN;

        if ($key !== null && $ttl > 0) {
            Cache::put($key, Crypt::encryptString((string) json_encode([
                'token' => $token,
                'expires_at' => now()->getTimestamp() + $ttl,
            ])), $ttl);
        }

        return $token;
    }

    /** Drop the cached token, e.g. after the service answered 401. */
    public function forget(AutomationConnection $connection): void
    {
        if (($key = $this->cacheKey($connection)) !== null) {
            Cache::forget($key);
        }
    }

    /** Null for an unsaved connection (the CP test): nothing to cache under. */
    protected function cacheKey(AutomationConnection $connection): ?string
    {
        if (! $connection->exists) {
            return null;
        }

        return 'statamic-automations:oauth2:'.$connection->getKey().':'
            .hash('sha256', (string) json_encode($connection->auth_config));
    }

    protected function cached(string $key): ?string
    {
        $payload = Cache::get($key);

        if (! is_string($payload)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($payload), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['token'] ?? null) || $data['token'] === ''
            || (int) ($data['expires_at'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }

        return $data['token'];
    }

    /**
     * One call to the token endpoint.
     *
     * @return array{0: string, 1: int} token and lifetime in seconds
     *
     * @throws OAuth2TokenException
     */
    protected function request(AutomationConnection $connection): array
    {
        $config = $connection->auth_config ?? [];
        $url = (string) ($config['token_url'] ?? '');

        if ($url === '') {
            throw new OAuth2TokenException('The connection has no token URL.');
        }

        // Right before the call, not only on save: the name may resolve
        // elsewhere by now. The options pin the request to the checked address.
        try {
            $pinned = $this->guard->guard($url);
        } catch (UnsafeHostException $e) {
            throw new OAuth2TokenException($this->masked($connection, 'Token URL refused: '.$e->getMessage()));
        }

        $fields = array_filter(
            is_array($config['token_request_fields'] ?? null) ? $config['token_request_fields'] : [],
            fn ($value) => is_scalar($value) && (string) $value !== '',
        );
        $body = array_merge(['grant_type' => 'client_credentials'], $fields);

        if (filled($config['scope'] ?? null)) {
            $body['scope'] = (string) $config['scope'];
        }

        $request = Http::withOptions($pinned)
            // No redirects: the client secret must not follow one elsewhere.
            ->withoutRedirecting()
            ->asForm()
            ->acceptJson()
            ->timeout(max(1, (int) $connection->timeout));

        if (($config['client_auth_method'] ?? 'client_secret_basic') === 'client_secret_post') {
            $body['client_id'] = (string) ($config['client_id'] ?? '');
            $body['client_secret'] = (string) ($config['client_secret'] ?? '');
        } else {
            $request = $request->withBasicAuth((string) ($config['client_id'] ?? ''), (string) ($config['client_secret'] ?? ''));
        }

        try {
            $response = $request->post($url, $body);
        } catch (\Throwable $e) {
            // Not chained: the previous exception's trace and message are unmasked.
            throw new OAuth2TokenException($this->masked($connection, 'Token request failed: '.$e->getMessage()));
        }

        $json = $response->json();

        if (! $response->successful()) {
            $detail = is_array($json) ? trim((string) ($json['error_description'] ?? $json['reason'] ?? $json['error'] ?? '')) : '';

            throw new OAuth2TokenException($this->masked(
                $connection,
                "Token request answered with HTTP {$response->status()}".($detail !== '' ? ": {$detail}" : '').'.',
            ));
        }

        $token = is_array($json) ? ($json['access_token'] ?? null) : null;

        if (! is_string($token) || trim($token) === '') {
            throw new OAuth2TokenException('Token request succeeded but the answer has no access_token.');
        }

        $lifetime = is_array($json) && is_numeric($json['expires_in'] ?? null) && (int) $json['expires_in'] > 0
            ? (int) $json['expires_in']
            : self::DEFAULT_LIFETIME;

        return [$token, $lifetime];
    }

    protected function masked(AutomationConnection $connection, string $message): string
    {
        return (string) $connection->mask($message);
    }
}
