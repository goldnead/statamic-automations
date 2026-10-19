<?php

namespace Goldnead\StatamicAutomations\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapLookup;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicAutomations\Casts\MillisecondDateTime;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Actions\GrantEntitlementAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Triggers\EntitlementGrantedTrigger;
use Goldnead\StatamicAutomations\Integrations\Insights\RunMetric;
use Goldnead\StatamicAutomations\Integrations\Insights\Runs;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Nodes\Actions\CallAutomationAction;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitForAutomationNode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The automations as objects a funnels map can point at.
 *
 * The map keeps `type` and `id` and asks here for everything else. The id is the
 * handle, the same key the automation's sub-automation references and the
 * Control Panel use, so a map reference and a flow never disagree about which
 * automation is meant.
 *
 * Definitions are read through the repository, not Eloquent, so both storage
 * drivers work: a `flat_file` install has no rows to query.
 *
 * The metric is the figure the insights addon calls "Runs" ({@see Runs}),
 * narrowed to one automation. The rule is the same one: a run counts on
 * `started_at`, only inside the period, never past now, and never a test run.
 * Runs are linked by `automation_uuid`, the one reference a flat-file definition
 * has. The bounds are formatted with the column's millisecond format, for the
 * reason {@see RunMetric::untilNow()}
 * gives.
 */
class AutomationMapObjects implements ProvidesMapEdges, ProvidesMapObjects
{
    public const TYPE = 'automation';

    public function __construct(protected AutomationRepository $automations) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function typeLabel(): string
    {
        return __('statamic-automations::automations.map_type_label');
    }

    /** @return array<int, MapObject> */
    public function objects(?string $query = null, int $limit = 50): array
    {
        $needle = Str::lower(trim((string) $query));

        return $this->automations->all()
            ->filter(fn (Automation $automation) => $needle === ''
                || Str::contains(Str::lower((string) $automation->name), $needle)
                || Str::contains(Str::lower((string) $automation->handle), $needle))
            ->sortBy(fn (Automation $automation) => Str::lower((string) $automation->name))
            ->take(max(1, $limit))
            ->map(fn (Automation $automation) => $this->toObject($automation))
            ->values()
            ->all();
    }

    public function find(string $id): ?MapObject
    {
        $automation = $this->resolve($id);

        return $automation ? $this->toObject($automation) : null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        $automation = $this->resolve($id);

        if (! $automation) {
            return null;
        }

        $zone = date_default_timezone_get();
        $format = MillisecondDateTime::FORMAT;

        $count = AutomationRun::query()
            ->where('automation_uuid', $automation->uuid)
            ->where('is_test', false)
            ->where('started_at', '>=', $period->from->setTimezone($zone)->format($format))
            ->where('started_at', '<', $period->to->setTimezone($zone)->format($format))
            ->where('started_at', '<=', Carbon::now($zone)->format($format))
            ->count();

        return new MapMetric((int) $count, __('statamic-automations::insights.runs'), $period);
    }

    /**
     * The handle, or the uuid for a definition that somehow has none.
     */
    protected function idOf(Automation $automation): string
    {
        return $automation->handle !== null && $automation->handle !== ''
            ? (string) $automation->handle
            : (string) $automation->uuid;
    }

    /**
     * Every edge the automations imply, read from their own step configuration.
     * Nothing is stored: an edge exists because a step says so.
     *
     * @return iterable<MapEdge>
     */
    public function edges(): iterable
    {
        $edges = [];

        foreach ($this->automations->all() as $automation) {
            foreach ($this->edgesOf($automation) as $edge) {
                $edges[$edge->key()] = $edge;
            }
        }

        return array_values($edges);
    }

    /** @return list<MapEdge> */
    protected function edgesOf(Automation $automation): array
    {
        $edges = [];

        foreach ($automation->nodes as $node) {
            $edge = match ($node->type) {
                WaitForAutomationNode::handle() => $this->waitEdge($automation, $node),
                CallAutomationAction::handle() => $this->callEdge($automation, $node),
                EntitlementGrantedTrigger::handle() => $this->entitlementEdge($automation, $node, 'triggers'),
                GrantEntitlementAction::handle() => $this->entitlementEdge($automation, $node, 'grants'),
                default => null,
            };

            if ($edge !== null) {
                $edges[] = $edge;
            }
        }

        return $edges;
    }

    /** A waited-for automation runs first: the edge points from it to the waiting one. */
    protected function waitEdge(Automation $automation, AutomationNode $node): ?MapEdge
    {
        $written = $this->writtenAutomation($node);

        if ($written === null) {
            return null;
        }

        $awaited = $this->resolve($written);

        return new MapEdge(
            fromType: self::TYPE,
            fromId: $awaited ? $this->idOf($awaited) : $written,
            toType: self::TYPE,
            toId: $this->idOf($automation),
            kind: 'waits_for',
            label: __('statamic-automations::automations.map_edge_waits_for'),
            derivedFrom: 'wait_for_automation step of '.$this->idOf($automation),
            dashed: true,
        );
    }

