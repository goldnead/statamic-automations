<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicAutomations\Integrations\Map\AutomationMapObjects;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeEntitlementManager;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/*
 * The newsletter's welcome series asks "did she just unlock something?" (`entitlements.recent_grant`) and branches on
 * the answer (`nodes.recent.found`, `nodes.recent.product_title`, a switch on `nodes.recent.product_slug`). That is the
 * real shape on production, and it is a fact about how the person came: through a freebie or not. The dry run reads it
 * from the person (the trait `entitlements.recent_grant` holds the slug of the freebie, `…_age` how many minutes ago)
 * instead of ending at the question.
 */

function srgWelcome(array $recentConfig = ['email' => '{{ subscriber.email }}', 'within_minutes' => 60]): void
{
    $a = Automation::query()->create(['uuid' => 'uuid-srg', 'name' => 'Willkommen täglich', 'handle' => 'srg', 'enabled' => true]);

    $nodes = [
        ['trigger', 'manual', null, []],
        ['recent', 'entitlements.recent_grant', 'Gerade etwas freigeschaltet?', $recentConfig],
        ['has_grant', 'branch', 'Über ein Freebie gekommen?', ['mode' => 'all', 'conditions' => [['field' => 'nodes.recent.found', 'operator' => 'equals', 'value' => 'true']]]],
        ['has_title', 'branch', 'Titel bekannt?', ['mode' => 'all', 'conditions' => [['field' => 'nodes.recent.product_title', 'operator' => 'is_not_empty', 'value' => '']]]],
        ['send_plain', 'marketing.send_email', 'Willkommen 1', ['template' => 'willkommen-1', 'subject' => 'Schön, dass du da bist', 'list' => 'newsletter']],
        ['send_bridge', 'marketing.send_email', 'Übergang Freebie', ['template' => 'uebergang', 'subject' => 'Wie es nach „{{ nodes.recent.product_title }}“ weitergeht', 'list' => 'newsletter']],
    ];

    foreach ($nodes as [$key, $type, $label, $config]) {
        AutomationNode::query()->create(['automation_id' => $a->id, 'node_key' => $key, 'type' => $type, 'label' => $label, 'config' => $config]);
    }

    foreach ([
        ['trigger', 'default', 'recent'],
        ['recent', 'default', 'has_grant'],
        ['has_grant', 'true', 'has_title'],
        ['has_grant', 'false', 'send_plain'],
        ['has_title', 'true', 'send_bridge'],
        ['has_title', 'false', 'send_plain'],
    ] as [$from, $output, $to]) {
        AutomationEdge::query()->create(['automation_id' => $a->id, 'from_node_key' => $from, 'from_output' => $output, 'to_node_key' => $to]);
    }
}

/** @return list<string> kind:label of every effect, depth first */
function srgFlat(array $effects): array
{
    $out = [];

    foreach ($effects as $e) {
        $out[] = $e->kind.':'.$e->label.($e->detail !== null && $e->detail !== '' ? ' ['.$e->detail.']' : '');
        array_push($out, ...srgFlat($e->children));
    }

    return $out;
}

function srgReact(SimPerson $person): array
{
    return app(AutomationMapObjects::class)->react('srg', new SimEvent(SimEvent::STARTED), $person, CarbonImmutable::parse('2026-10-22 10:00:00'));
}

it('takes the plain welcome way for a person who did not come through a freebie', function () {
    srgWelcome();

    $flat = implode("\n", srgFlat(srgReact(SimPerson::known())));

    expect($flat)->toContain('mail:Schön, dass du da bist')
        ->and($flat)->not->toContain('Wie es nach')
        ->and($flat)->not->toContain('unknown:Gerade etwas freigeschaltet?')
        ->and($flat)->not->toContain('unknown:Über ein Freebie gekommen?');
});

it('takes the bridge way for a person who came through a freebie with a known name', function () {
    srgWelcome();
    config()->set('automations.entitlements.title_sources', [['collection' => 'resources', 'match' => 'resource_slug', 'field' => 'title']]);
    Collection::make('resources')->save();
    Entry::make()->collection('resources')->slug('toolkit')->data(['title' => 'Das Toolkit', 'resource_slug' => 'toolkit'])->save();

    $flat = srgFlat(srgReact(SimPerson::known(['entitlements.recent_grant' => 'toolkit'])));
    $text = implode("\n", $flat);

    expect($text)->toContain('mail:Wie es nach „Das Toolkit“ weitergeht')
        ->and($text)->not->toContain('mail:Schön, dass du da bist')
        ->and($text)->not->toContain('unknown:');
});

it('says "no name" and takes the plain way when the freebie has no name the site knows', function () {
    srgWelcome();

    $text = implode("\n", srgFlat(srgReact(SimPerson::known(['entitlements.recent_grant' => 'ohne-namen']))));

    expect($text)->toContain('mail:Schön, dass du da bist')->and($text)->not->toContain('Wie es nach');
});

