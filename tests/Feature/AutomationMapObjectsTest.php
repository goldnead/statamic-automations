<?php

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapLookup;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Integrations\Map\AutomationMapObjects;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Thumbnails\MailThumbnails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * Fake addons for the entitlement edges: a lead magnet and a product provider,
 * each answering for one slug. Registered in a test to say "this addon is
 * installed"; without registration the type is unavailable and no edge is drawn.
 */
class MapTestLeadMagnets implements ProvidesMapObjects
{
    public function type(): string
    {
        return 'lead_magnet';
    }

    public function typeLabel(): string
    {
        return 'Lead magnet';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [];
    }

    public function find(string $id): ?MapObject
    {
        return $id === 'freebie' ? new MapObject('lead_magnet', 'freebie', 'Freebie') : null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }
}

class MapTestProducts implements ProvidesMapObjects
{
    public function type(): string
    {
        return 'product';
    }

    public function typeLabel(): string
    {
        return 'Product';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [];
    }

    public function find(string $id): ?MapObject
    {
        return in_array($id, ['choir-course', 'masterclass-vokale-aussprache', 'multi', 'nothing'], true)
            ? new MapObject('product', $id, $id === 'choir-course' ? 'Choir course' : $id)
            : null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }
}

class MapTestGrantingProducts extends MapTestProducts
{
    public function find(string $id): ?MapObject
    {
        return in_array($id, ['masterclass-vokale-aussprache', 'bundle'], true) ? new MapObject('product', $id, $id) : null;
    }
}

/*
 * The automations as map objects for statamic-funnels.
 *
 * The clock is fixed at HEUTE. Runs are plain rows here: the metric reads
 * started_at, is_test and automation_uuid, and nothing else.
 */

const AUTOMATION_MAP_HEUTE = '2026-08-20 12:00:00';

function mapAutomation(string $uuid, string $name, string $handle, bool $enabled = true): Automation
{
    return Automation::query()->create([
        'uuid' => $uuid,
        'name' => $name,
        'handle' => $handle,
        'enabled' => $enabled,
    ]);
}

function mapRun(string $automationUuid, string $startedAt, bool $isTest = false): void
{
    AutomationRun::query()->create([
        'automation_uuid' => $automationUuid,
        'trigger_type' => 'entry.published',
        'status' => AutomationRun::STATUS_SUCCESS,
        'started_at' => $startedAt,
        'finished_at' => $startedAt,
        'duration_ms' => 1000,
        'is_test' => $isTest,
    ]);
}

/**
 * The grants of a product handle are the products addon's knowledge (absent
 * here), so the writing tests pin them through the seam. The default is the
 * catalogue the connection tests share: `choir-course` grants its own handle,
 * the site's `masterclass-vokale-aussprache` grants `kurs-vokale-aussprache`,
 * `multi` grants two slugs and `nothing` none.
 *
 * @param  array<string, list<string>>|null  $grantsOfHandle
 */
function mapGrantsOf(?array $grantsOfHandle = null, bool $alsoAnswerGranting = false): AutomationMapObjects
{
    $grantsOfHandle ??= [
        'choir-course' => ['choir-course'],
        'masterclass-vokale-aussprache' => ['kurs-vokale-aussprache'],
        'multi' => ['kurs-a', 'kurs-b'],
        'nothing' => [],
    ];

    return new class(app(AutomationRepository::class), $grantsOfHandle, $alsoAnswerGranting) extends AutomationMapObjects
    {
        public function __construct(AutomationRepository $automations, protected array $grantsOfHandle, protected bool $alsoAnswerGranting)
        {
            parent::__construct($automations);
        }

        protected function grantSlugsOf(string $handle): ?array
        {
            return $this->grantsOfHandle[$handle] ?? null;
        }

        protected function productsGranting(string $slug): array
        {
            if (! $this->alsoAnswerGranting) {
                return parent::productsGranting($slug);
            }

            return array_keys(array_filter($this->grantsOfHandle, fn (array $slugs) => in_array($slug, $slugs, true)));
        }
    };
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(AUTOMATION_MAP_HEUTE));

    $this->map = mapGrantsOf();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reports its type key and a translated type label', function () {
    expect($this->map->type())->toBe('automation')
        ->and($this->map->typeLabel())->toBe('Automation');
});

it('lists the automations sorted by name and limits them', function () {
    mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');
    mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');
    mapAutomation('uuid-vokale', 'Vokale-Strecke', 'vokale-strecke');

    $names = array_map(fn ($object) => $object->label, $this->map->objects());

    expect($names)->toBe(['Nachfassen', 'Vokale-Strecke', 'Willkommensserie'])
        ->and($this->map->objects(null, 2))->toHaveCount(2);
});

it('filters by name or handle without regard to case', function () {
    mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');
    mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');
    mapAutomation('uuid-vokale', 'Vokale-Strecke', 'vokale-strecke');

    $labels = fn (?string $query) => array_map(fn ($object) => $object->label, $this->map->objects($query));

    expect($labels('NACH'))->toBe(['Nachfassen'])
        ->and($labels('welcome'))->toBe(['Willkommensserie'])
        ->and($labels('vokale-strecke'))->toBe(['Vokale-Strecke'])
        ->and($labels('gibt es nicht'))->toBe([])
        ->and($labels('   '))->toHaveCount(3);
});

it('finds one automation by handle, uuid or id and says so when it is gone', function () {
    $automation = mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');

    $object = $this->map->find('nachfass');

    expect($object)->not->toBeNull()
        ->and($object->type)->toBe('automation')
        ->and($object->id)->toBe('nachfass')
        ->and($object->label)->toBe('Nachfassen')
        ->and($object->cpUrl)->toEndWith('/cp/automations/automations/'.$automation->id.'/edit')
        ->and($this->map->find('uuid-nachfass')->id)->toBe('nachfass')
        ->and($this->map->find((string) $automation->id)->id)->toBe('nachfass')
        ->and($this->map->find('gibt-es-nicht'))->toBeNull();
});

it('maps the enabled switch to enabled, on and off', function () {
    mapAutomation('uuid-an', 'Aktiv', 'an', enabled: true);
    mapAutomation('uuid-aus', 'Pausiert', 'aus', enabled: false);

    expect($this->map->find('an')->enabled)->toBeTrue()
        ->and($this->map->find('aus')->enabled)->toBeFalse();
});

