<?php

namespace Goldnead\StatamicAutomations\Contracts;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Support\ActionResult;

interface AutomationAction extends AutomationNode
{
    /**
     * Execute the action for the given context.
     *
     * Implementations should respect $context->isTestMode() and return
     * a rendered preview rather than producing real side effects when
     * test mode is active.
     *
     * @param  array<string, mixed>  $config  Resolved config from the node, with
     *                                        tokens already replaced where supported.
     */
    public function execute(AutomationContext $context, array $config): ActionResult;
}