it('does not count a grant that lies further back than the step looks', function () {
    srgWelcome(['email' => '{{ subscriber.email }}', 'within_minutes' => 60]);

    $old = implode("\n", srgFlat(srgReact(SimPerson::known(['entitlements.recent_grant' => 'toolkit', 'entitlements.recent_grant_age' => 61]))));
    $fresh = implode("\n", srgFlat(srgReact(SimPerson::known(['entitlements.recent_grant' => 'toolkit', 'entitlements.recent_grant_age' => 59]))));

    expect($old)->toContain('Nothing was just unlocked')
        ->and($fresh)->toContain('Just unlocked: toolkit');
});

it('does not decide for a step that looks up somebody else', function () {
    srgWelcome(['email' => 'fest@example.com', 'within_minutes' => 60]);

    $text = implode("\n", srgFlat(srgReact(SimPerson::known(['entitlements.recent_grant' => 'toolkit']))));

    expect($text)->toContain('unknown:');
});

it('names the steps and the course in the way it explains, not the raw keys', function () {
    srgWelcome();

    $text = implode("\n", srgFlat(srgReact(SimPerson::known(['entitlements.recent_grant' => 'toolkit']))));

    expect($text)->not->toContain('nodes recent')->and($text)->not->toContain('nodes.recent')
        ->and($text)->toContain('Gerade etwas freigeschaltet?');
});

it('words a question about the awaited automation and about an access by their names', function () {
    $a = Automation::query()->create(['uuid' => 'uuid-srg', 'name' => 'Warten', 'handle' => 'srg', 'enabled' => true]);
    Automation::query()->create(['uuid' => 'uuid-mk', 'name' => 'Minikurs CVT im Chor', 'handle' => 'minikurs', 'enabled' => true]);

    foreach ([
        ['trigger', 'manual', null, []],
        ['wait', 'wait_for_automation', 'Warten, bis der Minikurs vorbei ist', ['email' => '{{ subscriber.email }}', 'automation' => 'minikurs']],
        ['done', 'branch', 'Kurs gelesen?', ['mode' => 'all', 'conditions' => [['field' => 'nodes.wait.ran_to_end', 'operator' => 'equals', 'value' => 'true']]]],
        ['own', 'entitlements.check_access', 'Hat den Kurs?', ['email' => '{{ subscriber.email }}', 'product_slug' => 'kurs-x', 'mode' => 'report']],
        ['has', 'branch', 'Kurs da?', ['mode' => 'all', 'conditions' => [['field' => 'nodes.own.has_access', 'operator' => 'equals', 'value' => 'true']]]],
        ['end', 'stop', null, []],
    ] as [$key, $type, $label, $config]) {
        AutomationNode::query()->create(['automation_id' => $a->id, 'node_key' => $key, 'type' => $type, 'label' => $label, 'config' => $config]);
    }

    foreach ([['trigger', 'default', 'wait'], ['wait', 'default', 'done'], ['done', 'true', 'own'], ['done', 'false', 'end'], ['own', 'default', 'has'], ['has', 'true', 'end'], ['has', 'false', 'end']] as [$from, $output, $to]) {
        AutomationEdge::query()->create(['automation_id' => $a->id, 'from_node_key' => $from, 'from_output' => $output, 'to_node_key' => $to]);
    }

    $text = implode("\n", srgFlat(srgReact(SimPerson::known([], ['kurs-x'], ['minikurs']))));

    expect($text)->toContain('Minikurs CVT im Chor')
        ->and($text)->not->toContain('nodes wait')
        ->and($text)->not->toContain('ran to end')
        ->and($text)->not->toContain('nodes own')->and($text)->toContain('has access to ‘kurs-x’');
});

/** A stand-in for the lead magnets resource model: the title of the lead magnet whose `access_product` is asked. */
class SrgFakeResource
{
    public ?string $title = null;

    private ?string $product = null;

    public static function query(): static
    {
        return new static;
    }

    public function where(string $column, mixed $value): static
    {
        $this->product = $column === 'access_product' ? (string) $value : null;

        return $this;
    }

    public function first(): ?static
    {
        $this->title = $this->product === 'toolkit' ? 'Das Toolkit (Lead Magnet)' : null;

        return $this->title === null ? null : $this;
    }
}

it('names a freebie by the lead magnet behind it when the catalogue and the site do not', function () {
    srgWelcome();
    config()->set('automations.integrations.lead_magnets.resource_model', SrgFakeResource::class);

    $text = implode("\n", srgFlat(srgReact(SimPerson::known(['entitlements.recent_grant' => 'toolkit']))));

    expect($text)->toContain('mail:Wie es nach „Das Toolkit (Lead Magnet)“ weitergeht');
});

it('does not decide for a step whose window the engine would refuse', function () {
    srgWelcome(['email' => '{{ subscriber.email }}', 'within_minutes' => 0]);

    $text = implode("\n", srgFlat(srgReact(SimPerson::known(['entitlements.recent_grant' => 'toolkit']))));

    expect($text)->toContain('unknown:');
});

