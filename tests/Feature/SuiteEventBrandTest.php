<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\Courses\Events\LearnerEnrolled;
use Goldnead\StatamicAutomations\Integrations\Courses\Triggers as CT;
use Goldnead\StatamicAutomations\Integrations\Funnels\Triggers\FunnelOfferDeclinedTrigger;
use Goldnead\StatamicAutomations\Integrations\Funnels\Triggers\UpsellDeclinedTrigger;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Integrations\Payments\Triggers as PT;
use Goldnead\StatamicAutomations\Listeners\HandleCommerceEvent;
use Goldnead\StatamicAutomations\Listeners\HandleFunnelOrPaymentEvent;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\EventBrand;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Goldnead\StatamicFunnels\Events\FunnelOfferDeclined;
use Goldnead\StatamicPayments\Events\CheckoutBlocked;
use Goldnead\StatamicPayments\Events\SubscriptionPaymentUpcoming;
use Goldnead\StatamicPayments\Events\SubscriptionRenewed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

require_once __DIR__.'/../Fixtures/CommerceEventStubs.php';
require_once __DIR__.'/../Fixtures/SuiteEventStubs.php';

/**
 * Gauntlet round 2 for A1: which brand an event belongs to, and what the
 * editor says about it.
 *
 * The finding that started this: `runFor()` looked for automations only in the
 * brand that happened to be current. A scheduler run has none, so fifteen
 * "payment upcoming" events started nothing; a webhook falls back to the
 * default brand, so another brand's flows started, under the wrong sender.
 * The brand is now read off the event.
 */
class SuiteEventBrandTest extends TestCase
{
    private const FORCED = ['payments', 'funnels', 'courses', 'affiliates'];

    private int $brandA;

    private int $brandB;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        IntegrationDetector::flush();