    /** The automation starts the one it calls. toLabel keeps the written text for a dead reference. */
    protected function callEdge(Automation $automation, AutomationNode $node): ?MapEdge
    {
        $written = $this->writtenAutomation($node);

        if ($written === null) {
            return null;
        }

        $called = $this->resolve($written);

        return new MapEdge(
            fromType: self::TYPE,
            fromId: $this->idOf($automation),
            toType: self::TYPE,
            toId: $called ? $this->idOf($called) : $written,
            kind: 'starts',
            label: __('statamic-automations::automations.map_edge_starts'),
            derivedFrom: 'call_automation step of '.$this->idOf($automation),
            toLabel: $written,
        );
    }

    /**
     * A trigger on "entitlement granted" or a grant step for a product slug. The
     * slug names a lead magnet or a product in another addon; when neither is
     * installed or neither has that slug, there is no edge. Nothing is guessed.
     */
    protected function entitlementEdge(Automation $automation, AutomationNode $node, string $kind): ?MapEdge
    {
        $slug = $this->productSlugOf($node);
        $target = $slug !== null ? $this->productObjectFor($slug) : null;

        if ($slug === null || $target === null) {
            return null;
        }

        $handle = $this->idOf($automation);
        $isTrigger = $kind === 'triggers';

        return new MapEdge(
            fromType: $isTrigger ? $target[0] : self::TYPE,
            fromId: $isTrigger ? $target[1] : $handle,
            toType: $isTrigger ? self::TYPE : $target[0],
            toId: $isTrigger ? $handle : $target[1],
            kind: $kind,
            label: __($isTrigger
                ? 'statamic-automations::automations.map_edge_triggers'
                : 'statamic-automations::automations.map_edge_grants'),
            derivedFrom: $isTrigger
                ? "entitlement-granted trigger of {$handle}, product_slug {$slug}"
                : "grant_entitlement step of {$handle}, product_slug {$slug}",
        );
    }

    /**
     * The object a product slug means: a lead magnet with that slug first, then a
     * product. Null when neither provider is installed or neither knows the slug.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function productObjectFor(string $slug): ?array
    {
        $registry = app(MapObjectRegistry::class);

        foreach (['lead_magnet', 'product'] as $type) {
            if ($registry->lookup($type, $slug)->status === MapLookup::FOUND) {
                return [$type, $slug];
            }
        }

        return null;
    }

    /**
     * Broken references this automation carries, in words for the map. A wait or
     * call step whose automation does not resolve is a problem; nothing else is
     * checked here.
     *
     * @return list<string>
     */
    protected function problemsOf(Automation $automation): array
    {
        $problems = [];

        foreach ($automation->nodes as $node) {
            if (! in_array($node->type, [WaitForAutomationNode::handle(), CallAutomationAction::handle()], true)) {
                continue;
            }

            $written = $this->writtenAutomation($node);

            if ($written === null || $this->resolve($written) !== null) {
                continue;
            }

            $problems[] = sprintf(
                'step "%s" points at automation "%s", which does not exist',
                $this->stepName($node),
                $written,
            );
        }

        return $problems;
    }

    /** The automation handle or uuid a wait or call step writes, trimmed, or null when empty. */
    protected function writtenAutomation(AutomationNode $node): ?string
    {
        $value = $node->getConfigValue('automation');

        if (! is_scalar($value)) {
            return null;
        }

        $written = trim((string) $value);

        return $written === '' ? null : $written;
    }

    /** The product slug an entitlement trigger or grant step names, or null for "every product". */
    protected function productSlugOf(AutomationNode $node): ?string
    {
        $value = $node->getConfigValue('product_slug');

        if (! is_scalar($value)) {
            return null;
        }

        $slug = trim((string) $value);

        return $slug === '' ? null : $slug;
    }

    protected function stepName(AutomationNode $node): string
    {
        $label = trim((string) $node->label);

        return $label !== '' ? $label : (string) $node->node_key;
    }

    protected function toObject(Automation $automation): MapObject
    {
        return new MapObject(
            type: self::TYPE,
            id: $this->idOf($automation),
            label: $automation->name !== '' ? (string) $automation->name : $this->idOf($automation),
            cpUrl: cp_route('statamic-automations.automations.edit', $automation->id),
            enabled: (bool) $automation->enabled,
            problems: $this->problemsOf($automation),
        );
    }

    /** The automation behind a map id, or null. findByRef also takes the uuid and the numeric id. */
    protected function resolve(string $id): ?Automation
    {
        return $this->automations->findByRef($id);
    }
}
