<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Models\AutomationConnectionOperation;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Facades\Http;

/**
 * Runs one operation of a connection set up in the CP.
 *
 * One class serves every `connection.<connection>.<operation>` handle, like
 * the generic EventTrigger does for events. The registry describes each handle
 * from the operation's row ({@see AutomationConnectionOperation::nodeEntry()}),
 * so the static methods here are only the fallback for the bare class, and the
 * engine hands the handle in as `$config['_node_type']`.
 *
 * The node config holds the operation's inputs and nothing else — already
 * token-resolved by the executor. Path, query and body come from the operation
 * and only ever see `{{ input.x }}`. The credential is read from the connection
 * at the moment of the call and put on the request, never into the config or
 * the context, so it cannot reach the run log; and the output is masked on top,
 * in case a service echoes it back.
 */
class ConnectionOperationAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'connection';
    }

    public static function label(): string
    {
        return 'Connection operation';
    }

    public static function description(): ?string
    {
        return 'Calls an operation of a connection set up under Automations → Connections.';
    }

    public static function group(): string
    {
        return 'Connections';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public static function outputSchema(): array
    {
        return [
            'status' => 'integer',
            'body' => 'mixed',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $type = (string) ($config['_node_type'] ?? '');
        $operation = AutomationConnectionOperation::findByNodeType($type);

        if ($operation === null) {
            return ActionResult::failed("The connection operation '{$type}' no longer exists.");
        }

        $connection = $operation->automationConnection;

        $inputs = [];
        $missing = [];

        foreach ($operation->inputSchema() as $field) {
            $value = $config[$field['handle']] ?? null;

            if ($value === null || $value === '') {
                $value = $field['default'] ?? null;
            }

            if ($field['required'] && ($value === null || $value === '')) {
                $missing[] = $field['handle'];
            }

            $inputs[$field['handle']] = $value;
        }

        $path = $this->fill((string) $operation->path, $inputs, encode: true);
        $query = array_filter(
            array_map(fn ($value) => $this->fill($value, $inputs), AutomationConnectionOperation::keyValue($operation->query)),
            fn ($value) => $value !== null && $value !== '',
        );
        $body = array_map(fn ($value) => $this->fill($value, $inputs), AutomationConnectionOperation::keyValue($operation->body));

        $method = strtoupper((string) $operation->method);
        $url = $connection->url($path);
        $headers = $connection->defaultHeaders();

        $preview = [
            'method' => $method,
            'url' => $query === [] ? $url : $url.'?'.http_build_query($query),
            // Names only: the values of the auth headers never leave the call.
            'headers' => array_keys([...$headers, ...$connection->authHeaders()]),
            'body' => $body,
        ];

        // A test run starts from an empty context, so a token-fed input may
        // well be empty; that is reported, not failed on.
        if ($context->isTestMode()) {
            return ActionResult::success($connection->mask([
                'preview' => $preview + ($missing === [] ? [] : ['missing_inputs' => $missing]),
                'note' => 'Test mode — request not sent.',
            ]));
        }

        if ($missing !== []) {
            return ActionResult::failed('Required input missing: '.implode(', ', $missing).'.');
        }

        try {
            $options = ['query' => $query];

            if ($body !== [] && $method !== 'GET') {
                $options['json'] = $body;
            }

            $response = Http::withHeaders([...$headers, ...$connection->authHeaders()])
                ->timeout(max(1, (int) $connection->timeout))
                ->send($method, $url, $options);
        } catch (\Throwable $e) {
            return ActionResult::failed((string) $connection->mask($e->getMessage()), $connection->mask(['preview' => $preview]));
        }

        $json = $response->json();
        $output = ['status' => $response->status()];

        foreach (AutomationConnectionOperation::keyValue($operation->response_map) as $name => $dotPath) {
            $output[$name] = data_get($json, (string) $dotPath);
        }

        $output['body'] = is_array($json) ? $json : $response->body();
        $output = $connection->mask($output);

        if (! $response->successful() && $operation->fail_on_error_status) {
            return ActionResult::failed("{$type} answered with HTTP {$response->status()}.", $output);
        }

        return ActionResult::success($output);
    }

    /**
     * Replace `{{ input.x }}` in a template. A value that is exactly one token
     * keeps its type, so a number or toggle input stays a number or a boolean
     * in the JSON body. Path segments are URL-encoded.
     *
     * @param  array<string, mixed>  $inputs
     */
    protected function fill(mixed $template, array $inputs, bool $encode = false): mixed
    {
        if (! is_string($template)) {
            return $template;
        }

        $pattern = '/\{\{\s*input\.([A-Za-z0-9_]+)\s*\}\}/';

        if (! $encode && preg_match('/^'.trim($pattern, '/').'$/', $template, $whole)) {
            return $inputs[$whole[1]] ?? null;
        }

        return preg_replace_callback($pattern, function (array $match) use ($inputs, $encode) {
            $value = $inputs[$match[1]] ?? '';
            $value = is_scalar($value) ? (string) $value : json_encode($value);

            return $encode ? rawurlencode((string) $value) : (string) $value;
        }, $template);
    }
}
