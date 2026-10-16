<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Http\Requests\ScopesUniquenessToBrand;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Models\AutomationConnectionOperation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Registries\ConnectionTemplateRegistry;
use Goldnead\StatamicAutomations\Support\HostGuard;
use Goldnead\StatamicAutomations\Support\OAuth2TokenService;
use Goldnead\StatamicAutomations\Support\UnsafeHostException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * JSON API for connections and their operations.
 *
 * Credentials go in and never come out: every response carries `auth_config`
 * as placeholders only, and on update an empty field or the placeholder means
 * "unchanged". The pattern is Webhook Manager's outbound screen.
 */
class ConnectionsController extends Controller
{
    use ScopesUniquenessToBrand;

    /** What the CP shows in a credential field that is set. */
    public const PLACEHOLDER = '••••••••';

    protected const PERMISSION = 'manage automation connections';

    public function index(): JsonResponse
    {
        $this->authorizeAction(self::PERMISSION);

        $connections = AutomationConnection::query()
            ->withCount('operations')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $connections->map(fn (AutomationConnection $connection) => $this->present($connection))->values(),
        ]);
    }

    public function show(AutomationConnection $automationConnection): JsonResponse
    {
        $this->authorizeAction(self::PERMISSION);

        return response()->json(['data' => $this->present($automationConnection, detailed: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAction(self::PERMISSION);

        $data = $this->validateConnection($request);
        $data['auth_config'] = $this->authConfig($data['auth_type'], $request->input('auth_config'));
        $this->requireOAuthSecret($data['auth_type'], $data['auth_config']);

        $template = $this->template($request);

        // The connection and the operations of a template go in together:
        // a bad operation leaves no half-made connection behind.
        $connection = DB::transaction(function () use ($data, $template) {
            $connection = AutomationConnection::create($data);

            foreach ($template['operations'] ?? [] as $operation) {
                $connection->operations()->create($this->validateTemplateOperation($operation, $connection));
            }

            return $connection;
        });

        return response()->json(['data' => $this->present($connection, detailed: true)], 201);
    }

    public function update(Request $request, AutomationConnection $automationConnection): JsonResponse
    {
        $this->authorizeAction(self::PERMISSION);

        $data = $this->validateConnection($request, $automationConnection);
        $data['auth_config'] = $this->authConfig($data['auth_type'], $request->input('auth_config'), $automationConnection);
        $this->requireOAuthSecret($data['auth_type'], $data['auth_config']);

        $automationConnection->update($data);

        return response()->json(['data' => $this->present($automationConnection->fresh(), detailed: true)]);
    }

    public function destroy(AutomationConnection $automationConnection): JsonResponse
    {
        $this->authorizeAction(self::PERMISSION);

        $automationConnection->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * One GET on `base_url + test_path` with the connection's auth. Answers
     * whether it worked and never with the body — a service's answer to an
     * authenticated request is not something to hand to the browser.
     *
     * With a body, it tests what the form holds rather than what is stored:
     * the edit page sends its unsaved values, so "Test" answers for the screen
     * the operator is looking at. Nothing is saved. The submitted base URL
     * goes through the same HostGuard as every call, below.
     */
    public function test(Request $request, AutomationConnection $automationConnection): JsonResponse
    {
        $this->authorizeAction(self::PERMISSION);

        if ($request->hasAny(['base_url', 'auth_type', 'auth_config', 'default_headers', 'timeout', 'test_path'])) {
            $automationConnection = $this->draft($request, $automationConnection);
        }

        $started = microtime(true);
        $url = $automationConnection->url((string) ($automationConnection->test_path ?: '/'));
        $oauth = $automationConnection->usesOAuth2() ? app(OAuth2TokenService::class) : null;

        $send = fn () => Http::withOptions(app(HostGuard::class)->guard($url))
            ->withHeaders($automationConnection->requestHeaders())
            ->withoutRedirecting()
            ->timeout(max(1, (int) $automationConnection->timeout))
            ->get($url);

        try {
            // Checked again here and pinned, like every operation call; no
            // redirects, so a credential header cannot follow one elsewhere.
            // An OAuth2 connection gets its token from the same service as
            // an operation call, with the same single retry on a 401.
            if ($oauth) {
                $automationConnection->useAccessToken($oauth->token($automationConnection));
            }

            $response = $send();

            if ($oauth && $response->status() === 401) {
                $automationConnection->useAccessToken($oauth->token($automationConnection, fresh: true));
                $response = $send();
            }
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'status' => null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $automationConnection->mask($e->getMessage()),
            ]);
        }

        return response()->json([
            'ok' => $response->successful(),
            'status' => $response->status(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    public function storeOperation(Request $request, AutomationConnection $automationConnection): JsonResponse
    {
        $this->authorizeAction(self::PERMISSION);

        $operation = $automationConnection->operations()->create($this->validateOperation($request, $automationConnection));

        return response()->json(['data' => $this->presentOperation($operation)], 201);
    }

    public function updateOperation(
        Request $request,
        AutomationConnection $automationConnection,
        AutomationConnectionOperation $automationConnectionOperation,
    ): JsonResponse {
        $this->authorizeAction(self::PERMISSION);
        abort_unless($automationConnectionOperation->connection_id === $automationConnection->id, 404);

        $automationConnectionOperation->update(
            $this->validateOperation($request, $automationConnection, $automationConnectionOperation),
        );

        return response()->json(['data' => $this->presentOperation($automationConnectionOperation->fresh())]);
    }

    public function destroyOperation(
        AutomationConnection $automationConnection,
        AutomationConnectionOperation $automationConnectionOperation,
    ): JsonResponse {
        $this->authorizeAction(self::PERMISSION);
        abort_unless($automationConnectionOperation->connection_id === $automationConnection->id, 404);

        $automationConnectionOperation->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * An unsaved copy of the connection with the submitted values over it.
     *
     * Stored credentials fill empty fields only while the host stays the
     * stored one. Pointed at another host, the draft carries only what was
     * typed: a test must not become the way to send a saved secret somewhere
     * new without anyone typing it.
     */
    protected function draft(Request $request, AutomationConnection $stored): AutomationConnection
    {
        $data = $request->validate([
            'base_url' => ['sometimes', 'required', 'url', 'max:2048'],
            'auth_type' => ['sometimes', 'required', Rule::in(AutomationConnection::AUTH_TYPES)],
            'auth_config' => ['nullable', 'array'],
            ...$this->oauthRules($request->input('auth_type', $stored->auth_type) === AutomationConnection::OAUTH2, required: false),
            'default_headers' => ['nullable', 'array'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
            'test_path' => ['nullable', 'string', 'max:1024'],
        ]);

        $draft = $stored->replicate();
        $draft->fill(array_filter([
            'base_url' => $data['base_url'] ?? null,
            'auth_type' => $data['auth_type'] ?? null,
            'default_headers' => $data['default_headers'] ?? null,
            'timeout' => $data['timeout'] ?? null,
        ], fn ($value) => $value !== null));

        if ($request->has('test_path')) {
            $draft->test_path = $data['test_path'] ?? null;
        }

        $host = fn (mixed $url) => strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        $sameHost = $host($draft->base_url) === $host($stored->base_url);

        // The client secret goes to the token URL too: the same rule applies.
        if ($sameHost && $draft->auth_type === AutomationConnection::OAUTH2
            && is_array($request->input('auth_config')) && filled($request->input('auth_config.token_url'))) {
            $sameHost = $host($request->input('auth_config.token_url')) === $host(($stored->auth_config ?? [])['token_url'] ?? null);
        }

        $draft->auth_config = $this->authConfig(
            (string) $draft->auth_type,
            $request->input('auth_config'),
            $sameHost ? $stored : null,
        );

        return $draft;
    }

    /** @return array<string, mixed> */
    protected function validateConnection(Request $request, ?AutomationConnection $connection = null): array
    {
        $unique = $this->brandScoped(Rule::unique('automation_connections', 'handle'));

        $data = $request->validate([
            'handle' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                $connection ? $unique->ignore($connection->id) : $unique,
            ],
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'url', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) {
                try {
                    app(HostGuard::class)->guard((string) $value);
                } catch (UnsafeHostException $e) {
                    $fail($e->getMessage());
                }
            }],
            'auth_type' => ['required', Rule::in(AutomationConnection::AUTH_TYPES)],
            'auth_config' => ['nullable', 'array'],
            ...$this->oauthRules($request->input('auth_type') === AutomationConnection::OAUTH2),
            'default_headers' => ['nullable', 'array'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
            'test_path' => ['nullable', 'string', 'max:1024'],
            'template_handle' => ['nullable', 'string', 'max:64'],
        ]);

        unset($data['template_handle']);
        $data['timeout'] ??= 15;

        return $data;
    }

    /** @return array<string, mixed> */
    protected function validateOperation(
        Request $request,
        AutomationConnection $connection,
        ?AutomationConnectionOperation $operation = null,
    ): array {
        // Any RFC 7230 token, checked uppercased: `report` is REPORT.
        if (is_string($request->input('method'))) {
            $request->merge(['method' => strtoupper(trim($request->input('method')))]);
        }

        $data = $request->validate($this->operationRules($connection, $operation));

        $data['fail_on_error_status'] ??= true;

        return $data;
    }

    /**
     * One operation of a template, checked by the rules of the operation form.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function validateTemplateOperation(array $operation, AutomationConnection $connection): array
    {
        $operation['method'] = strtoupper(trim((string) ($operation['method'] ?? '')));

        $data = Validator::make($operation, $this->operationRules($connection))->validate();
        $data['fail_on_error_status'] ??= true;

        return $data;
    }

    /** @return array<string, mixed> */
    protected function operationRules(AutomationConnection $connection, ?AutomationConnectionOperation $operation = null): array
    {
        $unique = Rule::unique('automation_connection_operations', 'handle')->where('connection_id', $connection->id);

        return [
            'handle' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                $operation ? $unique->ignore($operation->id) : $unique,
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'method' => ['required', 'string', 'max:32', 'regex:'.AutomationConnectionOperation::METHOD_PATTERN],
            'path' => ['required', 'string', 'max:2048'],
            'query' => ['nullable', 'array'],
            'body' => ['nullable', 'array'],
            'body_mode' => ['nullable', Rule::in(AutomationConnectionOperation::BODY_MODES)],
            // A line break would end the header and start another one.
            'content_type' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'raw_body' => ['nullable', 'string', 'max:65535'],
            'headers' => ['nullable', 'array', $this->noReservedHeaders()],
            'response_format' => ['nullable', Rule::in(AutomationConnectionOperation::RESPONSE_FORMATS)],
            'inputs' => ['nullable', 'array'],
            'inputs.*.handle' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'inputs.*.label' => ['nullable', 'string', 'max:255'],
            'inputs.*.type' => ['required', Rule::in(AutomationConnectionOperation::INPUT_TYPES)],
            'inputs.*.required' => ['nullable', 'boolean'],
            'inputs.*.default' => ['nullable'],
            'inputs.*.options' => ['nullable', 'array'],
            'response_map' => ['nullable', 'array'],
            'fail_on_error_status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The template named by `template_handle`, or null for none. An unknown
     * handle is an error, not "no template": the form would otherwise save a
     * connection without the operations the operator picked it for.
     *
     * @return array<string, mixed>|null
     */
    protected function template(Request $request): ?array
    {
        $handle = $request->input('template_handle');

        if (! is_string($handle) || $handle === '') {
            return null;
        }

        $template = app(ConnectionTemplateRegistry::class)->get($handle);

        if ($template === null) {
            throw ValidationException::withMessages(['template_handle' => __('Unknown connection template.')]);
        }

        return $template;
    }

    /**
     * Rules for the OAuth2 fields of `auth_config`. The token URL passes the
     * host guard on save, as the base URL does, and again before every
     * request.
     *
     * @return array<string, mixed>
     */
    protected function oauthRules(bool $active, bool $required = true): array
    {
        $required = $active && $required;

        return [
            'auth_config.token_url' => [$required ? 'required' : 'nullable', 'url', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) {
                try {
                    app(HostGuard::class)->guard((string) $value);
                } catch (UnsafeHostException $e) {
                    $fail($e->getMessage());
                }
            }],
            'auth_config.client_id' => [$required ? 'required' : 'nullable', 'string', 'max:1024'],
            'auth_config.client_secret' => ['nullable', 'string', 'max:4096'],
            'auth_config.scope' => ['nullable', 'string', 'max:1024'],
            'auth_config.client_auth_method' => ['nullable', Rule::in(AutomationConnection::CLIENT_AUTH_METHODS)],
            'auth_config.token_request_fields' => ['nullable', 'array', function (string $attribute, mixed $value, \Closure $fail) {
                foreach (AutomationConnectionOperation::keyValue($value) as $name => $field) {
                    if (trim((string) $name) === '' || ! is_scalar($field) && $field !== null) {
                        $fail(__('The token request fields must be name and value pairs.'));
                    }
                }
            }],
        ];
    }

    /** A client credentials connection without a client secret can never get a token. */
    protected function requireOAuthSecret(string $type, ?array $config): void
    {
        if ($type === AutomationConnection::OAUTH2 && ! filled($config['client_secret'] ?? null)) {
            throw ValidationException::withMessages(['auth_config.client_secret' => __('The client secret is required.')]);
        }
    }

    /**
     * Refuses a header named Host or Content-Length, in any case and in
     * either key_value shape: the transport sets those, and the call drops
     * them anyway, so saving one would only hide that it has no effect.
     */
    protected function noReservedHeaders(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            foreach (array_keys(AutomationConnectionOperation::keyValue($value)) as $name) {
                if (in_array(strtolower(trim((string) $name)), AutomationConnection::RESERVED_HEADERS, true)) {
                    $fail(__('The header :name is set by the request itself and cannot be configured.', ['name' => $name]));
                }
            }
        };
    }

    /**
     * The credentials to store: only the keys the auth type uses, and where a
     * field arrived empty or as the placeholder, the value already stored —
     * as long as the auth type did not change.
     *
     * @return array<string, string>|null
     */
    protected function authConfig(string $type, mixed $input, ?AutomationConnection $existing = null): ?array
    {
        $input = is_array($input) ? $input : [];
        $kept = $existing && $existing->auth_type === $type ? ($existing->auth_config ?? []) : [];
        $config = [];

        if ($type === AutomationConnection::OAUTH2) {
            return $this->oauthConfig($input, $kept);
        }

        foreach (AutomationConnection::AUTH_FIELDS[$type] ?? [] as $key) {
            $value = $input[$key] ?? null;

            $config[$key] = is_string($value) && $value !== '' && $value !== self::PLACEHOLDER
                ? $value
                : (string) ($kept[$key] ?? '');
        }

        return $config === [] ? null : $config;
    }

    /**
     * The OAuth2 setup to store. The plain fields are taken as typed (an
     * empty one falls back to what is stored); the client secret and the
     * secret token request fields keep the stored value when they arrive
     * empty or as the placeholder.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $kept
     * @return array<string, mixed>
     */
    protected function oauthConfig(array $input, array $kept): array
    {
        $config = [];

        foreach (['token_url', 'client_id', 'scope'] as $key) {
            $value = $input[$key] ?? null;
            $config[$key] = is_string($value) && $value !== '' ? trim($value) : (string) ($kept[$key] ?? '');
        }

        $secret = $input['client_secret'] ?? null;
        $config['client_secret'] = is_string($secret) && $secret !== '' && $secret !== self::PLACEHOLDER
            ? $secret
            : (string) ($kept['client_secret'] ?? '');

        $method = $input['client_auth_method'] ?? $kept['client_auth_method'] ?? null;
        $config['client_auth_method'] = in_array($method, AutomationConnection::CLIENT_AUTH_METHODS, true) ? $method : 'client_secret_basic';

        $keptFields = is_array($kept['token_request_fields'] ?? null) ? $kept['token_request_fields'] : [];
        $fields = [];

        foreach (AutomationConnectionOperation::keyValue($input['token_request_fields'] ?? []) as $name => $value) {
            $name = trim((string) $name);
            $value = is_scalar($value) ? (string) $value : '';

            if ($name === '') {
                continue;
            }

            $fields[$name] = $value === self::PLACEHOLDER ? (string) ($keptFields[$name] ?? '') : $value;
        }

        $config['token_request_fields'] = $fields;

        return $config;
    }

    /**
     * Public so the CP pages hand the browser exactly what this API does —
     * one presenter, so there is one place a credential could leak from.
     *
     * @return array<string, mixed>
     */
    public function present(AutomationConnection $connection, bool $detailed = false): array
    {
        $data = [
            'id' => $connection->id,
            'handle' => $connection->handle,
            'name' => $connection->name,
            'base_url' => $connection->base_url,
            'auth_type' => $connection->auth_type,
            // Which fields are set, never what is in them.
            'auth_config' => $this->presentAuthConfig($connection),
            'auth_configured' => filled($connection->usesOAuth2()
                ? ($connection->auth_config['client_secret'] ?? null)
                : array_filter($connection->auth_config ?? [])),
            'default_headers' => $connection->default_headers ?? [],
            'timeout' => $connection->timeout,
            'test_path' => $connection->test_path,
            'operations_count' => $connection->operations_count ?? $connection->operations()->count(),
            'show_url' => cp_route('statamic-automations.api.connections.show', $connection->id),
            'test_url' => cp_route('statamic-automations.api.connections.test', $connection->id),
            'edit_url' => cp_route('statamic-automations.connections.edit', $connection->id),
        ];

        if ($detailed) {
            $data['operations'] = $connection->operations()->orderBy('id')->get()
                ->map(fn (AutomationConnectionOperation $operation) => $this->presentOperation($operation, $connection))
                ->values();
            $data['used_by'] = $this->usedBy('connection.'.$connection->handle.'.');
        }

        return $data;
    }

    /**
     * Which credential fields are set, never what is in them. For OAuth2 the
     * fields that are not secret (token URL, client id, scope, method, the
     * grant type) are shown as they are, because the form has to edit them.
     *
     * @return array<string, mixed>
     */
    protected function presentAuthConfig(AutomationConnection $connection): array
    {
        $config = $connection->auth_config ?? [];
        $hide = fn ($value) => filled($value) ? self::PLACEHOLDER : '';

        if (! $connection->usesOAuth2()) {
            return array_map($hide, $config);
        }

        $fields = [];

        foreach (is_array($config['token_request_fields'] ?? null) ? $config['token_request_fields'] : [] as $name => $value) {
            $fields[$name] = in_array($name, AutomationConnection::OAUTH2_PLAIN_TOKEN_FIELDS, true) ? $value : $hide($value);
        }

        return [
            'token_url' => $config['token_url'] ?? '',
            'client_id' => $config['client_id'] ?? '',
            'client_secret' => $hide($config['client_secret'] ?? ''),
            'scope' => $config['scope'] ?? '',
            'client_auth_method' => $config['client_auth_method'] ?? 'client_secret_basic',
            'token_request_fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    protected function presentOperation(AutomationConnectionOperation $operation, ?AutomationConnection $connection = null): array
    {
        $connection ??= $operation->automationConnection;

        return [
            'id' => $operation->id,
            'handle' => $operation->handle,
            'node_type' => 'connection.'.$connection->handle.'.'.$operation->handle,
            'name' => $operation->name,
            'description' => $operation->description,
            'method' => $operation->method,
            'path' => $operation->path,
            'query' => $operation->query ?? [],
            'body' => $operation->body ?? [],
            'inputs' => $operation->inputs ?? [],
            'response_map' => $operation->response_map ?? [],
            'fail_on_error_status' => (bool) $operation->fail_on_error_status,
            'body_mode' => $operation->sendsRawBody() ? 'raw' : 'form_json',
            'content_type' => $operation->content_type,
            'raw_body' => $operation->raw_body,
            'headers' => $operation->headers ?? [],
            'response_format' => $operation->responseFormat(),
        ];
    }

    /**
     * The automations with a node of this connection, so the CP can warn
     * before the connection is deleted.
     *
     * @return array<int, array{id: int, name: string, handle: string}>
     */
    protected function usedBy(string $typePrefix): array
    {
        // Narrowed again in PHP: `_` in a snake_case handle is a LIKE wildcard.
        $ids = AutomationNode::query()
            ->where('type', 'like', $typePrefix.'%')
            ->get(['automation_id', 'type'])
            ->filter(fn (AutomationNode $node) => str_starts_with((string) $node->type, $typePrefix))
            ->pluck('automation_id')
            ->unique();

        return Automation::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'handle'])
            ->map(fn (Automation $automation) => [
                'id' => $automation->id,
                'name' => $automation->name,
                'handle' => $automation->handle,
            ])
            ->all();
    }
}
