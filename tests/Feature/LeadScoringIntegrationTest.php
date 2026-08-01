<?php

/**
 * LeadHub lead-scoring integration for the automations addon:
 *   (1) opt-in link-click rewriting in the SendEmail send path,
 *   (2) the `contact_score_changed` event trigger,
 *   (3) the `leadhub.change_score` action.
 *
 * All three are guarded so they degrade gracefully when LeadHub is absent. The
 * LeadHub surface is faked here via container bindings / a fake facade root, so
 * these tests need no dependency on the sibling addon.
 */

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Integrations\LeadHub\Actions\ChangeScoreAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubEventTriggers;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Illuminate\Support\Facades\Queue;

// Stand-in for the OPTIONAL email-templates addon (not vendored in this repo).
require_once __DIR__.'/../Fixtures/EmailTemplatesStub.php';

/** Bind fake LeadHub click-tracking services under the seam's container keys. */
function bindFakeClickTracking(?object $contact): void
{
    app()->instance(LeadHubAdapter::RECIPIENT_RESOLVER, new class($contact)
    {
        public function __construct(private ?object $contact) {}

        public function resolve(?string $contactId = null, ?string $email = null): ?object
        {
            return $this->contact;
        }
    });

    app()->instance(LeadHubAdapter::CLICK_LINKER, new class
    {
        public function rewriteHtml(string $html, object $contact, array $context = []): string
        {
            return str_replace('https://shop.test', 'https://track.test/go', $html);
        }
    });
}

// ---------------------------------------------------------------------------
// (1) Link-click rewriting in the SendEmail path
// ---------------------------------------------------------------------------

it('rewrites body links via the LeadHub linker when tracking is opted in and a contact resolves', function () {
    EmailTemplates::reset();
    bindFakeClickTracking((object) ['uuid' => 'c1', 'email' => 'a@b.test']);
    EmailTemplates::$entries = ['welcome' => ['body' => '<a href="https://shop.test">Kaufen</a>']];

    $result = (new SendEmailAction)->execute(
        AutomationContext::make(['contact_id' => 'c1'], testMode: true),
        ['to' => 'a@b.test', 'subject' => 'S', 'body' => 'x', 'template' => 'welcome', 'track_clicks' => true],
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['preview']['html'])->toContain('https://track.test/go');
    expect($result->output['preview']['html'])->not->toContain('https://shop.test');
});

it('leaves the HTML untouched when LeadHub click-tracking is absent', function () {
    EmailTemplates::reset();
    EmailTemplates::$entries = ['welcome' => ['body' => '<a href="https://shop.test">Kaufen</a>']];

    $result = (new SendEmailAction)->execute(
        AutomationContext::make(['contact_id' => 'c1'], testMode: true),
        ['to' => 'a@b.test', 'subject' => 'S', 'body' => 'x', 'template' => 'welcome', 'track_clicks' => true],
    );

    expect($result->output['preview']['html'])->toContain('https://shop.test');
});

it('leaves the HTML untouched when tracking is not opted in', function () {
    EmailTemplates::reset();
    bindFakeClickTracking((object) ['uuid' => 'c1', 'email' => 'a@b.test']);
    EmailTemplates::$entries = ['welcome' => ['body' => '<a href="https://shop.test">Kaufen</a>']];

    $result = (new SendEmailAction)->execute(
        AutomationContext::make(['contact_id' => 'c1'], testMode: true),
        ['to' => 'a@b.test', 'subject' => 'S', 'body' => 'x', 'template' => 'welcome'], // no track_clicks
    );

    expect($result->output['preview']['html'])->toContain('https://shop.test');
});

it('leaves the HTML untouched when the recipient does not resolve to a contact', function () {
    EmailTemplates::reset();
    bindFakeClickTracking(null); // resolver returns no contact
    EmailTemplates::$entries = ['welcome' => ['body' => '<a href="https://shop.test">Kaufen</a>']];

    $result = (new SendEmailAction)->execute(
        AutomationContext::make([], testMode: true),
        ['to' => 'ghost@b.test', 'subject' => 'S', 'body' => 'x', 'template' => 'welcome', 'track_clicks' => true],
    );

    expect($result->output['preview']['html'])->toContain('https://shop.test');
});

// ---------------------------------------------------------------------------
// (2) contact_score_changed event trigger
// ---------------------------------------------------------------------------

/** @param array<string,mixed> $config */
function makeScoreTriggerAutomation(string $triggerHandle, array $config = []): Automation
{
    $automation = Automation::create(['name' => 'On score', 'handle' => "on-{$triggerHandle}", 'enabled' => true]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => $triggerHandle, 'config' => $config]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'log', 'type' => 'add_log_entry', 'config' => ['message' => 'score {{ new_score }}']]);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'log']);

    return $automation;
}

it('registers the contact_score_changed trigger in the node library', function () {
    $this->actingAsSuperUser();
    config()->set('automations.integrations.leadhub.score_changed_event', LeadHubScoreChangedTestEvent::class);
    LeadHubEventTriggers::register(app('automations'));

    $triggers = $this->getJson('/cp/automations/api/nodes')->assertOk()->json('data.triggers');
    $entry = collect($triggers)->firstWhere('handle', 'contact_score_changed');

    expect($entry)->not->toBeNull();
    expect($entry['label'])->toBe('Contact score changed');
    expect($entry['group'])->toBe('LeadHub');
    expect($entry['output_schema'])->toHaveKey('new_score');
});

