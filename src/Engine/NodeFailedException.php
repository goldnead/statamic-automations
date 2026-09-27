<?php

namespace Goldnead\StatamicAutomations\Engine;

/**
 * A node returned a failed result and had no `_on_error: continue`.
 *
 * Its own type so a Loop set to `on_item_error: continue` can end the item
 * on exactly this and nothing else: a database error or a bug in the engine
 * is not an item that "failed", and has to fail the run.
 */
class NodeFailedException extends \RuntimeException
{
    public function __construct(
        public readonly string $nodeKey,
        public readonly string $reason,
    ) {
        parent::__construct("Node '{$nodeKey}' failed: {$reason}");
    }
}