it('counts runs of one automation over the period only, without test runs or future runs', function () {
    mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');
    mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');

    mapRun('uuid-willkommen', '2026-08-15 10:00:00');
    mapRun('uuid-willkommen', '2026-08-18 09:00:00');
    // A test run in the period: never a figure on the map.
    mapRun('uuid-willkommen', '2026-08-19 09:00:00', isTest: true);
    // Two months before the period: in no figure of it.
    mapRun('uuid-willkommen', '2026-06-02 09:00:00');
    // After now, so not yet counted: the figure never looks into the future.
    mapRun('uuid-willkommen', '2026-08-21 09:00:00');
    // Another automation's run, in the period.
    mapRun('uuid-nachfass', '2026-08-16 09:00:00');

    $period = MapPeriod::lastDays(30);

    $metric = $this->map->metric('welcome-daily', $period);

    expect($metric)->not->toBeNull()
        ->and($metric->value)->toBe(2)
        ->and($metric->what)->toBe('Runs')
        ->and($metric->period)->toBe($period)
        ->and($this->map->metric('nachfass', $period)->value)->toBe(1);
});

it('returns no metric for an automation that does not exist', function () {
    expect($this->map->metric('gibt-es-nicht', MapPeriod::lastDays(30)))->toBeNull();
});

it('is registered in the map registry after boot', function () {
    $registry = app(MapObjectRegistry::class);

    expect($registry->has('automation'))->toBeTrue()
        ->and($registry->types())->toHaveKey('automation', 'Automation');

    mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');

    expect($registry->lookup('automation', 'nachfass')->status)->toBe(MapLookup::FOUND)
        ->and($registry->lookup('automation', 'gibt-es-nicht')->status)->toBe(MapLookup::MISSING);
});

/*
 * Edges: what an automation's own steps say about the map. Each step type is
 * read from its configuration and nothing else.
 */

function mapNode(Automation $automation, string $type, array $config = [], ?string $label = null): AutomationNode
{
    return AutomationNode::query()->create([
        'automation_id' => $automation->id,
        'node_key' => 'node-'.bin2hex(random_bytes(4)),
        'type' => $type,
        'label' => $label,
        'config' => $config,
    ]);
}

function mapEdgeByKey(array $edges, string $key): ?MapEdge
{
    return collect($edges)->first(fn (MapEdge $edge) => $edge->key() === $key);
}

it('draws a waits-for edge from the awaited automation to the waiting one', function () {
    mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');
    $nachfass = mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');
    mapNode($nachfass, 'wait_for_automation', ['automation' => 'welcome-daily'], 'Warten');

    $edge = mapEdgeByKey($this->map->edges(), 'automation:welcome-daily>waits_for>automation:nachfass');

    expect($edge)->not->toBeNull()
        ->and($edge->fromType)->toBe('automation')
        ->and($edge->fromId)->toBe('welcome-daily')
        ->and($edge->toId)->toBe('nachfass')
        ->and($edge->kind)->toBe('waits_for')
        ->and($edge->label)->toBe('awaited by')
        ->and($edge->dashed)->toBeTrue()
        ->and($edge->derivedFrom)->toBe('wait_for_automation step of nachfass')
        ->and($edge->toLabel)->toBeNull();
});

it('resolves a waited-for automation written as uuid to its handle', function () {
    mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');
    $nachfass = mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');
    mapNode($nachfass, 'wait_for_automation', ['automation' => 'uuid-willkommen']);

    expect(mapEdgeByKey($this->map->edges(), 'automation:welcome-daily>waits_for>automation:nachfass'))->not->toBeNull();
});

it('draws a starts edge from the calling automation to the one it calls', function () {
    $welcome = mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');
    mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');
    mapNode($welcome, 'call_automation', ['automation' => 'nachfass'], 'Aufruf');

    $edge = mapEdgeByKey($this->map->edges(), 'automation:welcome-daily>starts>automation:nachfass');

    expect($edge)->not->toBeNull()
        ->and($edge->label)->toBe('starts')
        ->and($edge->dashed)->toBeFalse()
        ->and($edge->derivedFrom)->toBe('call_automation step of welcome-daily')
        ->and($edge->toLabel)->toBe('nachfass')
        ->and($this->map->find('welcome-daily')->problems)->toBe([]);
});

it('words the problems in German when the app speaks German', function () {
    app()->setLocale('de');

    $nachfass = mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');
    mapNode($nachfass, 'wait_for_automation', ['automation' => 'gone'], 'Warten');

    expect($this->map->find('nachfass')->problems)
        ->toBe(['Schritt „Warten“ verweist auf die Automatisierung „gone“, die es nicht gibt']);
});

it('keeps a dead wait and a dead call as edges with the written text, and reports both as problems', function () {
    $nachfass = mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');
    mapNode($nachfass, 'wait_for_automation', ['automation' => 'gone'], 'Warten');
    mapNode($nachfass, 'call_automation', ['automation' => 'weg'], 'Aufruf');

    $edges = $this->map->edges();

    $wait = mapEdgeByKey($edges, 'automation:gone>waits_for>automation:nachfass');
    $call = mapEdgeByKey($edges, 'automation:nachfass>starts>automation:weg');

    expect($wait)->not->toBeNull()
        ->and($wait->fromId)->toBe('gone')
        ->and($wait->toLabel)->toBeNull()
        ->and($call)->not->toBeNull()
        ->and($call->toId)->toBe('weg')
        ->and($call->toLabel)->toBe('weg')
        ->and($this->map->find('nachfass')->problems)
        ->toHaveCount(2)
        ->toContain('step "Warten" points at automation "gone", which does not exist')
        ->toContain('step "Aufruf" points at automation "weg", which does not exist');
});

it('draws no problem for a step without a written automation', function () {
    $nachfass = mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');
    mapNode($nachfass, 'call_automation', ['automation' => '  '], 'Aufruf');

    expect($this->map->edges())->toBe([])
        ->and($this->map->find('nachfass')->problems)->toBe([]);
});

it('draws no entitlement edge without a lead magnet or product provider, and no guess', function () {
    $welcome = mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');
    mapNode($welcome, 'entitlements.granted', ['product_slug' => 'freebie']);
    mapNode($welcome, 'entitlements.grant', ['product_slug' => 'choir-course']);

    $kinds = array_map(fn (MapEdge $edge) => $edge->kind, $this->map->edges());

    expect($kinds)->toBe([]);
});

