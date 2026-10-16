<?php

namespace Goldnead\StatamicAutomations\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A write-only view of a file resource for Guzzle's `sink` option.
 *
 * It counts the bytes as they are written and stops the transfer at the first
 * byte over the limit, so a download never grows past it on disk and never
 * sits in memory as a whole.
 */
class CountingSink implements StreamInterface
{
    use StreamDecoratorTrait;

    protected int $written = 0;

    /** @param  resource  $resource */
    public function __construct($resource, protected int $maxBytes)
    {
        $this->stream = Utils::streamFor($resource);
    }

    /** The resource belongs to the caller: closing this view must not close it. */
    public function __destruct()
    {
        $this->stream->detach();
    }

    public function write(string $string): int
    {
        if ($this->written + strlen($string) > $this->maxBytes) {
            throw new SizeLimitExceeded(sprintf('The download is larger than the limit of %s MB.', rtrim(rtrim(number_format($this->maxBytes / 1048576, 2, '.', ''), '0'), '.')));
        }

        $this->written += strlen($string);

        return $this->stream->write($string);
    }

    public function bytesWritten(): int
    {
        return $this->written;
    }
}
