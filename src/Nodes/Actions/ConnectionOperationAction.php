<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Models\AutomationConnectionOperation;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\HostGuard;
use Goldnead\StatamicAutomations\Support\UnsafeHostException;
use Goldnead\StatamicAutomations\Support\XmlToArray;
use Illuminate\Http\Client\Response;
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
            'headers' => 'array',
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

        if (($dotted = $this->dotSegmentInput((string) $operation->path, $inputs)) !== null) {
            return ActionResult::failed("The input '{$dotted}' may not be '.' or '..' where it is used in the path.");
        }

        $path = $this->fill((string) $operation->path, $inputs, encode: true);
        // An empty optional input leaves its parameter out rather than
        // sending `?name=`.
        $query = array_filter(
            array_map(fn ($value) => $this->fill($value, $inputs), AutomationConnectionOperation::keyValue($operation->query)),
            fn ($value) => $value !== null && $value !== '',
        );
        $raw = $operation->sendsRawBody();
        $body = $raw
            ? $this->fillRaw((string) $operation->raw_body, $inputs)
            : array_map(fn ($value) => $this->fill($value, $inputs), AutomationConnectionOperation::keyValue($operation->body));
        $contentType = trim((string) $operation->content_type) ?: 'text/plain; charset=utf-8';

        $method = strtoupper((string) $operation->method);
        $url = $connection->url($path, $query);

        // The operation's own headers over the connection's defaults, and the
        // credential over both: an operation cannot replace the auth header
        // with a value of its own, nor read it.
        $operationHeaders = array_map(
            fn ($value) => is_scalar($value = $this->fill($value, $inputs)) ? (string) $value : (string) json_encode($value),
            AutomationConnectionOperation::keyValue($operation->headers),
        );
        $headers = [...$connection->defaultHeaders(), ...$operationHeaders];

        $preview = [
            'method' => $method,
            'url' => $url,
            // Names only: the values of the auth headers never leave the call.
            'headers' => array_keys([...$headers, ...$connection->authHeaders()]),
            'body' => $body,
        ] + ($raw ? ['content_type' => $contentType] : []);

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

        // Again at call time, not only on save: the name may resolve elsewhere
        // by now. The options pin the call to the address just checked.
        try {
            $pinned = app(HostGuard::class)->guard($url);
        } catch (UnsafeHostException $e) {
            return ActionResult::failed($e->getMessage(), ['preview' => $connection->mask($preview)]);
        }

        try {
            // No redirects: Guzzle strips only Authorization and Cookie on a
            // redirect to another host, so a custom credential header would
            // follow. A 3xx is answered like any other non-2xx status.
            $request = Http::withOptions($pinned)
                ->withHeaders([...$headers, ...$connection->authHeaders()])
                ->withoutRedirecting()
                ->timeout(max(1, (int) $connection->timeout));

            $options = [];

            if ($raw) {
                // A raw body goes out even on GET: some APIs (and CalDAV's
                // REPORT) want one, and the operator typed it on purpose.
                if ($body !== '') {
                    $request = $request->withBody((string) $body, $contentType);
                }
            } elseif ($body !== [] && $method !== 'GET') {
                $options = ['json' => $body];
            }

            $response = $request->send($method, $url, $options);
        } catch (\Throwable $e) {
            return ActionResult::failed((string) $connection->mask($e->getMessage()), $connection->mask(['preview' => $preview]));
        }

        $parsed = $this->parseResponse($response, $operation->responseFormat());
        $output = ['status' => $response->status()];

        foreach (AutomationConnectionOperation::keyValue($operation->response_map) as $name => $dotPath) {
            $output[$name] = data_get($parsed, (string) $dotPath);
        }

        $output['headers'] = $this->responseHeaders($response);
        $output['body'] = is_array($parsed) ? $parsed : $response->body();
        $output = $connection->mask($output);

        if (! $response->successful() && $operation->fail_on_error_status) {
            return ActionResult::failed("{$type} answered with HTTP {$response->status()}.", $output);
        }

        return ActionResult::success($output);
    }

    /**
     * The handle of an input that would put a `.` or `..` segment into the
     * path — also percent-encoded, also after a slash — or null. The value is
     * encoded before it goes in, but a server or proxy that decodes it first
     * would walk out of the API's path.
     *
     * @param  array<string, mixed>  $inputs
     */
    protected function dotSegmentInput(string $path, array $inputs): ?string
    {
        preg_match_all('/\{\{\s*input\.([A-Za-z0-9_]+)\s*\}\}/', $path, $matches);

        foreach (array_unique($matches[1]) as $handle) {
            $value = $inputs[$handle] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $decoded = (string) $value;

            for ($i = 0; $i < 3; $i++) {
                $decoded = rawurldecode($decoded);
            }

            foreach (preg_split('#[/\\\\]#', $decoded) ?: [] as $segment) {
                if ($segment === '.' || $segment === '..') {
                    return $handle;
                }
            }
        }

        return null;
    }

    /**
     * The raw body template with its inputs in. `{{ input.x }}` puts the value
     * in as text; `{{ input.x | json }}` as a JSON literal (a string quoted
     * and escaped, a list as an array), which is how a value goes safely into
     * a JSON body typed by hand; `{{ input.x | xml }}` escapes it for XML
     * text or an attribute. Nothing else is recognised as a filter here.
     *
     * @param  array<string, mixed>  $inputs
     */
    protected function fillRaw(string $template, array $inputs): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*input\.([A-Za-z0-9_]+)\s*(?:\|\s*(json|xml)\s*)?\}\}/',
            function (array $match) use ($inputs) {
                $value = $inputs[$match[1]] ?? null;
                $filter = $match[2] ?? '';

                if ($filter === 'json') {
                    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }

                $text = is_scalar($value) ? (string) $value : ($value === null ? '' : (string) json_encode($value));

                return $filter === 'xml' ? htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') : $text;
            },
            $template,
        );
    }

    /**
     * The answer as an array where it is structured, or null.
     *
     * `auto` goes by the Content-Type (JSON, then XML) and, when that says
     * nothing useful, tries JSON. `text` never parses. An answer that does not
     * parse in the format asked for is null here and stays readable as the
     * raw text in `body`.
     *
     * @return array<mixed>|null
     */
    protected function parseResponse(Response $response, string $format): ?array
    {
        $type = strtolower((string) $response->header('Content-Type'));

        if ($format === 'auto') {
            $format = match (true) {
                str_contains($type, 'json') => 'json',
                str_contains($type, 'xml') => 'xml',
                default => 'json',
            };
        }

        return match ($format) {
            'json' => is_array($json = $response->json()) ? $json : null,
            'xml' => XmlToArray::parse($response->body()),
            default => null,
        };
    }

    /**
     * The response headers, names lowercased, several values joined with a
     * comma the way HTTP allows. `Set-Cookie` is left out: a session a
     * service hands out is a credential, and the run log is no place for it.
     *
     * @return array<string, string>
     */
    protected function responseHeaders(Response $response): array
    {
        $headers = [];

        foreach ($response->headers() as $name => $values) {
            $name = strtolower((string) $name);

            if ($name === 'set-cookie') {
                continue;
            }

            $headers[$name] = implode(', ', array_map('strval', (array) $values));
        }

        ksort($headers);

        return $headers;
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
