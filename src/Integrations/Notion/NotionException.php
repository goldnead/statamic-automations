<?php

namespace Goldnead\StatamicAutomations\Integrations\Notion;

use RuntimeException;

/**
 * A Notion request that did not go through. The message is safe for the run
 * log: the client masks the credential out of it before throwing.
 */
class NotionException extends RuntimeException
{
    public function isNotFound(): bool
    {
        return $this->getCode() === 404;
    }
}