it('draws a triggers edge from a lead magnet with the slug, and nothing for an unknown slug', function () {
    app(MapObjectRegistry::class)->register(MapTestLeadMagnets::class);
    $welcome = mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');
    mapNode($welcome, 'entitlements.granted', ['product_slug' => 'freebie']);

    $edge = mapEdgeByKey($this->map->edges(), 'lead_magnet:freebie>triggers>automation:welcome-daily');

    expect($edge)->not->toBeNull()
        ->and($edge->fromType)->toBe('lead_magnet')
        ->and($edge->label)->toBe('triggers')
        ->and($edge->derivedFrom)->toBe('entitlement-granted trigger of welcome-daily, product_slug freebie');

    $other = mapAutomation('uuid-anderes', 'Anderes', 'anderes');
    mapNode($other, 'entitlements.granted', ['product_slug' => 'nichts']);

    expect(array_filter($this->map->edges(), fn (MapEdge $e) => $e->toId === 'anderes'))->toBe([]);
});

it('draws a grants edge to the product when no lead magnet has the slug', function () {
    app(MapObjectRegistry::class)->register(MapTestLeadMagnets::class);
    app(MapObjectRegistry::class)->register(MapTestProducts::class);
    $welcome = mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');
    mapNode($welcome, 'entitlements.grant', ['product_slug' => 'choir-course']);

    $edge = mapEdgeByKey($this->map->edges(), 'automation:welcome-daily>grants>product:choir-course');

    expect($edge)->not->toBeNull()
        ->and($edge->toType)->toBe('product')
        ->and($edge->label)->toBe('grants access')
        ->and($edge->derivedFrom)->toBe('grant_entitlement step of welcome-daily, product_slug choir-course');
});

/*
 * An automation that listens to the grant slug, not to the product handle:
 * the product `masterclass-vokale-aussprache` grants `kurs-vokale-aussprache`.
 * The real answer comes from the products addon (absent here), so it is pinned
 * through the seam, as the template check is.
 */
function mapGrantAsker(array $grantsBySlug): AutomationMapObjects
{
    return new class(app(AutomationRepository::class), $grantsBySlug) extends AutomationMapObjects
    {
        public function __construct(AutomationRepository $automations, protected array $grantsBySlug)
        {
            parent::__construct($automations);
        }

        protected function productsGranting(string $slug): array
        {
            return $this->grantsBySlug[$slug] ?? [];
        }
    };
}

it('draws a triggers edge from the product that grants the slug the trigger listens to', function () {
    app(MapObjectRegistry::class)->register(MapTestGrantingProducts::class);
    $vokale = mapAutomation('uuid-vokale', 'Vokale Bonus', 'vokale-bonus');
    mapNode($vokale, 'entitlements.granted', ['product_slug' => 'kurs-vokale-aussprache']);

    $edges = mapGrantAsker(['kurs-vokale-aussprache' => ['masterclass-vokale-aussprache', 'bundle']])->edges();
    $edge = mapEdgeByKey($edges, 'product:masterclass-vokale-aussprache>triggers>automation:vokale-bonus');

    expect($edge)->not->toBeNull()
        ->and($edge->kind)->toBe('triggers')
        ->and($edge->label)->toBe('product grants it, triggers')
        ->and($edge->derivedFrom)->toBe('entitlement-granted trigger of vokale-bonus, product_slug kurs-vokale-aussprache, granted by product masterclass-vokale-aussprache')
        ->and(mapEdgeByKey($edges, 'product:bundle>triggers>automation:vokale-bonus'))->not->toBeNull()
        ->and($edges)->toHaveCount(2);
});

it('draws no grant edge for a granting product the map does not know, and none without a provider', function () {
    $vokale = mapAutomation('uuid-vokale', 'Vokale Bonus', 'vokale-bonus');
    mapNode($vokale, 'entitlements.granted', ['product_slug' => 'kurs-vokale-aussprache']);
    $asker = mapGrantAsker(['kurs-vokale-aussprache' => ['masterclass-vokale-aussprache']]);

    expect($asker->edges())->toBe([]);

    app(MapObjectRegistry::class)->register(MapTestGrantingProducts::class);

    expect(mapGrantAsker([])->edges())->toBe([]);
});

it('draws one line when the product handle and its grant are the same slug', function () {
    app(MapObjectRegistry::class)->register(MapTestGrantingProducts::class);
    $vokale = mapAutomation('uuid-vokale', 'Vokale Bonus', 'vokale-bonus');
    mapNode($vokale, 'entitlements.granted', ['product_slug' => 'bundle']);

    $edges = mapGrantAsker(['bundle' => ['bundle']])->edges();

    expect($edges)->toHaveCount(1)
        ->and($edges[0]->key())->toBe('product:bundle>triggers>automation:vokale-bonus')
        ->and($edges[0]->label)->toBe('triggers');
});

it('asks the products source once per slug and run, however many triggers listen to it', function () {
    app(MapObjectRegistry::class)->register(MapTestGrantingProducts::class);
    foreach (['eins', 'zwei', 'drei'] as $handle) {
        $automation = mapAutomation("uuid-{$handle}", ucfirst($handle), $handle);
        mapNode($automation, 'entitlements.granted', ['product_slug' => 'kurs-vokale-aussprache']);
    }

    $asker = new class(app(AutomationRepository::class)) extends AutomationMapObjects
    {
        public int $asked = 0;

        protected function productsGranting(string $slug): array
        {
            $this->asked++;

            return ['masterclass-vokale-aussprache'];
        }
    };

    expect($asker->edges())->toHaveCount(3)
        ->and($asker->asked)->toBe(1);

    $asker->edges();

    expect($asker->asked)->toBe(2);
});

it('lets the direct edge win over a grant edge with the same key, whatever the step order', function () {
    app(MapObjectRegistry::class)->register(MapTestGrantingProducts::class);
    $vokale = mapAutomation('uuid-vokale', 'Vokale Bonus', 'vokale-bonus');
    mapNode($vokale, 'entitlements.granted', ['product_slug' => 'kurs-vokale-aussprache']);
    mapNode($vokale, 'entitlements.granted', ['product_slug' => 'bundle']);

    $edges = mapGrantAsker(['kurs-vokale-aussprache' => ['bundle']])->edges();
    $edge = mapEdgeByKey($edges, 'product:bundle>triggers>automation:vokale-bonus');

    expect($edges)->toHaveCount(1)
        ->and($edge->label)->toBe('triggers')
        ->and($edge->derivedFrom)->toBe('entitlement-granted trigger of vokale-bonus, product_slug bundle');
});

