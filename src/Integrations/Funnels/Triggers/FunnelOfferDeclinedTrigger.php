<?php

namespace Goldnead\StatamicAutomations\Integrations\Funnels\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

/**
 * Somebody said no to an offer in a funnel: the upsell or downsell was
 * declined.
 *
 * Declining is the more common answer and not an error. Until the funnels
 * addon fired `FunnelOfferDeclined` there was no moment to hang a follow-up
 * on, only the path marker. `step.offer` is the offer that was turned down,
 * which is what a "still thinking about it?" mail names.
 *
 * `email` is copied to the top level on purpose: it is the default subject
 * path, so "only once per person" works on this trigger without configuring a
 * subject key.
 */
class FunnelOfferDeclinedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'funnels.offer_declined';
    }

    public static function label(): string
    {
        return 'Funnel Offer Declined';
    }

    public static function description(): ?string
    {
        return 'Triggered on every decline of an offer in a funnel. After a paid purchase funnels.upsell_declined fires on the same click; use that one to reach buyers only.';
    }

    public static function group(): string
    {
        return 'Funnels';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'funnel',
                'label' => 'Funnel',
                'type' => 'select',
                'options_source' => 'funnels.funnels',
                'required' => false,
                'help' => 'Leave empty for every funnel.',
            ],
            [
                'handle' => 'step',
                'label' => 'Step',
                'type' => 'text',
                'required' => false,
                'help' => 'The step key. Leave empty for every offer step of the funnel.',
            ],
            [
                'handle' => 'offer',
                'label' => 'Offer',
                'type' => 'select',
                'options_source' => 'offers.offers',
                'required' => false,
                'help' => 'Only when this offer was declined. Leave empty for every offer.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'visit' => [
                'id' => 'string',
                'email' => 'string',
                'name' => 'string',
                'funnel' => 'string',
                'funnel_title' => 'string',
            ],
            'step' => [
                'key' => 'string',
                'type' => 'string',
                'label' => 'string',
                'offer' => 'string',
            ],
            'email' => 'string',
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        foreach ([
            'funnel' => $this->visitOf($event)['funnel'] ?? null,
            'step' => $this->stepOf($event)['key'] ?? null,
            'offer' => $this->stepOf($event)['offer'] ?? null,
        ] as $field => $actual) {
            $wanted = is_string($config[$field] ?? null) ? trim($config[$field]) : '';

            if ($wanted !== '' && $actual !== $wanted) {
                return false;
            }
        }

        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $visit = $this->visitOf($event);

        return AutomationContext::make([
            'visit' => $visit,
            'step' => $this->stepOf($event),
            'email' => $visit['email'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function visitOf(object|array $event): array
    {
        $visit = is_array($event) ? ($event['visit'] ?? null) : ($event->visit ?? null);

        if (is_array($visit)) {
            return $visit;
        }

        if (! is_object($visit)) {
            return [];
        }

        return [
            'id' => $visit->id ?? null,
            'email' => $visit->email ?? null,
            'name' => $visit->name ?? null,
            'funnel' => $visit->funnel->handle ?? null,
            'funnel_title' => $visit->funnel->title ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function stepOf(object|array $event): array
    {
        $step = is_array($event) ? ($event['step'] ?? null) : ($event->step ?? null);

        if (is_array($step)) {
            return $step;
        }

        if (! is_object($step)) {
            return [];
        }

        return [
            'key' => $step->node_key ?? null,
            'type' => $step->type ?? null,
            'label' => $step->label ?? null,
            'offer' => $this->offerOf($step),
        ];
    }

    /**
     * The offer handle an offer step sells.
     *
     * A real `FunnelStep` answers `config('offer')`; its `config` attribute is
     * the same array. Both are asked, in that order, so the stub-shaped object
     * of a test and the model of a site read the same.
     */
    protected function offerOf(object $step): ?string
    {
        try {
            $offer = method_exists($step, 'config')
                ? $step->config('offer')
                : (is_array($step->config ?? null) ? ($step->config['offer'] ?? null) : null);
        } catch (\Throwable) {
            $offer = null;
        }

        return is_string($offer) && $offer !== '' ? $offer : null;
    }
}
