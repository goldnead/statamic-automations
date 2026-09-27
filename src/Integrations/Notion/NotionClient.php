<?php

namespace Goldnead\StatamicAutomations\Integrations\Notion;

use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads from the Notion API with the credential of a connection.
 *
 * ## The credential comes from a connection
 *
 * A connection set up under Automations, Connections, with bearer auth and the
 * integration token of a Notion integration. This client takes its auth
 * headers, its default headers and its timeout, and nothing else: the address
 * is always {@see BASE_URL}. A connection whose base URL points elsewhere
 * cannot send the token there through these nodes.
 *
 * ## Read only
 *
 * `POST data_sources/{id}/query` is a read in Notion's API, like the two GETs.
 * There is no write here, which is why the Notion nodes run in a test run
 * exactly as in a real one.
 *
 * ## One version
 *
 * `Notion-Version: 2025-09-03`, the version that introduced data sources.
 * Under older versions `data_sources/…` does not exist, and a database query
 * would need the database ID instead. The header is set last, so a default
 * header on the connection cannot downgrade it.
 */
class NotionClient
{
    public const BASE_URL = 'https://api.notion.com/v1/';

    public const VERSION = '2025-09-03';

    /** Rows or blocks per request; Notion's maximum. */
    public const PAGE_SIZE = 100;

    public function __construct(protected AutomationConnection $connection) {}

    /**
     * The connection with this handle, or null.
     */
    public static function connection(string $handle): ?AutomationConnection
    {
        if ($handle === '' || ! AutomationConnection::schemaReady()) {
            return null;
        }

        return AutomationConnection::query()->where('handle', $handle)->first();
    }

    public function hasCredential(): bool
    {
        $headers = $this->connection->authHeaders();

        return $headers !== [] && ! in_array(trim((string) reset($headers)), ['', 'Bearer', 'Basic'], true);
    }

    /**
     * Query a data source, following `next_cursor` for at most `$maxPages`
     * requests.
     *
     * @param  array<string, mixed>  $body  filter and sorts
     * @return array{results: list<array<string, mixed>>, has_more: bool}
     */
    public function queryDataSource(string $id, array $body, int $maxPages): array
    {
        $results = [];
        $cursor = null;
        $requests = 0;

        do {
            $answer = $this->send('POST', 'data_sources/'.rawurlencode($id).'/query', array_filter([
                ...$body,
                'page_size' => self::PAGE_SIZE,
                'start_cursor' => $cursor,
            ], fn ($value) => $value !== null));

            $requests++;
            array_push($results, ...$this->results($answer));
            $cursor = ($answer['has_more'] ?? false) ? ($answer['next_cursor'] ?? null) : null;
        } while ($cursor !== null && $requests < $maxPages);

        return ['results' => $results, 'has_more' => $cursor !== null];
    }

    /**
     * @return array<string, mixed>
     */
    public function page(string $id): array
    {
        return $this->send('GET', 'pages/'.rawurlencode($id));
    }

    /**
     * Every child block of a block or page, over all pages of the answer.
     *
     * @return list<array<string, mixed>>
     */
    public function children(string $blockId): array
    {
        $blocks = [];
        $cursor = null;

        do {
            $answer = $this->send('GET', 'blocks/'.rawurlencode($blockId).'/children', array_filter([
                'page_size' => self::PAGE_SIZE,
                'start_cursor' => $cursor,
            ], fn ($value) => $value !== null));

            array_push($blocks, ...$this->results($answer));
            $cursor = ($answer['has_more'] ?? false) ? ($answer['next_cursor'] ?? null) : null;
        } while ($cursor !== null);

        return $blocks;
    }

    /**
     * One request. A non-2xx answer throws a {@see NotionException} carrying
     * Notion's own message, with the credential masked out of it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, array $payload = []): array
    {
        $headers = [
            ...$this->connection->defaultHeaders(),
            ...$this->connection->authHeaders(),
            'Notion-Version' => self::VERSION,
        ];

        try {
            $request = Http::baseUrl(self::BASE_URL)
                ->withHeaders($headers)
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout(max(1, (int) $this->connection->timeout))
                // 429 and 5xx are passing: try twice more, with a pause.
                ->retry([1000, 3000], when: fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())), throw: false);

            /** @var Response $response */
            $response = $method === 'GET'
                ? $request->get($path, $payload)
                : $request->send($method, $path, ['json' => $payload === [] ? new \stdClass : $payload]);
        } catch (Throwable $e) {
            throw new NotionException((string) $this->connection->mask('Notion could not be reached: '.$e->getMessage()));
        }

        $json = $response->json();

        if (! $response->successful()) {
            $message = is_array($json) && isset($json['message']) ? (string) $json['message'] : 'no message';
            $code = is_array($json) && isset($json['code']) ? (string) $json['code'] : 'error';

            throw new NotionException(
                (string) $this->connection->mask("Notion answered {$method} {$path} with HTTP {$response->status()} ({$code}): {$message}"),
                $response->status(),
            );
        }

        if (! is_array($json)) {
            throw new NotionException("Notion answered {$method} {$path} with something that is not JSON.", $response->status());
        }

        return $json;
    }

    /**
     * The `results` of a list answer. An answer without them is not an empty
     * list: it is a shape this client does not know, and reading it as "no
     * rows" would be the quiet failure.
     *
     * @param  array<string, mixed>  $answer
     * @return list<array<string, mixed>>
     */
    protected function results(array $answer): array
    {
        if (! isset($answer['results']) || ! is_array($answer['results'])) {
            throw new NotionException('Notion answered without a results list; this node does not know that shape.');
        }

        return array_values(array_filter($answer['results'], 'is_array'));
    }
}