/*
 * The real seam: class_exists, the registry check, method_exists and the catch.
 * The products addon is not a dependency here, so the true two-addon run needs
 * it installed; this stand-in carries its class name and its one method and
 * drives the wiring. It is only declared when the real class is absent.
 */
if (! class_exists('Goldnead\\StatamicProducts\\Integrations\\Map\\ProductMapObjects')) {
    eval('namespace Goldnead\\StatamicProducts\\Integrations\\Map;
    class ProductMapObjects
    {
        public static bool $throws = false;
        public function productsGranting(string $slug): array
        {
            if (self::$throws) { throw new \\RuntimeException("products down"); }
            return $slug === "kurs-vokale-aussprache" ? ["masterclass-vokale-aussprache", 7, "bundle"] : [];
        }
    }');
}

it('asks the real products source through the seam and draws the edge', function () {
    $class = 'Goldnead\\StatamicProducts\\Integrations\\Map\\ProductMapObjects';
    $class::$throws = false;
    app(MapObjectRegistry::class)->register(MapTestGrantingProducts::class);
    $vokale = mapAutomation('uuid-vokale', 'Vokale Bonus', 'vokale-bonus');
    mapNode($vokale, 'entitlements.granted', ['product_slug' => 'kurs-vokale-aussprache']);

    $edges = $this->map->edges();

    expect(mapEdgeByKey($edges, 'product:masterclass-vokale-aussprache>triggers>automation:vokale-bonus'))->not->toBeNull()
        ->and(mapEdgeByKey($edges, 'product:bundle>triggers>automation:vokale-bonus'))->not->toBeNull()
        ->and($edges)->toHaveCount(2);
});

it('claims nothing when the products source throws', function () {
    $class = 'Goldnead\\StatamicProducts\\Integrations\\Map\\ProductMapObjects';

    if (! property_exists($class, 'throws')) {
        $this->markTestSkipped('only the stand-in can be told to throw');
    }

    $class::$throws = true;
    app(MapObjectRegistry::class)->register(MapTestGrantingProducts::class);
    $vokale = mapAutomation('uuid-vokale', 'Vokale Bonus', 'vokale-bonus');
    mapNode($vokale, 'entitlements.granted', ['product_slug' => 'kurs-vokale-aussprache']);

    try {
        expect($this->map->edges())->toBe([]);
    } finally {
        $class::$throws = false;
    }
});

it('yields one edge when two steps express the same relation', function () {
    app(MapObjectRegistry::class)->register(MapTestLeadMagnets::class);
    $welcome = mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');
    mapNode($welcome, 'entitlements.granted', ['product_slug' => 'freebie']);
    mapNode($welcome, 'entitlements.granted', ['product_slug' => 'freebie']);

    $triggers = array_filter($this->map->edges(), fn (MapEdge $e) => $e->kind === 'triggers');

    expect($triggers)->toHaveCount(1);
});

it('lists the edges of every addon in the map registry', function () {
    $welcome = mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');
    $nachfass = mapAutomation('uuid-nachfass', 'Nachfassen', 'nachfass');
    mapNode($nachfass, 'wait_for_automation', ['automation' => 'welcome-daily']);
    mapNode($welcome, 'call_automation', ['automation' => 'nachfass']);

    $edges = app(MapObjectRegistry::class)->edges();

    expect($edges)->toHaveKeys([
        'automation:welcome-daily>waits_for>automation:nachfass',
        'automation:welcome-daily>starts>automation:nachfass',
    ])
        ->and($edges['automation:welcome-daily>starts>automation:nachfass'])->toBeInstanceOf(MapEdge::class);
});

it('reports a send step whose template does not exist and not a plain-text step', function () {
    $mail = mapAutomation('uuid-mail', 'Bonus', 'vokale-bonus');
    mapNode($mail, 'send_email', ['template' => 'tag-3'], 'Tag 3');
    mapNode($mail, 'send_email', ['template' => ''], 'Klartext');

    // The real check asks the template addon (present in CI, absent locally), so the question is pinned through a seam.
    $asker = new class(app(AutomationRepository::class)) extends AutomationMapObjects
    {
        protected function templateIsGone(string $slug): bool
        {
            return $slug === 'tag-3';
        }
    };

    expect($asker->find('vokale-bonus')->problems)->toBe(['step "Tag 3" sends template "tag-3", which does not exist']);
});

/*
 * Drawing a connection: product or lead magnet -> automation writes the
 * product_slug of the automation's "access granted" trigger. Only an existing
 * trigger with an empty slug is written; anything else is refused, so that
 * disconnect is always the exact reverse and needs no memory.
 */

function mapConnection(string $fromType = 'product', string $fromId = 'choir-course', string $toId = 'welcome-daily'): array
{
    return ['from_type' => $fromType, 'from_id' => $fromId, 'to_type' => 'automation', 'to_id' => $toId, 'kind' => 'triggers'];
}

function mapTriggerOf(string $handle): ?AutomationNode
{
    return app(AutomationRepository::class)->findByRef($handle)->nodes->first(fn (AutomationNode $n) => $n->type === 'entitlements.granted');
}

function mapConnectable(array $config = [], bool $enabled = false): Automation
{
    app(MapObjectRegistry::class)->register(MapTestLeadMagnets::class);
    app(MapObjectRegistry::class)->register(MapTestProducts::class);

    $automation = mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily', $enabled);
    mapNode($automation, 'entitlements.granted', $config, 'Zugang');
    mapNode($automation, 'send_email', ['template' => 'x'], 'Mail');

    return $automation;
}

it('offers product and lead magnet to automation as a triggers connection', function () {
    $rules = collect($this->map->connectionRules());

    expect($rules->map(fn ($r) => $r['from_type'].'>'.$r['to_type'].'>'.$r['kind'])->all())
        ->toBe(['product>automation>triggers', 'lead_magnet>automation>triggers'])
        ->and($rules->every(fn ($r) => $r['label'] === 'starts'))->toBeTrue();
});

it('describes the consequence in words with both names and refuses nothing for a free trigger', function () {
    mapConnectable();

    $preview = $this->map->connectionPreview(mapConnection(), 'connect');

    expect($preview['consequence'])->toBe('From now on "Willkommensserie" starts whenever access "choir-course" is granted, which happens when someone buys "Choir course".')
        ->and($preview['before'])->toBe('')
        ->and($preview['after'])->toBe('choir-course');

    $lead = $this->map->connectionPreview(mapConnection('lead_magnet', 'freebie'), 'connect');

    expect($lead['consequence'])->toBe('From now on "Willkommensserie" starts for everyone who gets "Freebie".');
});

it('writes the slug into the trigger on connect and leaves everything else as it was', function () {
    mapConnectable(['source' => 'mollie']);
    $versionBefore = app(AutomationRepository::class)->findByRef('welcome-daily')->version;

    $result = $this->map->connect(mapConnection());

    $trigger = mapTriggerOf('welcome-daily');

    expect($result)->toBe(['before' => '', 'after' => 'choir-course'])
        ->and($trigger->config)->toBe(['source' => 'mollie', 'product_slug' => 'choir-course'])
        ->and($trigger->label)->toBe('Zugang')
        ->and(app(AutomationRepository::class)->findByRef('welcome-daily')->nodes)->toHaveCount(2)
        ->and(app(AutomationRepository::class)->findByRef('welcome-daily')->version)->toBe($versionBefore + 1);

    $edge = mapEdgeByKey($this->map->edges(), 'product:choir-course>triggers>automation:welcome-daily');
    expect($edge)->not->toBeNull();
});

it('disconnect is the exact reverse of connect', function () {
    mapConnectable(['source' => 'mollie']);

    $this->map->connect(mapConnection());
    $result = $this->map->disconnect(mapConnection());

    expect($result)->toBe(['before' => 'choir-course', 'after' => ''])
        ->and(mapTriggerOf('welcome-daily')->config)->toBe(['source' => 'mollie'])
        ->and(app(AutomationRepository::class)->findByRef('welcome-daily')->nodes)->toHaveCount(2);

    expect(fn () => $this->map->disconnect(mapConnection()))->toThrow(DomainException::class);
});

it('describes the disconnect consequence in words', function () {
    mapConnectable(['product_slug' => 'choir-course']);

    expect($this->map->connectionPreview(mapConnection(), 'disconnect')['consequence'])
        ->toContain('"Willkommensserie" is switched off and no longer waits for "Choir course"');
});

it('writes the grant slug of the product, not its handle, and draws the line it wrote', function () {
    mapConnectable();
    $c = mapConnection('product', 'masterclass-vokale-aussprache');

    $preview = $this->map->connectionPreview($c, 'connect');

    expect($preview['after'])->toBe('kurs-vokale-aussprache')
        ->and($preview['consequence'])->toContain('"kurs-vokale-aussprache"');

    $result = $this->map->connect($c);

    expect($result)->toBe(['before' => '', 'after' => 'kurs-vokale-aussprache'])
        ->and(mapTriggerOf('welcome-daily')->config)->toBe(['product_slug' => 'kurs-vokale-aussprache']);

    // The line the map draws afterwards is the one the user drew, from the product that grants the slug.
    expect(mapEdgeByKey($this->map->edges(), 'product:masterclass-vokale-aussprache>triggers>automation:welcome-daily'))->not->toBeNull();

    // Disconnect compares the same slug, and empties it again.
    expect($this->map->connectionPreview($c, 'disconnect')['before'])->toBe('kurs-vokale-aussprache')
        ->and($this->map->disconnect($c))->toBe(['before' => 'kurs-vokale-aussprache', 'after' => ''])
        ->and(mapTriggerOf('welcome-daily')->config)->toBe([]);
});

it('refuses a product that grants nothing, naming why, and writes nothing', function () {
    mapConnectable();

    expect(fn () => $this->map->connect(mapConnection('product', 'nothing')))
        ->toThrow(DomainException::class, 'grants no access')
        ->and(mapTriggerOf('welcome-daily')->config)->toBe([]);
});

it('refuses a product that grants several slugs and names them', function () {
    mapConnectable();

    expect(fn () => $this->map->connect(mapConnection('product', 'multi')))
        ->toThrow(DomainException::class, 'kurs-a, kurs-b')
        ->and(mapTriggerOf('welcome-daily')->config)->toBe([]);
});

it('refuses a product whose grants are unknown to this install', function () {
    mapConnectable();
    $unknown = mapGrantsOf([]);

    expect(fn () => $unknown->connect(mapConnection('product', 'choir-course')))->toThrow(DomainException::class, 'cannot tell');
});

it('can release one of several grant slugs of a product that is connected through it', function () {
    mapConnectable(['product_slug' => 'kurs-b']);

    expect($this->map->disconnect(mapConnection('product', 'multi')))->toBe(['before' => 'kurs-b', 'after' => ''])
        ->and(mapTriggerOf('welcome-daily')->config)->toBe([]);
});

it('says when other products grant the same slug, because they start the automation too', function () {
    mapConnectable();
    $shared = mapGrantsOf(['choir-course' => ['kurs'], 'masterclass-vokale-aussprache' => ['kurs']], true);

    expect($shared->connectionPreview(mapConnection(), 'connect')['consequence'])
        ->toContain('masterclass-vokale-aussprache');
});

it('says that a running automation changes at once, in both languages', function () {
    mapConnectable(enabled: true);

    expect($this->map->connectionPreview(mapConnection(), 'connect')['consequence'])->toEndWith('The automation is active, so this applies immediately.');

    app()->setLocale('de');

    expect($this->map->connectionPreview(mapConnection(), 'connect')['consequence'])->toEndWith('Die Automatisierung ist aktiv, die Änderung gilt sofort.');
});

it('refuses to release a running automation, because an empty trigger means every grant', function () {
    mapConnectable(['product_slug' => 'choir-course'], enabled: true);

    $message = 'This automation is switched on. Without a product it would start on every access that is granted. Switch it off first.';

    expect(fn () => $this->map->connectionPreview(mapConnection(), 'disconnect'))->toThrow(DomainException::class, $message)
        ->and(fn () => $this->map->disconnect(mapConnection()))->toThrow(DomainException::class, $message)
        ->and(mapTriggerOf('welcome-daily')->config)->toBe(['product_slug' => 'choir-course']);

    app()->setLocale('de');

    expect(fn () => $this->map->disconnect(mapConnection()))
        ->toThrow(DomainException::class, 'Diese Automatisierung ist eingeschaltet. Ohne Produkt würde sie bei jeder Freischaltung starten. Schalte sie erst aus.');
});

it('refuses the undo of a connect on a running automation too', function () {
    mapConnectable(enabled: true);

    $this->map->connect(mapConnection());

    expect(fn () => $this->map->disconnect(mapConnection()))->toThrow(DomainException::class, 'switched on')
        ->and(mapTriggerOf('welcome-daily')->config)->toBe(['product_slug' => 'choir-course']);
});

it('says truthfully what an empty trigger means when it releases a switched-off automation', function () {
    mapConnectable(['product_slug' => 'choir-course']);

    expect($this->map->connectionPreview(mapConnection(), 'disconnect')['consequence'])
        ->toBe('"Willkommensserie" is switched off and no longer waits for "Choir course". An empty trigger means every access, so switch it on only after you chose a new one.');
});

it('keeps a version the editor can roll back to when it writes', function () {
    $automation = mapConnectable();
    $versions = app(VersionManager::class);
    $before = count($versions->versions($automation));

    $this->map->connect(mapConnection());

    expect(count($versions->versions($automation)))->toBe($before + 1);
});

it('writes nothing when the slug changed between the preview and the write', function () {
    mapConnectable();
    // The user confirmed a preview that said "empty"; by the time of the write another tab has set a slug.
    $stale = new class(app(AutomationRepository::class)) extends AutomationMapObjects
    {
        public function connectionPreview(array $c, string $action): array
        {
            return ['consequence' => '', 'before' => '', 'after' => 'choir-course'];
        }
    };
    $trigger = mapTriggerOf('welcome-daily');
    $trigger->update(['config' => ['product_slug' => 'freebie']]);
    $version = app(AutomationRepository::class)->findByRef('welcome-daily')->version;

    expect(fn () => $stale->connect(mapConnection('lead_magnet', 'freebie')))
        ->toThrow(DomainException::class, 'changed in the meantime')
        ->and(mapTriggerOf('welcome-daily')->config)->toBe(['product_slug' => 'freebie'])
        ->and(app(AutomationRepository::class)->findByRef('welcome-daily')->version)->toBe($version);
});

it('writes nothing on disconnect when the slug changed between the preview and the write', function () {
    mapConnectable(['product_slug' => 'freebie']);
    $stale = new class(app(AutomationRepository::class)) extends AutomationMapObjects
    {
        public function connectionPreview(array $c, string $action): array
        {
            return ['consequence' => '', 'before' => 'choir-course', 'after' => ''];
        }
    };

    expect(fn () => $stale->disconnect(mapConnection('lead_magnet', 'freebie')))
        ->toThrow(DomainException::class, 'changed in the meantime')
        ->and(mapTriggerOf('welcome-daily')->config)->toBe(['product_slug' => 'freebie']);
});

it('words the compare-and-set refusal in German', function () {
    app()->setLocale('de');
    mapConnectable(['product_slug' => 'freebie']);
    $stale = new class(app(AutomationRepository::class)) extends AutomationMapObjects
    {
        public function connectionPreview(array $c, string $action): array
        {
            return ['consequence' => '', 'before' => '', 'after' => 'choir-course'];
        }
    };

    expect(fn () => $stale->connect(mapConnection('lead_magnet', 'freebie')))
        ->toThrow(DomainException::class, 'zwischenzeitlich geändert');
});

it('runs the whole chain from the registry to the provider, product and lead magnet', function () {
    mapConnectable();
    app()->instance(AutomationMapObjects::class, mapGrantsOf(null, true));
    $registry = app(MapObjectRegistry::class);
    $registry->register(AutomationMapObjects::class);
    mapRegisterSourceRights();
    $user = User::make()->email('chain@example.com')->makeSuper();
    $user->save();

    $rules = collect($registry->connectionRules());
    expect($rules->where('to_type', 'automation')->pluck('owner')->unique()->all())->toBe(['automation']);

    $applied = $registry->applyConnection(mapConnection('product', 'masterclass-vokale-aussprache'), 'connect', $user, '');

    expect($applied['after'])->toBe('kurs-vokale-aussprache')
        ->and(mapTriggerOf('welcome-daily')->config)->toBe(['product_slug' => 'kurs-vokale-aussprache']);

    // The drawn line belongs to the provider that owns the rule: the owner of a `triggers` line is `automation`.
    $edge = $registry->edges()['product:masterclass-vokale-aussprache>triggers>automation:welcome-daily'] ?? null;
    expect($edge)->not->toBeNull()
        ->and($edge->ownerType)->toBe('automation');

    // A second tab that still shows "empty" is refused and nothing moves.
    expect(fn () => $registry->applyConnection(mapConnection('lead_magnet', 'freebie'), 'connect', $user, ''))
        ->toThrow(DomainException::class);

    $undone = $registry->applyConnection($applied['undo'], 'disconnect', $user, 'kurs-vokale-aussprache');
    expect($undone['after'])->toBe('')
        ->and(mapTriggerOf('welcome-daily')->config)->toBe([]);

    $registry->applyConnection(mapConnection('lead_magnet', 'freebie'), 'connect', $user, '');
    expect(mapTriggerOf('welcome-daily')->config)->toBe(['product_slug' => 'freebie']);
});

it('refuses when the trigger already listens to another slug and does not touch it', function () {
    mapConnectable(['product_slug' => 'freebie']);

    expect(fn () => $this->map->connect(mapConnection()))
        ->toThrow(DomainException::class, '"Willkommensserie" already starts for "freebie". Release that first.')
        ->and(mapTriggerOf('welcome-daily')->config)->toBe(['product_slug' => 'freebie']);
});

it('refuses when the trigger already has this slug', function () {
    mapConnectable(['product_slug' => 'choir-course']);

    expect(fn () => $this->map->connect(mapConnection()))->toThrow(DomainException::class, 'already connected');
});

it('refuses a disconnect when the trigger names another slug', function () {
    mapConnectable(['product_slug' => 'freebie']);

    expect(fn () => $this->map->disconnect(mapConnection()))->toThrow(DomainException::class);
    expect(mapTriggerOf('welcome-daily')->config)->toBe(['product_slug' => 'freebie']);
});

it('refuses an automation with another kind of trigger', function () {
    app(MapObjectRegistry::class)->register(MapTestProducts::class);
    $other = mapAutomation('uuid-eintrag', 'Eintrag', 'eintrag');
    mapNode($other, 'entry_published', [], 'Eintrag');

    expect(fn () => $this->map->connect(mapConnection(toId: 'eintrag')))
        ->toThrow(DomainException::class, 'has a different trigger');
});

it('refuses an automation without any trigger step', function () {
    app(MapObjectRegistry::class)->register(MapTestProducts::class);
    $bare = mapAutomation('uuid-leer', 'Leer', 'leer');
    mapNode($bare, 'send_email', ['template' => 'x']);

    expect(fn () => $this->map->connect(mapConnection(toId: 'leer')))->toThrow(DomainException::class, 'no trigger');
});

it('refuses an automation with more than one trigger step', function () {
    $automation = mapConnectable();
    mapNode($automation, 'entry_published', [], 'Zweiter');

    expect(fn () => $this->map->connect(mapConnection()))->toThrow(DomainException::class, 'more than one trigger');
});

it('refuses an unknown automation, an unknown source, and a pair that is not offered', function () {
    mapConnectable();

    expect(fn () => $this->map->connect(mapConnection(toId: 'gibt-es-nicht')))->toThrow(DomainException::class)
        ->and(fn () => $this->map->connect(mapConnection(fromId: 'gibt-es-nicht')))->toThrow(DomainException::class)
        ->and(fn () => $this->map->connect(['from_type' => 'course', 'from_id' => 'x'] + mapConnection()))->toThrow(DomainException::class);
});

/**
 * The two source rights belong to addons that are not installed here; on a real
 * site they are registered, and the gate only answers for registered permissions.
 */
function mapRegisterSourceRights(): void
{
    foreach (['access products utility', 'manage lead magnets'] as $foreign) {
        Permission::register($foreign);
    }
}

function mapUserWith(string $name, array $permissions): Statamic\Contracts\Auth\User
{
    mapRegisterSourceRights();

    $role = Role::make($name)->title($name);
    foreach ($permissions as $permission) {
        $role->addPermission($permission);
    }
    $role->save();

    $user = User::make()->email($name.'@example.com');
    $user->assignRole($role);
    $user->save();

    return $user;
}

it('asks for the right on the source and on the target, whichever pair is drawn', function () {
    $super = User::make()->email('a@example.com')->makeSuper();
    $super->save();

    $cases = [
        // [from_type, permissions held, allowed]
        ['product', ['access products utility', 'edit automations'], true],
        ['product', ['edit automations'], false],
        ['product', ['access products utility'], false],
        ['product', [], false],
        ['lead_magnet', ['manage lead magnets', 'edit automations'], true],
        ['lead_magnet', ['edit automations'], false],
        ['lead_magnet', ['manage lead magnets'], false],
        // The right for the other kind of source does not stand in.
        ['product', ['manage lead magnets', 'edit automations'], false],
        ['lead_magnet', ['access products utility', 'edit automations'], false],
    ];

    foreach ($cases as $i => [$from, $permissions, $allowed]) {
        $user = mapUserWith('case-'.$i, $permissions);

        expect($this->map->connectionAuthorized(mapConnection($from, 'x'), $user))->toBe($allowed, "case {$i}: {$from} with [".implode(', ', $permissions).']');
    }

    expect($this->map->connectionAuthorized(mapConnection(), $super))->toBeTrue()
        ->and($this->map->connectionAuthorized(mapConnection('lead_magnet', 'freebie'), $super))->toBeTrue()
        ->and($this->map->connectionAuthorized(mapConnection(), null))->toBeFalse()
        ->and($this->map->connectionAuthorized(['from_type' => 'course', 'to_type' => 'automation'], $super))->toBeFalse();
});

it('refuses a preview to a user who lacks the right on the target', function () {
    mapConnectable();
    app()->instance(AutomationMapObjects::class, $this->map);
    $registry = app(MapObjectRegistry::class);
    $registry->register(AutomationMapObjects::class);
    $sourceOnly = mapUserWith('source-only', ['access products utility']);

    expect(fn () => $registry->previewConnection(mapConnection(), 'connect', $sourceOnly))
        ->toThrow(AuthorizationException::class);
});

it('words the consequence and the refusal in German when the app speaks German', function () {
    app()->setLocale('de');
    mapConnectable();

    expect($this->map->connectionPreview(mapConnection(), 'connect')['consequence'])
        ->toBe('Ab jetzt startet „Willkommensserie“, sobald der Zugang „choir-course“ gewährt wird, etwa wenn jemand „Choir course“ kauft.');

    $this->map->connect(mapConnection());

    expect(fn () => $this->map->connect(mapConnection('lead_magnet', 'freebie')))
        ->toThrow(DomainException::class, '„Willkommensserie“ startet schon für „choir-course“. Erst lösen.');
});

it('refuses a pair that has no source provider installed', function () {
    // Without the product addon registered there is nothing to refuse on, only the pair rules; the source check does.
    mapAutomation('uuid-willkommen', 'Willkommensserie', 'welcome-daily');

    expect(fn () => $this->map->connectionPreview(mapConnection(), 'connect'))->toThrow(DomainException::class);
});

/*
 * Details: the mails of an automation in step order, for the panel on the
 * funnels map. Read only; a picture appears only when one has been rendered.
 */

function mapDetailNode(Automation $automation, string $key, string $type, array $config = []): AutomationNode
{
    return AutomationNode::query()->create([
        'automation_id' => $automation->id,
        'node_key' => $key,
        'type' => $type,
        'config' => $config,
    ]);
}

function mapDetailChain(Automation $automation, array $keys): void
{
    foreach (array_slice($keys, 0, -1) as $i => $from) {
        AutomationEdge::query()->create([
            'automation_id' => $automation->id,
            'from_node_key' => $from,
            'to_node_key' => $keys[$i + 1],
        ]);
    }
}

it('lists the mails in step order, not in creation order', function () {
    $automation = mapAutomation('uuid-serie', 'Serie', 'serie');
    mapDetailNode($automation, 'm2', 'send_email', ['subject' => 'Tag 3']);
    mapDetailNode($automation, 'w1', 'delay', ['amount' => 2, 'unit' => 'days']);
    mapDetailNode($automation, 't', 'event_trigger');
    mapDetailNode($automation, 'm1', 'send_email', ['subject' => 'Willkommen']);
    mapDetailChain($automation, ['t', 'm1', 'w1', 'm2']);

    $blocks = $this->map->details('serie', MapPeriod::lastDays(30));

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['kind'])->toBe('list')
        ->and($blocks[0]['title'])->toBe('Mails')
        ->and(array_column($blocks[0]['items'], 'title'))->toBe(['Willkommen', 'Tag 3'])
        ->and($blocks[0]['items'][0]['image'])->toBeNull()
        ->and($blocks[0]['more'])->toBeNull();
});

