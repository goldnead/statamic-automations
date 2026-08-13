<?php

use Goldnead\Marketing\Events\MarketingSubscribed;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\EnrollmentGate;
use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Integrations\Marketing\Triggers\SubscriberConfirmedTrigger;
use Goldnead\StatamicAutomations\Listeners\HandleMarketingEvent;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Goldnead\StatamicAutomations\Support\RestartPolicy;
use Illuminate\Support\Facades\Queue;

/**
 * What happens when somebody enters an automation they have already entered.
 *
 * The first case is the one that matters most: the default has not changed and
 * may not change. Every automation in every install is on `always`, and a
 * release that quietly started suppressing enrollments would stop flows nobody
 * had touched.
 */
beforeEach(function (): void {
    $this->gate = app(EnrollmentGate::class);

    $this->automationWith = function (?string $policy, ?string $subjectKey = null): Automation {
        $automation = Automation::create([
            'name' => 'Welcome',
            'handle' => 'welcome_'.bin2hex(random_bytes(4)),
            'enabled' => true,
        ]);

        $config = [];

        if ($policy !== null) {
            $config[RestartPolicy::CONFIG_KEY] = $policy;
        }

        if ($subjectKey !== null) {
            $config[RestartPolicy::SUBJECT_CONFIG_KEY] = $subjectKey;
        }

        $automation->nodes()->create([
            'node_key' => 't',
            'type' => 'manual',
            'position_x' => 0,
            'position_y' => 0,
            'config' => $config,
        ]);

        return $automation->fresh(['nodes', 'edges']);
    };

    $this->evaluate = fn (Automation $automation, array $context = ['subscriber' => ['email' => 'jane@example.com']]) => $this->gate->evaluate(
        $automation,
        $automation->nodes->first(),
        AutomationContext::make($context),
    );

    $this->priorRun = fn (Automation $automation, string $status, string $subject = 'jane@example.com') => AutomationRun::create([
        'automation_id' => $automation->id,
        'automation_uuid' => $automation->uuid,
        'subject_key' => $subject,
        'status' => $status,
    ]);
});

it('enrolls again every time when nothing is configured — the unchanged default', function (): void {
    $automation = ($this->automationWith)(null);

    ($this->priorRun)($automation, AutomationRun::STATUS_SUCCESS);
    ($this->priorRun)($automation, AutomationRun::STATUS_WAITING);

    $verdict = ($this->evaluate)($automation);

    expect($verdict['allowed'])->toBeTrue()
        ->and($verdict['policy'])->toBe(RestartPolicy::Always);
});

it('reads an unknown policy as the default rather than as a new rule', function (): void {
    // A value from a later release, or a typo in an imported YAML file, must
    // not silently start suppressing enrollments.
    expect(RestartPolicy::fromValue('something-from-2027'))->toBe(RestartPolicy::Always)
        ->and(RestartPolicy::fromValue(''))->toBe(RestartPolicy::Always)
        ->and(RestartPolicy::fromValue(null))->toBe(RestartPolicy::Always);
});

it('records who a run is about even on the default policy', function (): void {
    // Needed by the next event, and by the distinct-people count, so it is
    // written whether or not any policy uses it today.
    $verdict = ($this->evaluate)(($this->automationWith)(null));

    expect($verdict['subject_key'])->toBe('jane@example.com');
});

it('ignores a repeat when the policy is ignore', function (): void {
    $automation = ($this->automationWith)(RestartPolicy::Ignore->value);

    expect(($this->evaluate)($automation)['allowed'])->toBeTrue();

    ($this->priorRun)($automation, AutomationRun::STATUS_SUCCESS);

    $verdict = ($this->evaluate)($automation);

    expect($verdict['allowed'])->toBeFalse()
        ->and($verdict['reason'])->toBe('already_enrolled');
});

it('ignores a repeat even when the earlier pass failed', function (): void {
    // "Ever been in this automation" is the question, deliberately. Somebody
    // whose first pass errored on mail two has still had mail one.
    $automation = ($this->automationWith)(RestartPolicy::Ignore->value);

    ($this->priorRun)($automation, AutomationRun::STATUS_FAILED);

    expect(($this->evaluate)($automation)['allowed'])->toBeFalse();
});

