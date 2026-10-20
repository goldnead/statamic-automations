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
use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\StatamicAutomations\Casts\MillisecondDateTime;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Actions\GrantEntitlementAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Triggers\EntitlementGrantedTrigger;
use Goldnead\StatamicAutomations\Integrations\Insights\RunMetric;
use Goldnead\StatamicAutomations\Integrations\Insights\Runs;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Nodes\Actions\CallAutomationAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitForAutomationNode;
use Goldnead\StatamicAutomations\Support\AuditLogger;
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
     * The pairs this addon can write: a product or a lead magnet starts an
     * automation by being the slug of its "access granted" trigger.
     *
     * @return list<array{from_type: string, to_type: string, kind: string, label: string}>
     */
    public function connectionRules(): array
    {
        $label = __('statamic-automations::automations.map_edge_starts');

        return array_map(
            fn (string $from) => ['from_type' => $from, 'to_type' => self::TYPE, 'kind' => 'triggers', 'label' => $label],
            self::CONNECTABLE_FROM,
        );
    }

    /**
     * Both ends need their own right: the one that guards the source in the
     * Control Panel (a product: `access products utility`, statamic-products
     * ServiceProvider; a lead magnet: `manage lead magnets`) and the right to
     * edit the automation that is written. Having one never stands in for the other.
     */
    public function connectionAuthorized(array $c, $user): bool
    {
        if ($user === null || ! method_exists($user, 'can')) {
            return false;
        }

        $source = self::SOURCE_PERMISSION[(string) ($c['from_type'] ?? '')] ?? null;

        return $source !== null && (bool) $user->can($source) && (bool) $user->can('edit automations');
    }

    /** The right that guards each kind of source on its own Control Panel. */
    private const SOURCE_PERMISSION = [
        'product' => 'access products utility',
        'lead_magnet' => 'manage lead magnets',
    ];

    /**
     * What the write would do. `before` and `after` are the trigger's slug, the
     * field the write changes, which for a product is the access it grants and
     * not its handle.
     *
     * @return array{consequence: string, before: string, after: string}
     *
     * @throws \DomainException
     */
    public function connectionPreview(array $c, string $action): array
    {
        [$automation, , $source, $current] = $this->connectionParts($c);
        $name = $automation->name !== '' ? (string) $automation->name : $this->idOf($automation);
        $object = $source->label;
        $from = (string) $c['from_type'];

        // A running automation takes the change at once: there is no draft stage, only the version that is kept.
        $live = $automation->enabled ? ' '.__('statamic-automations::automations.map_live_note') : '';

        if ($action === 'connect') {
            $this->assertFree($c, $name, $object, $current);
            $slug = $this->slugToWrite($c, $name, $object);

            $consequence = __('statamic-automations::automations.map_connect_'.$from, ['automation' => $name, 'object' => $object, 'slug' => $slug]);

            if ($from === 'product') {
                $others = array_values(array_diff($this->productsGranting($slug), [(string) $c['from_id']]));

                if ($others !== []) {
                    $consequence .= ' '.__('statamic-automations::automations.map_connect_shared', ['products' => implode(', ', $others)]);
                }
            }

            return ['consequence' => $consequence.$live, 'before' => '', 'after' => $slug];
        }

        if ($action !== 'disconnect') {
            throw new \DomainException(__('statamic-automations::automations.map_connect_unsupported'));
        }

        if ($current === '' || ! $this->isConnectedVia($c, $current)) {
            throw new \DomainException(__('statamic-automations::automations.map_disconnect_not_connected', ['automation' => $name, 'object' => $object]));
        }

        // An empty slug on "access granted" means every grant (FlattensEntitlements), so a running automation must not be emptied.
        if ($automation->enabled) {
            throw new \DomainException(__('statamic-automations::automations.map_disconnect_running'));
        }

        return [
            'consequence' => __('statamic-automations::automations.map_disconnect', ['automation' => $name, 'object' => $object]),
            'before' => $current,
            'after' => '',
        ];
    }

    /** @return array{before: string, after: string} */
    public function connect(array $c): array
    {
        $preview = $this->connectionPreview($c, 'connect');

        $this->writeSlug($c, $preview['before'], $preview['after'], 'connected');

        return ['before' => $preview['before'], 'after' => $preview['after']];
    }

    /**
     * The reverse of connect(): the slug it wrote goes away again. A trigger is only
     * ever written while its slug was empty, so empty is exactly what it was before.
     *
     * @return array{before: string, after: string}
     */
    public function disconnect(array $c): array
    {
        $preview = $this->connectionPreview($c, 'disconnect');

        $this->writeSlug($c, $preview['before'], null, 'disconnected');

        return ['before' => $preview['before'], 'after' => $preview['after']];
    }

    /** Sources a trigger slug can name. A lead magnet's slug is its handle; a product's is the access it grants. */
    private const CONNECTABLE_FROM = ['product', 'lead_magnet'];

    /**
     * Everything a connection needs, or a reason it cannot be had.
     *
     * @return array{0: Automation, 1: AutomationNode, 2: MapObject, 3: string}
     *
     * @throws \DomainException
     */
    protected function connectionParts(array $c): array
    {
        $from = (string) ($c['from_type'] ?? '');
        $fromId = (string) ($c['from_id'] ?? '');

        if (($c['to_type'] ?? null) !== self::TYPE || ! in_array($from, self::CONNECTABLE_FROM, true)
            || ! in_array($c['kind'] ?? 'triggers', ['triggers'], true)) {
            throw new \DomainException(__('statamic-automations::automations.map_connect_unsupported'));
        }

        $automation = $this->resolve((string) ($c['to_id'] ?? ''));

        if (! $automation) {
            throw new \DomainException(__('statamic-automations::automations.map_connect_no_automation', ['id' => (string) ($c['to_id'] ?? '')]));
        }

        $name = $automation->name !== '' ? (string) $automation->name : $this->idOf($automation);
        $lookup = app(MapObjectRegistry::class)->lookup($from, $fromId);

        if ($lookup->status !== MapLookup::FOUND) {
            throw new \DomainException(__('statamic-automations::automations.map_connect_no_source', ['id' => $fromId]));
        }

        $triggers = $automation->nodes->filter(fn (AutomationNode $node) => $node->type === EntitlementGrantedTrigger::handle() || $node->isTrigger())->values();

        if ($triggers->isEmpty()) {
            throw new \DomainException(__('statamic-automations::automations.map_connect_no_trigger', ['automation' => $name]));
        }

        if ($triggers->count() > 1) {
            throw new \DomainException(__('statamic-automations::automations.map_connect_many_triggers', ['automation' => $name]));
        }

        $trigger = $triggers->first();

        if ($trigger->type !== EntitlementGrantedTrigger::handle()) {
            throw new \DomainException(__('statamic-automations::automations.map_connect_other_trigger', ['automation' => $name]));
        }

        return [$automation, $trigger, $lookup->object, (string) $this->productSlugOf($trigger)];
    }

    /** @throws \DomainException when the trigger already listens to something. */
    private function assertFree(array $c, string $name, string $object, string $current): void
    {
        if ($current === '') {
            return;
        }

        throw new \DomainException($this->isConnectedVia($c, $current)
            ? __('statamic-automations::automations.map_connect_already', ['automation' => $name, 'object' => $object])
            : __('statamic-automations::automations.map_connect_other_slug', ['automation' => $name, 'slug' => $current]));
    }

    /**
     * Whether the slug a trigger holds is one this source starts it with: a lead
     * magnet's handle, or for a product an access it grants (its own handle too,
     * the older way a trigger was written by hand).
     */
    private function isConnectedVia(array $c, string $slug): bool
    {
        $fromId = (string) $c['from_id'];

        if ($slug === $fromId) {
            return true;
        }

        return $c['from_type'] === 'product' && in_array($slug, $this->grantSlugsOf($fromId) ?? [], true);
    }

    /**
     * The slug a connect writes: for a lead magnet its handle (the entitlement
     * it grants carries `$resource->handle`, lead-magnets GrantService.php:428);
     * for a product the one access it grants. A product that grants nothing or
     * several is refused with the reason, because a guess would write a slug no
     * purchase of it ever produces, or the wrong one.
     *
     * @throws \DomainException
     */
    private function slugToWrite(array $c, string $name, string $object): string
    {
        if ($c['from_type'] !== 'product') {
            return (string) $c['from_id'];
        }

        $grants = $this->grantSlugsOf((string) $c['from_id']);

        if ($grants === null) {
            throw new \DomainException(__('statamic-automations::automations.map_connect_grants_unknown', ['object' => $object]));
        }

        if ($grants === []) {
            throw new \DomainException(__('statamic-automations::automations.map_connect_grants_nothing', ['object' => $object, 'automation' => $name]));
        }

        if (count($grants) > 1) {
            throw new \DomainException(__('statamic-automations::automations.map_connect_grants_many', ['object' => $object, 'slugs' => implode(', ', $grants)]));
        }

        return $grants[0];
    }

    /**
     * The access slugs a product grants, or null when that cannot be told. The
     * products addon only offers the other direction (`productsGranting()`, a
     * slug to handles), so this reads the catalogue row's own list,
     * `Product::grantSlugs()`, the same list its checkout hands to the
     * entitlements bridge. Without that addon or with one too old, nothing is known.
     *
     * @return list<string>|null
     */
    protected function grantSlugsOf(string $handle): ?array
    {
        $model = 'Goldnead\\StatamicProducts\\Models\\Product';

        // get_class_methods(), not method_exists(): the products addon is optional, so PHPStan
        // cannot see the class and calls a literal method_exists() on it impossible.
        if (! class_exists($model) || ! in_array('grantSlugs', get_class_methods($model), true)) {
            return null;
        }

        try {
            $product = $model::query()->forBrand()->where('handle', $handle)->first();

            return $product === null ? null : array_values(array_filter($product->grantSlugs(), 'is_string'));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Writes the trigger's slug the way the editor saves: a version first so the
     * change can be rolled back, then the graph through the repository (both storage
     * drivers), the version counted up, and a line in the audit log.
     *
     * Compare and set: the automation and its trigger are read again right here,
     * and the write only happens while the slug is still the one the preview
     * showed. Another tab or user in between means nothing is written. There is
     * no lock, so a write landing inside the few lines between this read and the
     * save can still win; the version is counted up from this fresh read.
     *
     * @param  string  $expected  the slug the preview showed.
     * @param  string|null  $slug  null empties the slug.
     *
     * @throws \DomainException when the slug is no longer the expected one.
     */
    private function writeSlug(array $c, string $expected, ?string $slug, string $what): void
    {
        [$automation, $trigger, , $current] = $this->connectionParts($c);

        if ($current !== $expected) {
            $name = $automation->name !== '' ? (string) $automation->name : $this->idOf($automation);

            throw new \DomainException(__('statamic-automations::automations.map_connect_changed', ['automation' => $name]));
        }

        app(VersionManager::class)->snapshot($automation, 'Map: '.$what.' '.$c['from_type'].' '.$c['from_id']);

        $nodes = $automation->nodes->map(function (AutomationNode $node) use ($trigger, $slug) {
            $config = $node->config ?? [];

            if ($node->node_key === $trigger->node_key) {
                if ($slug === null) {
                    unset($config['product_slug']);
                } else {
                    $config['product_slug'] = $slug;
                }
            }

            return [
                'node_key' => $node->node_key,
                'type' => $node->type,
                'label' => $node->label,
                'position_x' => $node->position_x,
                'position_y' => $node->position_y,
                'config' => $config,
                'disabled' => $node->disabled,
            ];
        })->all();

        $edges = $automation->edges->map(fn ($edge) => [
            'from_node_key' => $edge->from_node_key,
            'from_output' => $edge->from_output,
            'to_node_key' => $edge->to_node_key,
            'to_input' => $edge->to_input,
        ])->all();

        $automation->version = (int) $automation->version + 1;
        $automation = $this->automations->save($automation, $nodes, $edges);

        app(AuditLogger::class)->record('updated', $automation, [
            'version' => $automation->version,
            'via' => 'map',
            'connection' => $what,
            'from' => $c['from_type'].':'.$c['from_id'],
            'slug' => $slug ?? $expected,
        ]);
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
        $this->grantingBySlug = [];
        $this->productKnown = [];
        $edges = [];

        foreach ($this->automations->all() as $automation) {
            foreach ($this->edgesOf($automation) as $edge) {
                $edges[$edge->key()] = $edge;
            }
        }

        return array_values($edges);
    }

    /**
     * The grant slugs asked of the products addon during one edges() run, and
     * whether a product handle is a known map object. Both are reset per run.
     *
     * @var array<string, list<string>>
     */
    private array $grantingBySlug = [];

    /** @var array<string, bool> */
    private array $productKnown = [];

    /**
     * A direct edge wins over a grant edge with the same key (a product whose
     * handle is also the slug it grants): the grant edges are appended last and
     * dropped when the direct way already drew that line.
     *
     * @return list<MapEdge>
     */
    protected function edgesOf(Automation $automation): array
    {
        $edges = [];
        $granted = [];

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

            if ($node->type === EntitlementGrantedTrigger::handle()) {
                array_push($granted, ...$this->grantedByEdges($automation, $node));
            }
        }

        $taken = array_map(fn (MapEdge $edge) => $edge->key(), $edges);

        foreach ($granted as $edge) {
            if (! in_array($edge->key(), $taken, true)) {
                $edges[] = $edge;
                $taken[] = $edge->key();
            }
        }

        return $edges;
    }

    /**
     * The second way to an "entitlement granted" trigger: the trigger listens to
     * a grant slug, and a product whose purchase grants that slug starts the
     * automation. The site's case is the product `masterclass-vokale-aussprache`
     * granting `kurs-vokale-aussprache` while the automation listens to the
     * latter. The products addon names the granting handles; each one must still
     * be a product the map knows. A handle that is also the slug yields the same
     * edge key as the direct way, so it stays one line.
     *
     * @return list<MapEdge>
     */
    protected function grantedByEdges(Automation $automation, AutomationNode $node): array
    {
        $slug = $this->productSlugOf($node);

        if ($slug === null) {
            return [];
        }

        $registry = app(MapObjectRegistry::class);
        $handle = $this->idOf($automation);
        $edges = [];

        $this->grantingBySlug[$slug] ??= $this->productsGranting($slug);

        foreach ($this->grantingBySlug[$slug] as $product) {
            $this->productKnown[$product] ??= $registry->lookup('product', $product)->status === MapLookup::FOUND;

            if (! $this->productKnown[$product]) {
                continue;
            }

            $edges[] = new MapEdge(
                fromType: 'product',
                fromId: $product,
                toType: self::TYPE,
                toId: $handle,
                kind: 'triggers',
                label: __('statamic-automations::automations.map_edge_triggers_by_grant'),
                derivedFrom: "entitlement-granted trigger of {$handle}, product_slug {$slug}, granted by product {$product}",
            );
        }

        return $edges;
    }

    /**
     * The handles of the products whose purchase grants a slug. Asked of the
     * products addon without depending on it: when it is absent or too old to
     * know the question, nothing is known and nothing is claimed.
     *
     * @return list<string>
     */
    protected function productsGranting(string $slug): array
    {
        $provider = 'Goldnead\\StatamicProducts\\Integrations\\Map\\ProductMapObjects';

        if (! class_exists($provider) || ! app(MapObjectRegistry::class)->has('product')) {
            return [];
        }

        try {
            $source = app($provider);

            if (! method_exists($source, 'productsGranting')) {
                return [];
            }

            return array_values(array_filter($source->productsGranting($slug), 'is_string'));
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
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
            if ($node->type === SendEmailAction::handle()) {
                $template = $this->writtenTemplate($node);

                if ($template !== null && $this->templateIsGone($template)) {
                    $problems[] = __('statamic-automations::automations.map_problem_template', ['step' => $this->stepName($node), 'template' => $template]);
                }

                continue;
            }

            if (! in_array($node->type, [WaitForAutomationNode::handle(), CallAutomationAction::handle()], true)) {
                continue;
            }

            $written = $this->writtenAutomation($node);

            if ($written === null || $this->resolve($written) !== null) {
                continue;
            }

            $problems[] = __('statamic-automations::automations.map_problem_automation', [
                'step' => $this->stepName($node),
                'automation' => $written,
            ]);
        }

        return $problems;
    }

    /** The mail template slug a send-email step writes, trimmed, or null when it sends plain text. */
    protected function writtenTemplate(AutomationNode $node): ?string
    {
        $value = $node->getConfigValue('template');

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * Whether a template slug points at nothing. The sender falls back to plain
     * text without a word when it cannot find the template, so the map has to
     * ask the template addon itself. Without that addon nothing is known and
     * nothing is claimed.
     */
    protected function templateIsGone(string $slug): bool
    {
        if (! class_exists(EmailTemplates::class)) {
            return false;
        }

        try {
            return EmailTemplates::resolve($slug) === null;
        } catch (\Throwable) {
            return false;
        }
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