it('funnels a dispatched score-changed event into the dispatcher', function () {
    Queue::fake();
    config()->set('automations.integrations.leadhub.score_changed_event', LeadHubScoreChangedTestEvent::class);
    LeadHubEventTriggers::register(app('automations'));

    $automation = makeScoreTriggerAutomation('contact_score_changed');

    event(new LeadHubScoreChangedTestEvent('c1', 'a@b.test', 10, 15, 5, 'opened'));

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('maps the score-changed payload onto the automation context', function () {
    config()->set('automations.integrations.leadhub.score_changed_event', LeadHubScoreChangedTestEvent::class);
    LeadHubEventTriggers::register(app('automations'));

    $trigger = app(TriggerRegistry::class)->instance('contact_score_changed');
    $context = $trigger->buildContext(new LeadHubScoreChangedTestEvent('c1', 'a@b.test', 10, 15, 5, 'opened'), []);

    expect($context->get('new_score'))->toBe(15);
    expect($context->get('delta'))->toBe(5);
    expect($context->get('contact_id'))->toBe('c1');
});

it('does not register the score trigger when the LeadHub event class is absent', function () {
    config()->set('automations.integrations.leadhub.score_changed_event', 'Goldnead\\Leadhub\\Events\\DoesNotExist');
    LeadHubEventTriggers::register(app('automations'));

    expect(app(TriggerRegistry::class)->instance('contact_score_changed'))->toBeNull();
});

// ---------------------------------------------------------------------------
// (3) leadhub.change_score action
// ---------------------------------------------------------------------------

it('exposes the expected handle, group and output schema', function () {
    expect(ChangeScoreAction::handle())->toBe('leadhub.change_score');
    expect(ChangeScoreAction::group())->toBe('LeadHub');
    expect(ChangeScoreAction::outputSchema())->toHaveKey('new_score');
});

it('calls adjustScore with the resolved delta and returns the new score', function () {
    config()->set('automations.integrations.leadhub.facade', [FakeScoringLeadHub::class]);
    FakeScoringLeadHub::$calls = [];

    $action = new ChangeScoreAction(new LeadHubAdapter);
    $result = $action->execute(
        AutomationContext::make(['contact_id' => 'c1']),
        ['delta' => '5', 'reason' => 'opened'],
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['new_score'])->toBe(42);
    expect($result->output['delta'])->toBe(5);
    expect(FakeScoringLeadHub::$calls[0])->toBe(['c1', 5, 'opened']);
});

it('defaults the contact reference to the triggering contact_id', function () {
    config()->set('automations.integrations.leadhub.facade', [FakeScoringLeadHub::class]);
    FakeScoringLeadHub::$calls = [];

    $action = new ChangeScoreAction(new LeadHubAdapter);
    $action->execute(AutomationContext::make(['contact_id' => 'from-context']), ['delta' => '3']);

    expect(FakeScoringLeadHub::$calls[0][0])->toBe('from-context');
});

it('degrades gracefully when LeadHub is absent', function () {
    config()->set('automations.integrations.leadhub.facade', []);

    $action = new ChangeScoreAction(new LeadHubAdapter);
    $result = $action->execute(AutomationContext::make(['contact_id' => 'c1']), ['delta' => '5']);

    expect($result->isSuccess())->toBeFalse();
    expect($result->error)->toContain('LeadHub not installed');
});

it('previews without persisting in test mode', function () {
    config()->set('automations.integrations.leadhub.facade', [FakeScoringLeadHub::class]);
    FakeScoringLeadHub::$calls = [];

    $action = new ChangeScoreAction(new LeadHubAdapter);
    $result = $action->execute(AutomationContext::make(['contact_id' => 'c1'], testMode: true), ['delta' => '7']);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output)->toHaveKey('preview');
    expect(FakeScoringLeadHub::$calls)->toBeEmpty();
});

it('requires a numeric delta', function () {
    $action = new ChangeScoreAction(new LeadHubAdapter);
    $result = $action->execute(AutomationContext::make(['contact_id' => 'c1']), ['delta' => 'abc']);

    expect($result->isSuccess())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Test doubles for the (not-yet-vendored) LeadHub surface.
// ---------------------------------------------------------------------------

/** Stand-in for Goldnead\Leadhub\Events\LeadHubContactScoreChanged. */
class LeadHubScoreChangedTestEvent
{
    public function __construct(
        public string $contactId,
        public string $email,
        public int $oldScore,
        public int $newScore,
        public int $delta,
        public ?string $reason = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'contact_id' => $this->contactId,
            'email' => $this->email,
            'old_score' => $this->oldScore,
            'new_score' => $this->newScore,
            'delta' => $this->delta,
            'reason' => $this->reason,
        ];
    }
}

/** Stand-in for the LeadHub facade root exposing adjustScore(). */
class FakeScoringLeadHub
{
    /** @var array<int, array{0:string,1:int,2:?string}> */
    public static array $calls = [];

    public static function adjustScore(string $contact, int $delta, ?string $reason = null): int
    {
        self::$calls[] = [$contact, $delta, $reason];

        return 42;
    }
}