        foreach (self::FORCED as $integration) {
            $app['config']->set("automations.integrations.{$integration}.detect", [self::class]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();

        $this->brandA = $this->brand('marke-a');
        $this->brandB = $this->brand('marke-b');
    }

    protected function tearDown(): void
    {
        app('brand-context')->forget();
        IntegrationDetector::flush();

        parent::tearDown();
    }

    // --- 1. the brand comes from the event ----------------------------------

    public function test_a_scheduler_event_without_a_current_brand_runs_in_the_events_brand(): void
    {
        Queue::fake();

        $flow = $this->automationIn($this->brandB, 'payments.subscription_payment_upcoming');

        // `payments:reminders` on the console: no brand is current.
        $this->assertFalse(app('brand-context')->hasCurrent());

        event(new SubscriptionPaymentUpcoming(
            (object) ['id' => 1, 'product' => 'kurs', 'brand_id' => $this->brandB],
            Carbon::parse('2026-10-01'),
            3,
        ));

        $runs = $this->runsOf($flow);
        $this->assertCount(1, $runs);
        $this->assertSame($this->brandB, (int) $runs->first()->brand_id);
        $this->assertFalse(app('brand-context')->hasCurrent(), 'The brand leaked out of the listener.');
    }

    public function test_a_webhook_under_the_default_brand_starts_only_the_events_brand(): void
    {
        Queue::fake();

        $mine = $this->automationIn($this->brandB, 'payments.subscription_renewed');
        $wrong = $this->automationIn($this->brandA, 'payments.subscription_renewed');

        // A webhook request that fell back to brand A, for a renewal of brand B.
        app('brand-context')->setCurrent($this->brandA);

        event(new SubscriptionRenewed(
            subscription: (object) ['id' => 5, 'product' => 'kurs', 'brand_id' => $this->brandB],
            payment: (object) ['id' => 2, 'product' => 'kurs', 'brand_id' => $this->brandB],
        ));

        $this->assertCount(1, $this->runsOf($mine));
        $this->assertCount(0, $this->runsOf($wrong), 'A flow of the wrong brand started.');
        $this->assertSame($this->brandA, app('brand-context')->currentId(), 'The request brand was not restored.');
    }

    public function test_an_event_without_a_brand_uses_the_current_one(): void
    {
        Queue::fake();

        $flow = $this->automationIn($this->brandB, 'funnels.offer_declined');
        $other = $this->automationIn($this->brandA, 'funnels.offer_declined');

        app('brand-context')->setCurrent($this->brandB);

        $visit = (object) ['id' => 1, 'email' => 'k@example.com'];
        $visit->funnel = (object) ['handle' => 'kurs', 'title' => 'Kurs'];
        event(new FunnelOfferDeclined($visit, (object) ['node_key' => 'upsell_1', 'config' => ['offer' => 'noten']]));

        $this->assertCount(1, $this->runsOf($flow));
        $this->assertCount(0, $this->runsOf($other));
    }

    public function test_no_brand_anywhere_is_a_warning_not_silence(): void
    {
        Queue::fake();
        Log::spy();

        $flow = $this->automationIn($this->brandB, 'payments.checkout_blocked');

        event(new CheckoutBlocked('captcha', 'x@example.com', '10.1.2.3'));

        $this->assertCount(0, $this->runsOf($flow));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'brand')
                && ($context['trigger'] ?? null) === 'payments.checkout_blocked')
            ->once();
    }

    public function test_an_unknown_brand_on_the_event_is_a_warning_not_a_crash(): void
    {
        Queue::fake();
        Log::spy();

        $flow = $this->automationIn($this->brandB, 'payments.subscription_payment_upcoming');

        event(new SubscriptionPaymentUpcoming((object) ['id' => 1, 'brand_id' => 999], Carbon::parse('2026-10-01'), 3));

        $this->assertCount(0, $this->runsOf($flow));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'brand'))->once();
    }

    public function test_the_resolver_reads_an_explicit_brand_id_first(): void
    {
        // The shape courses will send: a `brandId` next to models of another brand.
        $event = new class($this->brandA, (object) ['brand_id' => $this->brandB])
        {
            public function __construct(
                public readonly int $brandId,
                public readonly object $payment,
            ) {}
        };

        $resolver = app(EventBrand::class);

        $this->assertSame($this->brandA, $resolver->of($event));
        $this->assertSame($this->brandB, $resolver->of((object) ['commission' => (object) ['brand_id' => $this->brandB]]));
        $this->assertNull($resolver->of((object) ['reason' => 'captcha']));
    }

    public function test_a_course_event_runs_in_the_brand_it_names(): void
    {
        Queue::fake();

        $user = User::make()->email('lerner-b@example.com');
        $user->save();

        $mine = $this->automationIn($this->brandB, 'courses.learner_enrolled');
        $wrong = $this->automationIn($this->brandA, 'courses.learner_enrolled');

        // courses 0ec0f86: every course event carries `brandId`.
        event(new LearnerEnrolled((string) $user->id(), 'c-1', 'stimme', $this->brandB));

        $this->assertCount(1, $this->runsOf($mine));
        $this->assertCount(0, $this->runsOf($wrong));
    }

    public function test_a_course_event_finds_its_brand_through_the_course_entry(): void
    {
        Queue::fake();

        config()->set('brand-context.sites', ['default' => 'marke-b']);
        Collection::make('courses')->save();
        Entry::make()->collection('courses')->id('c-1')->slug('stimme')->data(['title' => 'Stimme'])->save();

        $user = User::make()->email('lerner@example.com');
        $user->save();

        $flow = $this->automationIn($this->brandB, 'courses.learner_enrolled');

        event(new LearnerEnrolled((string) $user->id(), 'c-1', 'stimme'));

        $this->assertCount(1, $this->runsOf($flow));
    }

    // --- 5. a learner who is gone -------------------------------------------

    public function test_a_course_event_for_a_deleted_user_starts_nothing_and_says_so(): void
    {
        Queue::fake();
        Log::spy();

        app('brand-context')->setCurrent($this->brandB);
        $flow = $this->automationIn($this->brandB, 'courses.learner_enrolled');

        event(new LearnerEnrolled('no-such-user', 'c-1', 'stimme'));

        $this->assertCount(0, $this->runsOf($flow));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'learner'))
            ->once();
    }

    public function test_a_team_seat_still_runs_without_an_account(): void
    {
        $this->assertTrue((new CT\TeamMemberAddedTrigger)->matches(
            ['ownerId' => 'gone', 'courseId' => 'c-1', 'courseSlug' => 'stimme', 'email' => 'neu@example.com'],
            [],
        ));
    }

    // --- 4. blocked checkout ------------------------------------------------

    public function test_a_blocked_checkout_carries_no_full_ip_address(): void
    {
        $context = (new PT\CheckoutBlockedTrigger)
            ->buildContext(new CheckoutBlocked('blocked_ip', 'x@example.com', '203.0.113.77'), [])
            ->all();

        $this->assertArrayNotHasKey('ip', $context['blocked']);
        $this->assertSame('203.0.113.0/24', $context['blocked']['ip_prefix']);
        $this->assertStringNotContainsString('203.0.113.77', json_encode($context));

        $v6 = (new PT\CheckoutBlockedTrigger)
            ->buildContext(new CheckoutBlocked('blocked_ip', null, '2001:db8:85a3:8d3:1319:8a2e:370:7348'), [])
            ->get('blocked.ip_prefix');
        $this->assertSame('2001:db8:85a3::/48', $v6);

        $this->assertStringContainsString('blocked.email', PT\CheckoutBlockedTrigger::description());
    }

    // --- 3. two triggers on one moment --------------------------------------

    public function test_triggers_that_fire_together_say_so(): void
    {
        $this->assertStringContainsString('funnels.upsell_declined', FunnelOfferDeclinedTrigger::description());
        $this->assertStringContainsString('funnels.offer_declined', UpsellDeclinedTrigger::description());
        $this->assertStringContainsString('payments.subscription_ended', PT\SubscriptionPlanCompletedTrigger::description());
        $this->assertStringContainsString('payments.subscription_plan_completed', PT\SubscriptionEndedTrigger::description());
    }

    // --- 2. German in the CP ------------------------------------------------

    public function test_the_node_library_describes_triggers_in_german(): void
    {
        app()->setLocale('de');

        $described = app(NodeRegistry::class)->describe('payments.subscription_paused');

        $this->assertSame('Abo pausiert', $described['label']);
        $this->assertSame('Zahlungen', $described['group']);
        $this->assertStringContainsStringIgnoringCase('pausiert', $described['description']);
    }

    public function test_every_commerce_course_partner_and_funnel_trigger_has_a_german_label_and_description(): void
    {
        $german = require __DIR__.'/../../resources/lang/de/triggers.php';
        $handles = array_merge(
            array_values(HandleFunnelOrPaymentEvent::PAYMENT_TRIGGERS),
            array_values(HandleFunnelOrPaymentEvent::FUNNEL_TRIGGERS),
            array_values(HandleCommerceEvent::COURSE_TRIGGERS),
            array_values(HandleCommerceEvent::AFFILIATE_TRIGGERS),
        );

        $nodes = app(NodeRegistry::class);

        foreach ($handles as $handle) {
            $class = $nodes->class($handle);
            $this->assertNotNull($class, $handle);

            $this->assertArrayHasKey($class::group(), $german['groups'], "{$handle}: group has no German name.");

            foreach (['label', 'description'] as $part) {
                $text = data_get($german, "{$handle}.{$part}");
                $this->assertIsString($text, "{$handle}: {$part} has no German translation.");
                $this->assertStringNotContainsString('—', $text, "{$handle}: dash in the German {$part}.");
            }
        }
    }

    public function test_the_german_names_do_not_leak_into_other_addons(): void
    {
        // Site-wide JSON keys would rename these words everywhere in the CP.
        app()->setLocale('de');

        $this->assertSame('Courses', __('Courses'));
        $this->assertSame('Quiz Passed', __('Quiz Passed'));
    }

    // --- helpers ------------------------------------------------------------

    private function brand(string $handle): int
    {
        return DB::table('brands')->insertGetId([
            'handle' => $handle,
            'name' => ucfirst($handle),
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function automationIn(int $brand, string $handle): Automation
    {
        return app('brand-context')->runFor($brand, function () use ($handle) {
            $automation = Automation::create([
                'name' => "On {$handle}",
                'handle' => 'on-'.str_replace('.', '-', $handle).'-'.bin2hex(random_bytes(4)),
                'enabled' => true,
            ]);

            AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => $handle, 'config' => []]);
            AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'log', 'type' => 'add_log_entry', 'config' => ['message' => 'fired']]);
            AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'log']);

            return $automation;
        });
    }

    /** @return \Illuminate\Support\Collection<int, AutomationRun> */
    private function runsOf(Automation $automation): \Illuminate\Support\Collection
    {
        return app('brand-context')->withoutBrandScope(
            fn () => AutomationRun::query()->where('automation_id', $automation->id)->get()
        );
    }
}