it('leaves a running pass alone when the policy is resume', function (): void {
    $automation = ($this->automationWith)(RestartPolicy::Resume->value);

    $waiting = ($this->priorRun)($automation, AutomationRun::STATUS_WAITING);

    $verdict = ($this->evaluate)($automation);

    expect($verdict['allowed'])->toBeFalse()
        ->and($verdict['reason'])->toBe('already_running')
        // Untouched: it carries on from where it is, which for a run parked in
        // a delay means the delay keeps running rather than restarting.
        ->and($waiting->fresh()->status)->toBe(AutomationRun::STATUS_WAITING);
});

it('enrolls under resume when every earlier pass has finished', function (): void {
    $automation = ($this->automationWith)(RestartPolicy::Resume->value);

    ($this->priorRun)($automation, AutomationRun::STATUS_SUCCESS);

    expect(($this->evaluate)($automation)['allowed'])->toBeTrue();
});

it('cancels the open pass AND its wake-up call when the policy is restart', function (): void {
    $automation = ($this->automationWith)(RestartPolicy::Restart->value);

    $waiting = ($this->priorRun)($automation, AutomationRun::STATUS_WAITING);

    $job = AutomationScheduledJob::create([
        'automation_id' => $automation->id,
        'automation_run_id' => $waiting->id,
        'node_key' => 'd1',
        'due_at' => now()->addDays(2),
        'status' => AutomationScheduledJob::STATUS_PENDING,
        'payload' => [],
    ]);

    $verdict = ($this->evaluate)($automation);

    expect($verdict['allowed'])->toBeTrue()
        ->and($verdict['reason'])->toBe('restarted')
        ->and($waiting->fresh()->status)->toBe(AutomationRun::STATUS_CANCELLED)
        // The part that is easy to forget: a cancelled run with a live
        // scheduled job wakes up two days later next to the new pass, which is
        // exactly what this policy exists to prevent.
        ->and($job->fresh()->status)->toBe(AutomationScheduledJob::STATUS_CANCELLED);
});

it('leaves a finished pass alone under restart', function (): void {
    $automation = ($this->automationWith)(RestartPolicy::Restart->value);

    $done = ($this->priorRun)($automation, AutomationRun::STATUS_SUCCESS);

    expect(($this->evaluate)($automation)['allowed'])->toBeTrue()
        ->and($done->fresh()->status)->toBe(AutomationRun::STATUS_SUCCESS);
});

it('falls back to the default when the event names nobody', function (): void {
    // A scheduled sweep is a run without a subject, not a run with an unknown
    // one. Treating every subjectless run as the same subject would make one
    // nightly sweep block every later one for ever.
    $automation = ($this->automationWith)(RestartPolicy::Ignore->value);

    ($this->priorRun)($automation, AutomationRun::STATUS_SUCCESS, 'jane@example.com');

    $verdict = ($this->evaluate)($automation, ['some' => 'payload']);

    expect($verdict['allowed'])->toBeTrue()
        ->and($verdict['reason'])->toBe('no_subject')
        ->and($verdict['subject_key'])->toBeNull();
});

it('treats the same address in different casing as the same person', function (): void {
    $automation = ($this->automationWith)(RestartPolicy::Ignore->value);

    ($this->priorRun)($automation, AutomationRun::STATUS_SUCCESS, 'jane@example.com');

    $verdict = ($this->evaluate)($automation, ['subscriber' => ['email' => ' JANE@Example.com ']]);

    expect($verdict['allowed'])->toBeFalse();
});

it('follows a configured subject token', function (): void {
    $automation = ($this->automationWith)(RestartPolicy::Ignore->value, '{{ order.customer_id }}');

    ($this->priorRun)($automation, AutomationRun::STATUS_SUCCESS, '4711');

    expect(($this->evaluate)($automation, ['order' => ['customer_id' => '4711']])['allowed'])->toBeFalse()
        ->and(($this->evaluate)($automation, ['order' => ['customer_id' => '4712']])['allowed'])->toBeTrue();
});

