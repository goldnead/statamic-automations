<?php

namespace Goldnead\StatamicAutomations\Context;

/**
 * Helper that assembles an AutomationContext from event data.
 *
 * Triggers normally build their own context, but ContextBuilder is
 * useful for manual triggers, tests and the sample-data CP endpoint.
 */
class ContextBuilder
{
    /**
     * Build a context with optional environment metadata.
     *
     * @param  array<string, mixed>  $data
     */
    public function build(array $data = [], bool $testMode = false): AutomationContext
    {
        $context = new AutomationContext($data, $testMode);

        if (! $context->has('site.handle')) {
            $context->set('site.handle', $this->currentSiteHandle());
        }

        if (! $context->has('meta.timestamp')) {
            $context->set('meta.timestamp', now()->toIso8601String());
        }

        return $context;
    }

    protected function currentSiteHandle(): string
    {
        if (class_exists(\Statamic\Facades\Site::class)) {
            try {
                return \Statamic\Facades\Site::current()->handle();
            } catch (\Throwable) {
                // Fall through to default
            }
        }

        return 'default';
    }
}
