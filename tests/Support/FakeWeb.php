<?php

namespace Goldnead\StatamicAutomations\Tests\Support;

use Closure;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * A fake web for the streaming download tests.
 *
 * `Http::fake()` answers before Guzzle's handler runs and never writes to the
 * `sink` option, so a download fetched through it would end up an empty file.
 * This installs a Guzzle handler instead, which does what a real transfer does:
 * it announces the headers (`on_headers`) and writes the body into the sink,
 * and records every request that reaches it.
 */
class FakeWeb
{
    /** @var array<int, FakeRequest> */
    public static array $requests = [];

    /**
     * @param  array<string, array<string, mixed>|FakeSequence|Closure>  $routes  host/path pattern (`*` matches anything) => answer; the first match wins
     */
    public static function fake(array $routes = ['*' => null]): void
    {
        static::$requests = [];

        Http::globalOptions(['handler' => function (RequestInterface $request, array $options) use ($routes) {
            static::$requests[] = $fake = new FakeRequest($request);

            $target = preg_replace('#^https?://#', '', (string) $request->getUri());

            foreach ($routes as $pattern => $answer) {
                if (! fnmatch($pattern, $target)) {
                    continue;
                }

                if ($answer instanceof Closure) {
                    $answer = $answer($fake);
                }

                if ($answer instanceof FakeSequence) {
                    $answer = $answer->next();
                }

                return static::answer($answer ?? static::response(), $options);
            }

            return static::answer(static::response('', 404), $options);
        }]);
    }

    /**
     * @param  string|array<mixed>  $body  an array is sent as JSON
     * @param  array<string, string>  $headers
     * @return array{body: string, status: int, headers: array<string, string>}
     */
    public static function response(string|array $body = '', int $status = 200, array $headers = []): array
    {
        if (is_array($body)) {
            $body = (string) json_encode($body);
            $headers += ['Content-Type' => 'application/json'];
        }

        return ['body' => $body, 'status' => $status, 'headers' => $headers];
    }

    public static function sequence(): FakeSequence
    {
        return new FakeSequence;
    }

    /** @param  array{body: string, status: int, headers: array<string, string>}  $answer */
    protected static function answer(array $answer, array $options): FulfilledPromise
    {
        $response = new PsrResponse($answer['status'], $answer['headers']);

        if (isset($options['sink'])) {
            if (isset($options['on_headers'])) {
                $options['on_headers']($response);
            }

            // The body goes into the sink, as the cURL handler would do it.
            if ($answer['body'] !== '') {
                $options['sink']->write($answer['body']);
            }

            return new FulfilledPromise($response);
        }

        return new FulfilledPromise(new PsrResponse($answer['status'], $answer['headers'], $answer['body']));
    }

    /** @return Collection<int, FakeRequest> */
    public static function recorded(): Collection
    {
        return collect(static::$requests);
    }

    public static function assertNothingSent(): void
    {
        expect(static::$requests)->toBe([]);
    }

    public static function assertSentCount(int $count): void
    {
        expect(static::$requests)->toHaveCount($count);
    }
}

class FakeSequence
{
    /** @var array<int, array<string, mixed>> */
    protected array $answers = [];

    protected int $position = 0;

    public function push(string|array $body = '', int $status = 200, array $headers = []): static
    {
        $this->answers[] = FakeWeb::response($body, $status, $headers);

        return $this;
    }

    /** The next answer; the last one repeats once the list is used up. */
    public function next(): array
    {
        $answer = $this->answers[min($this->position, count($this->answers) - 1)];
        $this->position++;

        return $answer;
    }
}

class FakeRequest
{
    public function __construct(protected RequestInterface $request) {}

    public function url(): string
    {
        return (string) $this->request->getUri();
    }

    /** @return array<int, string> */
    public function header(string $name): array
    {
        return $this->request->getHeader($name);
    }
}