it('keeps the number of a comparison that is not about yes or no, and turns a negated one around', function () {
    $a = Automation::query()->create(['uuid' => 'uuid-srg', 'name' => 'Zahl', 'handle' => 'srg', 'enabled' => true]);

    foreach ([
        ['trigger', 'manual', null, []],
        ['recent', 'entitlements.recent_grant', 'Gerade etwas freigeschaltet?', ['email' => '{{ subscriber.email }}']],
        ['neg', 'branch', 'Nicht frisch?', ['mode' => 'all', 'conditions' => [['field' => 'nodes.recent.found', 'operator' => 'does_not_equal', 'value' => 'true']]]],
        ['n', 'branch', 'Zahl?', ['mode' => 'all', 'conditions' => [['field' => 'nodes.other.count', 'operator' => 'greater_than', 'value' => '0']]]],
        ['other', 'delay', 'Anderer Schritt', ['amount' => 1, 'unit' => 'days']],
        ['end', 'stop', null, []],
    ] as [$key, $type, $label, $config]) {
        AutomationNode::query()->create(['automation_id' => $a->id, 'node_key' => $key, 'type' => $type, 'label' => $label, 'config' => $config]);
    }

    foreach ([['trigger', 'default', 'recent'], ['recent', 'default', 'neg'], ['neg', 'true', 'n'], ['neg', 'false', 'end'], ['n', 'true', 'end'], ['n', 'false', 'end']] as [$from, $output, $to]) {
        AutomationEdge::query()->create(['automation_id' => $a->id, 'from_node_key' => $from, 'from_output' => $output, 'to_node_key' => $to]);
    }

    $text = implode("\n", srgFlat(srgReact(SimPerson::known())));

    // Nothing was just unlocked, so "found is not true" holds: said as the person's, not as "found ≠ yes".
    expect($text)->toContain('the person has not just unlocked anything')
        ->and($text)->not->toContain('≠');
});

/*
 * Production, 22.10.2026: the product catalogue (statamic-products) names the access of the freebie "CVT im Chor"
 * "CVT im Chor — Masterclass", the lead magnet is called "CVT im Chor". The mail to a person who came through the
 * freebie must name the freebie, in the dry run and in a real run alike.
 */
function srgCatalogue(array $products): void
{
    require_once __DIR__.'/../Fixtures/CommerceServiceDoubles.php';

    $manager = new FakeEntitlementManager;
    $manager->products = $products;
    app()->instance(FakeEntitlementManager::class, $manager);
    config()->set('automations.integrations.entitlements.manager', FakeEntitlementManager::class);
}

it('names the freebie in the bridge mail by its lead magnet, not by the access the catalogue names', function () {
    srgWelcome();
    srgCatalogue(['toolkit' => ['slug' => 'toolkit', 'label' => 'Das Toolkit — Masterclass', 'group' => null]]);
    config()->set('automations.integrations.lead_magnets.resource_model', SrgFakeResource::class);

    $text = implode("\n", srgFlat(srgReact(SimPerson::known(['entitlements.recent_grant' => 'toolkit']))));

    expect($text)->toContain('mail:Wie es nach „Das Toolkit (Lead Magnet)“ weitergeht')
        ->and($text)->not->toContain('Masterclass');
});

it('shows the name of the case in "why", not the slug the switch compares', function () {
    $a = Automation::query()->create(['uuid' => 'uuid-srg', 'name' => 'Weiche', 'handle' => 'srg', 'enabled' => true]);
    srgCatalogue([]);
    config()->set('automations.integrations.lead_magnets.resource_model', SrgFakeResource::class);

    foreach ([
        ['trigger', 'manual', null, []],
        ['recent', 'entitlements.recent_grant', 'Gerade etwas freigeschaltet?', ['email' => '{{ subscriber.email }}']],
        ['which', 'switch', 'Welches Freebie?', ['value' => '{{ nodes.recent.product_slug }}', 'cases' => ['toolkit' => 'toolkit']]],
        ['end', 'stop', null, []],
    ] as [$key, $type, $label, $config]) {
        AutomationNode::query()->create(['automation_id' => $a->id, 'node_key' => $key, 'type' => $type, 'label' => $label, 'config' => $config]);
    }

    foreach ([['trigger', 'default', 'recent'], ['recent', 'default', 'which'], ['which', 'toolkit', 'end'], ['which', 'default', 'end']] as [$from, $output, $to]) {
        AutomationEdge::query()->create(['automation_id' => $a->id, 'from_node_key' => $from, 'from_output' => $output, 'to_node_key' => $to]);
    }

    $taken = implode("\n", srgFlat(srgReact(SimPerson::known(['entitlements.recent_grant' => 'toolkit']))));
    $tree = implode("\n", srgFlat(app(AutomationMapObjects::class)->react('srg', new SimEvent(SimEvent::STARTED), SimPerson::unknown(), CarbonImmutable::parse('2026-10-22 10:00:00'))));

    expect($taken)->toContain('Das Toolkit (Lead Magnet)')
        ->and($taken)->not->toMatch('/(case|Fall) toolkit/')
        ->and($tree)->toContain('Das Toolkit (Lead Magnet)')
        ->and($tree)->not->toMatch('/(case|Fall) toolkit/');
});