it('words the waits in whole units, German too', function () {
    app()->setLocale('de');
    $automation = mapAutomation('uuid-serie', 'Serie', 'serie');
    mapDetailNode($automation, 'm1', 'send_email', ['subject' => 'Eins']);
    mapDetailNode($automation, 'w1', 'delay', ['amount' => 2, 'unit' => 'days']);
    mapDetailNode($automation, 'm2', 'send_email', ['subject' => 'Zwei']);
    mapDetailNode($automation, 'w2', 'delay', ['amount' => 5, 'unit' => 'hours']);
    mapDetailNode($automation, 'm3', 'send_email', ['subject' => 'Drei']);
    mapDetailChain($automation, ['m1', 'w1', 'm2', 'w2', 'm3']);

    $blocks = $this->map->details('serie', MapPeriod::lastDays(30));

    expect($blocks[0]['title'])->toBe('Mails')
        ->and(array_column($blocks[0]['items'], 'meta'))->toBe(['sofort', 'nach 2 Tagen', 'nach 5 Stunden']);
});

it('adds up the waits between two mails', function () {
    $automation = mapAutomation('uuid-serie', 'Serie', 'serie');
    mapDetailNode($automation, 'm1', 'send_email', ['subject' => 'One']);
    mapDetailNode($automation, 'w1', 'delay', ['amount' => 1, 'unit' => 'days']);
    mapDetailNode($automation, 'w2', 'delay', ['amount' => 1, 'unit' => 'days']);
    mapDetailNode($automation, 'm2', 'send_email', ['subject' => 'Two']);
    mapDetailNode($automation, 'w3', 'delay', ['amount' => 90, 'unit' => 'minutes']);
    mapDetailNode($automation, 'm3', 'send_email', ['subject' => 'Three']);
    mapDetailChain($automation, ['m1', 'w1', 'w2', 'm2', 'w3', 'm3']);

    $items = $this->map->details('serie', MapPeriod::lastDays(30))[0]['items'];

    expect(array_column($items, 'meta'))->toBe(['right away', 'after 2 days', 'after 90 minutes']);
});

