<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Http\Requests\ScopesUniquenessToBrand;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Models\AutomationConnectionOperation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

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

        $connection = AutomationConnection::create($data);

        return response()->json(['data' => $this->present($connection, detailed: true)], 201);
    }

    public function update(Request $request, AutomationConnection $automationConnection): JsonResponse
    {
        $this->authorizeAction(self::PERMISSION);

        $data = $this->validateConnection($request, $automationConnection);
        $data['auth_config'] = $this->authConfig($data['auth_type'], $request->input('auth_config'), $automationConnection);

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
     */
    public function test(AutomationConnection $automationConnection): JsonResponse
    {
        $this->authorizeAction(self::PERMISSION);

        $started = microtime(true);

        try {
            $response = Http::withHeaders([...$automationConnection->defaultHeaders(), ...$automationConnection->authHeaders()])
                ->timeout(max(1, (int) $automationConnection->timeout))
                ->get($automationConnection->url((string) ($automationConnection->test_path ?: '/')));
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
            'base_url' => ['required', 'url', 'max:2048'],
            'auth_type' => ['required', Rule::in(AutomationConnection::AUTH_TYPES)],
            'auth_config' => ['nullable', 'array'],
            'default_headers' => ['nullable', 'array'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
            'test_path' => ['nullable', 'string', 'max:1024'],
        ]);

        $data['timeout'] ??= 15;

        return $data;
    }

    /** @return array<string, mixed> */
    protected function validateOperation(
        Request $request,
        AutomationConnection $connection,
        ?AutomationConnectionOperation $operation = null,
    ): array {
        $unique = Rule::unique('automation_connection_operations', 'handle')->where('connection_id', $connection->id);

        $data = $request->validate([
            'handle' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                $operation ? $unique->ignore($operation->id) : $unique,
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'method' => ['required', Rule::in(AutomationConnectionOperation::METHODS)],
            'path' => ['required', 'string', 'max:2048'],
            'query' => ['nullable', 'array'],
            'body' => ['nullable', 'array'],
            'inputs' => ['nullable', 'array'],
            'inputs.*.handle' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'inputs.*.label' => ['nullable', 'string', 'max:255'],
            'inputs.*.type' => ['required', Rule::in(AutomationConnectionOperation::INPUT_TYPES)],
            'inputs.*.required' => ['nullable', 'boolean'],
            'inputs.*.default' => ['nullable'],
            'inputs.*.options' => ['nullable', 'array'],
            'response_map' => ['nullable', 'array'],
            'fail_on_error_status' => ['nullable', 'boolean'],
        ]);

        $data['fail_on_error_status'] ??= true;

        return $data;
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

        foreach (AutomationConnection::AUTH_FIELDS[$type] ?? [] as $key) {
            $value = $input[$key] ?? null;

            $config[$key] = is_string($value) && $value !== '' && $value !== self::PLACEHOLDER
                ? $value
                : (string) ($kept[$key] ?? '');
        }

        return $config === [] ? null : $config;
    }

    /** @return array<string, mixed> */
    protected function present(AutomationConnection $connection, bool $detailed = false): array
    {
        $data = [
            'id' => $connection->id,
            'handle' => $connection->handle,
            'name' => $connection->name,
            'base_url' => $connection->base_url,
            'auth_type' => $connection->auth_type,
            // Which fields are set, never what is in them.
            'auth_config' => array_map(
                fn ($value) => filled($value) ? self::PLACEHOLDER : '',
                $connection->auth_config ?? [],
            ),
            'auth_configured' => array_filter($connection->auth_config ?? []) !== [],
            'default_headers' => $connection->default_headers ?? [],
            'timeout' => $connection->timeout,
            'test_path' => $connection->test_path,
            'operations_count' => $connection->operations_count ?? $connection->operations()->count(),
            'show_url' => cp_route('statamic-automations.api.connections.show', $connection->id),
            'test_url' => cp_route('statamic-automations.api.connections.test', $connection->id),
        ];

        if ($detailed) {
            $data['operations'] = $connection->operations()->orderBy('id')->get()
                ->map(fn (AutomationConnectionOperation $operation) => $this->presentOperation($operation, $connection))
                ->values();
            $data['used_by'] = $this->usedBy('connection.'.$connection->handle.'.');
        }

        return $data;
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
