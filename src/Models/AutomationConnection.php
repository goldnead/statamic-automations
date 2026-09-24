<?php

namespace Goldnead\StatamicAutomations\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * A service a customer sets up in the CP: base URL, auth and credentials.
 * Each of its operations becomes an action node `connection.<handle>.<op>`.
 *
 * The credentials live in `auth_config`, encrypted at rest, and are applied
 * only inside the HTTP call ({@see authHeaders()}). They never enter a node's
 * config or the run context, which is what keeps them out of the run log.
 *
 * @property int $id
 * @property string $handle
 * @property string $name
 * @property string $base_url
 * @property string $auth_type
 * @property array<string, string>|null $auth_config
 * @property array<string, string>|null $default_headers
 * @property int $timeout
 * @property string|null $test_path
 */
class AutomationConnection extends Model
{
    use HasBrand;

    public const AUTH_TYPES = ['none', 'header', 'bearer', 'basic'];

    /** The keys each auth type keeps in `auth_config`. */
    public const AUTH_FIELDS = [
        'none' => [],
        'header' => ['name', 'value'],
        'bearer' => ['token'],
        'basic' => ['username', 'password'],
    ];

    protected $table = 'automation_connections';

    protected static bool $schemaReady = false;

    protected $fillable = [
        'handle',
        'name',
        'base_url',
        'auth_type',
        'auth_config',
        'default_headers',
        'timeout',
        'test_path',
    ];

    protected $attributes = [
        'auth_type' => 'none',
        'timeout' => 15,
    ];

    // Second net: a model serialised by accident still carries no secret.
    protected $hidden = ['auth_config'];

    protected $casts = [
        'auth_config' => 'encrypted:array',
        'default_headers' => 'array',
        'timeout' => 'integer',
    ];

    /** See {@see Automation::schemaReady()}: nothing here may query before migrate. */
    public static function schemaReady(): bool
    {
        // Remembered once true: tables do not disappear while a process runs,
        // and the registry asks on every lookup.
        return static::$schemaReady = static::$schemaReady || Schema::hasTable('automation_connections');
    }

    protected static function booted(): void
    {
        // A renamed connection renames the group and handle of its nodes.
        static::saved(fn () => AutomationConnectionOperation::flushCache());
        static::deleted(fn () => AutomationConnectionOperation::flushCache());
    }

    /** Forget {@see schemaReady()}; see AutomationConnectionOperation::flushCache(). */
    public static function forgetSchemaReady(): void
    {
        static::$schemaReady = false;
    }

    /** @return HasMany<AutomationConnectionOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(AutomationConnectionOperation::class, 'connection_id');
    }

    /**
     * The headers that authenticate a request to this service.
     *
     * @return array<string, string>
     */
    public function authHeaders(): array
    {
        $auth = $this->auth_config ?? [];

        return match ($this->auth_type) {
            'bearer' => ['Authorization' => 'Bearer '.($auth['token'] ?? '')],
            'basic' => ['Authorization' => 'Basic '.base64_encode(($auth['username'] ?? '').':'.($auth['password'] ?? ''))],
            'header' => filled($auth['name'] ?? null) ? [(string) $auth['name'] => (string) ($auth['value'] ?? '')] : [],
            default => [],
        };
    }

    /**
     * Every string that would give the credential away: the stored values and
     * the header values built from them (a Basic header is base64, not the
     * password, and a service may echo either).
     *
     * Two stored values are left out. A header's NAME is not secret and is
     * what the preview shows. A short Basic username ("ada") is not worth
     * masking and would turn "canada" in a response into "can••••".
     *
     * @return array<int, string>
     */
    public function secretValues(): array
    {
        $stored = $this->auth_config ?? [];
        unset($stored['name']);

        if (strlen((string) ($stored['username'] ?? '')) < 8) {
            unset($stored['username']);
        }

        $values = [...array_values($stored), ...array_values($this->authHeaders())];

        return array_values(array_unique(array_filter(
            array_map('strval', $values),
            fn (string $value) => $value !== '',
        )));
    }

    /**
     * Replace every secret value inside `$data` with `••••`.
     */
    public function mask(mixed $data): mixed
    {
        $secrets = $this->secretValues();

        // Longest first, so a header value is replaced whole rather than
        // leaving its prefix around a masked token.
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $this->maskWith($data, $secrets);
    }

    /** @param  array<int, string>  $secrets */
    protected function maskWith(mixed $data, array $secrets): mixed
    {
        if (is_string($data)) {
            return $secrets === [] ? $data : str_replace($secrets, '••••', $data);
        }

        if (is_array($data)) {
            return array_map(fn ($value) => $this->maskWith($value, $secrets), $data);
        }

        return $data;
    }

    /**
     * Headers that are not secret, from a key_value field in either shape.
     *
     * @return array<string, string>
     */
    public function defaultHeaders(): array
    {
        return AutomationConnectionOperation::keyValue($this->default_headers);
    }

    /**
     * `base_url` + `$path`, keeping a query either of them already carries
     * (`?api-version=2`) and appending `$query` after it.
     *
     * @param  array<string, mixed>  $query
     */
    public function url(string $path, array $query = []): string
    {
        [$base, $baseQuery] = array_pad(explode('?', $this->base_url, 2), 2, '');
        [$path, $pathQuery] = array_pad(explode('?', $path, 2), 2, '');

        $queries = array_filter(
            [$baseQuery, $pathQuery, http_build_query($query, '', '&', PHP_QUERY_RFC3986)],
            fn (string $part) => $part !== '',
        );

        $url = rtrim($base, '/').'/'.ltrim($path, '/');

        return $queries === [] ? $url : $url.'?'.implode('&', $queries);
    }
}
