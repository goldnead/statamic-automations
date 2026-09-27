<?php

namespace Goldnead\StatamicAutomations\Support;

use Statamic\View\Antlers\Language\Nodes\AbstractNode;
use Statamic\View\Antlers\Language\Runtime\Tracing\RuntimeTracerContract;

/**
 * Stops a sandboxed render that runs away ({@see SandboxedAntlers}): too
 * many nodes entered (nested loops over large lists), or a single node
 * whose rendered content is already larger than the whole text may be.
 */
class SandboxBudget implements RuntimeTracerContract
{
    protected int $entered = 0;

    public function __construct(protected int $maxNodes, protected int $maxBytes) {}

    public function onEnter(AbstractNode $node)
    {
        if (++$this->entered > $this->maxNodes) {
            throw new SandboxLimitExceeded("The template ran more than {$this->maxNodes} steps.");
        }
    }

    public function onExit(AbstractNode $node, $runtimeContent)
    {
        if (is_string($runtimeContent) && strlen($runtimeContent) > $this->maxBytes) {
            throw new SandboxLimitExceeded('The text is longer than '.intdiv($this->maxBytes, 1024).' KB.');
        }
    }

    public function onRenderComplete() {}
}