it('never lets one automation block another', function (): void {
    $first = ($this->automationWith)(RestartPolicy::Ignore->value);
    $second = ($this->automationWith)(RestartPolicy::Ignore->value);

    ($this->priorRun)($first, AutomationRun::STATUS_SUCCESS);

    expect(($this->evaluate)($first)['allowed'])->toBeFalse()
        ->and(($this->evaluate)($second)['allowed'])->toBeTrue();
});

it('offers the policy on every trigger, whoever wrote the trigger', function (): void {
    // Appended by the registry rather than written into twenty-two trigger
    // classes — so a third-party trigger, and the config-driven EventTrigger
    // that serves many handles from one class, get it too.
    $handles = collect(app(NodeRegistry::class)->byKind('trigger'));

    expect($handles)->not->toBeEmpty();

    $handles->each(function (array $trigger): void {
        $fields = collect($trigger['schema'])->pluck('handle');

        expect($fields)->toContain(RestartPolicy::CONFIG_KEY, RestartPolicy::SUBJECT_CONFIG_KEY);
    });

    // …and only on triggers. An action has no enrollment to govern.
    collect(app(NodeRegistry::class)->byKind('action'))
        ->each(function (array $action): void {
            expect(collect($action['schema'])->pluck('handle'))->not->toContain(RestartPolicy::CONFIG_KEY);
        });
});

it('is applied by the dispatcher, not only by the gate', function (): void {
    // The gate is only worth having if the one caller that creates runs asks
    // it. This is that wiring.
    $dispatcher = app(TriggerDispatcher::class);

    expect($dispatcher)->toBeInstanceOf(TriggerDispatcher::class);

    $reflection = new ReflectionMethod(TriggerDispatcher::class, 'dispatch');
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('enrollment()->evaluate');
});

it('is applied by the listeners too, not only by the dispatcher', function (): void {
    // The gate lived in TriggerDispatcher, and the four dedicated listeners
    // (marketing, LeadHub, forms, entries) never went through it — they built
    // a context and called createRun() straight away. So on the one trigger a
    // welcome sequence actually starts from, `ignore` was read by nobody: the
    // field was in the config, the CP showed it, and a second confirmation
    // started a second pass in parallel with the first.
    //
    // The marketing addon is not installed here, so its event class is stubbed
    // the way EmailTemplatesStub stubs its sibling. The listener only reads the
    // class name and `toPayload()`.
    if (! class_exists(MarketingSubscribed::class)) {
        eval('namespace Goldnead\Marketing\Events; class MarketingSubscribed { public function __construct(public array $data = []) {} public function toPayload(): array { return $this->data; } }');
    }

    // The marketing trigger is only registered when the addon is present, so
    // it is registered by hand here — the listener looks it up by handle.
    app(TriggerRegistry::class)->register(
        SubscriberConfirmedTrigger::handle(),
        SubscriberConfirmedTrigger::class,
    );

    Queue::fake();

    $automation = Automation::create([
        'name' => 'Willkommen',
        'handle' => 'willkommen_'.bin2hex(random_bytes(4)),
        'enabled' => true,
    ]);

    $automation->nodes()->create([
        'node_key' => 't',
        'type' => 'marketing.subscribed',
        'position_x' => 0,
        'position_y' => 0,
        'config' => [RestartPolicy::CONFIG_KEY => RestartPolicy::Ignore->value],
    ]);

    $listener = app(HandleMarketingEvent::class);
    $event = new MarketingSubscribed(['email' => 'jane@example.com', 'list' => 'newsletter']);

    $listener->handle($event);
    $listener->handle($event);

    $runs = AutomationRun::query()->where('automation_uuid', $automation->uuid)->get();

    // One welcome, and it knows whose it is — under the old code this was two
    // runs, both with subject_key null.
    expect($runs)->toHaveCount(1)
        ->and($runs->first()->subject_key)->toBe('jane@example.com');
});