it('shows a thumbnail only when one already exists and never renders one', function () {
    Storage::fake('public');
    config()->set('automations.thumbnails.enabled', true);
    config()->set('automations.thumbnails.disk', 'public');
    $automation = mapAutomation('uuid-serie', 'Serie', 'serie');
    $first = mapDetailNode($automation, 'm1', 'send_email', ['subject' => 'Eins']);
    mapDetailNode($automation, 'm2', 'send_email', ['subject' => 'Zwei']);
    mapDetailChain($automation, ['m1', 'm2']);
    Storage::disk('public')->put(MailThumbnails::path($automation, 'm1', $first->config), 'PNG');

    $items = $this->map->details('serie', MapPeriod::lastDays(30))[0]['items'];

    expect($items[0]['image'])->toBe(MailThumbnails::url($automation, 'send_email', 'm1', $first->config))
        ->and($items[0]['image'])->not->toBeNull()
        ->and($items[1]['image'])->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('falls back to a clear title when a mail has neither subject nor a known template', function () {
    $automation = mapAutomation('uuid-serie', 'Serie', 'serie');
    mapDetailNode($automation, 'm1', 'send_email', ['subject' => '', 'template' => 'weg']);
    mapDetailNode($automation, 'm2', 'send_email', []);
    mapDetailChain($automation, ['m1', 'm2']);

    $items = $this->map->details('serie', MapPeriod::lastDays(30))[0]['items'];

    expect($items[0]['title'])->toBe('Mail without subject (template "weg")')
        ->and($items[1]['title'])->toBe('Mail without subject');
});

it('takes the subject of the template in one lookup when the mail has none of its own', function () {
    $asker = new class(app(AutomationRepository::class)) extends AutomationMapObjects
    {
        public int $lookups = 0;

        protected function templateSubjects(array $slugs): array
        {
            $this->lookups++;

            return ['a' => 'Subject of A', 'b' => 'Subject of B'];
        }
    };
    $automation = mapAutomation('uuid-serie', 'Serie', 'serie');
    mapDetailNode($automation, 'm1', 'send_email', ['template' => 'a']);
    mapDetailNode($automation, 'm2', 'send_email', ['template' => 'b']);
    mapDetailNode($automation, 'm3', 'send_email', ['subject' => 'Own', 'template' => 'a']);
    mapDetailChain($automation, ['m1', 'm2', 'm3']);

    $items = $asker->details('serie', MapPeriod::lastDays(30))[0]['items'];

    expect(array_column($items, 'title'))->toBe(['Subject of A', 'Subject of B', 'Own'])
        ->and($asker->lookups)->toBe(1);
});

it('lists the marketing mail node too, in template and campaign mode', function () {
    $automation = mapAutomation('uuid-serie', 'Serie', 'serie');
    mapDetailNode($automation, 'm1', 'marketing.send_email', ['template' => 'kauf-1', 'subject' => 'Danke, {{ contact.first_name }}']);
    mapDetailNode($automation, 'w1', 'delay', ['amount' => 3, 'unit' => 'days']);
    mapDetailNode($automation, 'm2', 'marketing.send_email', ['campaign' => 'nachfass']);
    mapDetailNode($automation, 'm3', 'send_email', ['subject' => 'Plain']);
    mapDetailChain($automation, ['m1', 'w1', 'm2', 'm3']);

    $items = $this->map->details('serie', MapPeriod::lastDays(30))[0]['items'];

    expect(array_column($items, 'title'))->toBe(['Danke, {{ contact.first_name }}', 'Campaign "nachfass"', 'Plain'])
        ->and(array_column($items, 'meta'))->toBe(['right away', 'after 3 days', 'right away']);
});

it('shows at most twelve mails and counts the rest', function () {
    $automation = mapAutomation('uuid-serie', 'Serie', 'serie');
    $keys = [];
    foreach (range(1, 14) as $i) {
        mapDetailNode($automation, "m{$i}", 'send_email', ['subject' => "Mail {$i}"]);
        $keys[] = "m{$i}";
    }
    mapDetailChain($automation, $keys);

    $block = $this->map->details('serie', MapPeriod::lastDays(30))[0];

    expect($block['items'])->toHaveCount(12)->and($block['more'])->toBe(2);
});

it('has no details for an unknown automation or one without mails', function () {
    $automation = mapAutomation('uuid-leer', 'Leer', 'leer');
    mapDetailNode($automation, 'w', 'delay', ['amount' => 1, 'unit' => 'days']);

    expect($this->map->details('gibt-es-nicht', MapPeriod::lastDays(30)))->toBeNull()
        ->and($this->map->details('leer', MapPeriod::lastDays(30)))->toBeNull();
});
