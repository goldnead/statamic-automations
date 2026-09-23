<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\Affiliates\Events\CommissionEarned;
use Goldnead\Affiliates\Events\PartnerApproved;
use Goldnead\Courses\Events\LearnerEnrolled;
use Goldnead\Courses\Events\LessonCompleted;
use Goldnead\Courses\Events\QuizFailed;
use Goldnead\Courses\Events\TeamMemberAdded;
use Goldnead\StatamicAutomations\Integrations\Affiliates\Triggers as AT;
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
use Goldnead\StatamicAutomations\Tests\TestCase;
use Goldnead\StatamicFunnels\Events\FunnelOfferDeclined;
use Goldnead\StatamicFunnels\Events\UpsellDeclined;
use Goldnead\StatamicPayments\Events\CheckoutBlocked;
use Goldnead\StatamicPayments\Events\SubscriptionAttemptFailed;
use Goldnead\StatamicPayments\Events\SubscriptionPaused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\User;

require_once __DIR__.'/../Fixtures/SuiteEventStubs.php';

/**
 * A1 and A2 of the Suite build (23.09.2026): the events payments, courses,
 * affiliates and funnels gained, reachable from the editor, and the purchase
 * triggers narrowed to one offer or one pricing option.
 *
 * Same stance as {@see CommerceTriggersTest}: a trigger that is registered but
 * never reached looks exactly like one that works, so the whole path from a
 * dispatched event to a started run is tested once per group.
 */
class SuiteEventTriggersTest extends TestCase
{
    private const FORCED = ['payments', 'funnels', 'courses', 'affiliates'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        IntegrationDetector::flush();

        foreach (self::FORCED as $integration) {
            $app['config']->set("automations.integrations.{$integration}.detect", [self::class]);
        }
    }

    protected function tearDown(): void
    {
        IntegrationDetector::flush();

        parent::tearDown();
    }

    // --- the maps -----------------------------------------------------------

    public function test_every_new_payments_event_maps_to_a_trigger(): void
    {
        $map = HandleFunnelOrPaymentEvent::PAYMENT_TRIGGERS;

        foreach ([
            'SubscriptionPaused' => 'payments.subscription_paused',
            'SubscriptionResumed' => 'payments.subscription_resumed',
            'SubscriptionPaymentUpcoming' => 'payments.subscription_payment_upcoming',
            'SubscriptionCardExpiring' => 'payments.subscription_card_expiring',
            'SubscriptionCardExpired' => 'payments.subscription_card_expired',
            'SubscriptionAttemptFailed' => 'payments.subscription_attempt_failed',
            'SubscriptionPlanCompleted' => 'payments.subscription_plan_completed',
            'SubscriptionChanged' => 'payments.subscription_changed',
            'SubscriptionReplaced' => 'payments.subscription_replaced',
            'CheckoutBlocked' => 'payments.checkout_blocked',
            'PaymentChargedBack' => 'payments.charged_back',
        ] as $class => $handle) {
            $this->assertSame($handle, $map['Goldnead\\StatamicPayments\\Events\\'.$class] ?? null, $class);
        }

        $this->assertSame(
            'funnels.offer_declined',
            HandleFunnelOrPaymentEvent::FUNNEL_TRIGGERS['Goldnead\\StatamicFunnels\\Events\\FunnelOfferDeclined'] ?? null,
        );
        $this->assertSame(
            'funnels.upsell_declined',
            HandleFunnelOrPaymentEvent::FUNNEL_TRIGGERS['Goldnead\\StatamicFunnels\\Events\\UpsellDeclined'] ?? null,
        );
    }

    public function test_every_course_and_affiliate_event_maps_to_a_trigger(): void
    {
        $this->assertSame([
            'Goldnead\\Courses\\Events\\LearnerEnrolled' => 'courses.learner_enrolled',
            'Goldnead\\Courses\\Events\\LessonCompleted' => 'courses.lesson_completed',
            'Goldnead\\Courses\\Events\\LessonUnlocked' => 'courses.lesson_unlocked',
            'Goldnead\\Courses\\Events\\QuizPassed' => 'courses.quiz_passed',
            'Goldnead\\Courses\\Events\\QuizFailed' => 'courses.quiz_failed',
            'Goldnead\\Courses\\Events\\CourseCompleted' => 'courses.course_completed',
            'Goldnead\\Courses\\Events\\DripPaused' => 'courses.drip_paused',
            'Goldnead\\Courses\\Events\\DripResumed' => 'courses.drip_resumed',
            'Goldnead\\Courses\\Events\\CourseAccessSuspended' => 'courses.access_suspended',
            'Goldnead\\Courses\\Events\\CourseAccessRestored' => 'courses.access_restored',
            'Goldnead\\Courses\\Events\\TeamMemberAdded' => 'courses.team_member_added',
            'Goldnead\\Courses\\Events\\TeamMemberRemoved' => 'courses.team_member_removed',
        ], HandleCommerceEvent::COURSE_TRIGGERS);

        $this->assertSame([
            'Goldnead\\Affiliates\\Events\\CommissionEarned' => 'affiliates.commission_earned',
            'Goldnead\\Affiliates\\Events\\CommissionReversed' => 'affiliates.commission_reversed',
            'Goldnead\\Affiliates\\Events\\PartnerApplied' => 'affiliates.partner_applied',
            'Goldnead\\Affiliates\\Events\\PartnerApproved' => 'affiliates.partner_approved',
        ], HandleCommerceEvent::AFFILIATE_TRIGGERS);
    }

    public function test_all_new_triggers_are_registered_and_their_classes_agree_with_the_maps(): void
    {
        $nodes = app(NodeRegistry::class);
        $mapped = array_merge(
            HandleFunnelOrPaymentEvent::PAYMENT_TRIGGERS,
            HandleFunnelOrPaymentEvent::FUNNEL_TRIGGERS,
            HandleCommerceEvent::COURSE_TRIGGERS,
            HandleCommerceEvent::AFFILIATE_TRIGGERS,
        );

        foreach ($this->allNewTriggers() as $class) {
            $this->assertTrue($nodes->has($class::handle()), "{$class::handle()} is not registered.");
            $this->assertContains($class::handle(), $mapped, "{$class::handle()} has no event behind it.");
            $this->assertNotEmpty($class::outputSchema(), "{$class::handle()} describes no output.");
        }
    }

    public function test_every_new_trigger_flattens_to_exactly_its_output_schema(): void
    {
        // The picker offers what outputSchema() says; the run carries what
        // buildContext() writes. A key in one and not the other is a token
        // that either resolves to nothing or cannot be found.
        foreach ($this->allNewTriggers() as $class) {
            $context = (new $class)->buildContext($this->sampleEvent(), [])->all();
            $schema = $class::outputSchema();

            $this->assertSame(array_keys($schema), array_keys($context), $class::handle());

            foreach ($schema as $key => $fragment) {
                if (is_array($fragment) && is_array($context[$key]) && $context[$key] !== []) {
                    $this->assertSame(array_keys($fragment), array_keys($context[$key]), $class::handle().' '.$key);
                }
            }
        }
    }

    public function test_every_new_trigger_survives_an_event_that_carries_nothing(): void
    {
        foreach ($this->allNewTriggers() as $class) {
            $trigger = new $class;

            // A course trigger about a learner declines an event that names
            // nobody (see SuiteEventBrandTest); every other one accepts it.
            $learner = is_subclass_of($class, CT\CourseTrigger::class)
                && ! is_a($class, CT\TeamMemberAddedTrigger::class, true);

            $this->assertSame(! $learner, $trigger->matches(new \stdClass, []), $class::handle());
            $this->assertIsArray($trigger->buildContext([], [])->all(), $class::handle());
        }
    }

    // --- filters ------------------------------------------------------------

    public function test_the_attempt_filter_picks_the_nth_failure(): void
    {
        $trigger = new PT\SubscriptionAttemptFailedTrigger;
        $event = fn (int $n) => ['subscription' => ['product' => 'kurs'], 'payment' => ['product' => 'kurs'], 'attempt' => $n];

        $this->assertTrue($trigger->matches($event(1), []));
        $this->assertFalse($trigger->matches($event(1), ['attempt' => 3]));
        $this->assertTrue($trigger->matches($event(3), ['attempt' => '3']));
        $this->assertSame(3, $trigger->buildContext($event(3), [])->get('attempt'));
    }

    public function test_a_change_can_be_narrowed_to_upgrades(): void
    {
        $trigger = new PT\SubscriptionChangedTrigger;
        $up = ['subscription' => ['product' => 'plus'], 'fromAmountCent' => 1000, 'toAmountCent' => 2000, 'immediate' => true];
        $down = ['subscription' => ['product' => 'basis'], 'fromAmountCent' => 2000, 'toAmountCent' => 1000, 'immediate' => false];

        $this->assertTrue($trigger->matches($up, ['direction' => 'upgrade']));
        $this->assertFalse($trigger->matches($down, ['direction' => 'upgrade']));
        $this->assertTrue($trigger->matches($down, ['direction' => 'downgrade']));
        $this->assertSame('downgrade', $trigger->buildContext($down, [])->get('change.direction'));
    }

    public function test_a_blocked_checkout_filters_by_reason(): void
    {
        $trigger = new PT\CheckoutBlockedTrigger;
        $event = new CheckoutBlocked('captcha', 'x@example.com', '10.0.0.1');

        $this->assertTrue($trigger->matches($event, ['reason' => 'captcha']));
        $this->assertFalse($trigger->matches($event, ['reason' => 'blocked_email']));
        $this->assertSame('x@example.com', $trigger->buildContext($event, [])->get('blocked.email'));
    }

    public function test_a_declined_offer_filters_by_funnel_and_step(): void
    {
        $trigger = new FunnelOfferDeclinedTrigger;
        $event = [
            'visit' => ['funnel' => 'kurs', 'email' => 'k@example.com'],
            'step' => ['key' => 'upsell_1', 'offer' => 'noten'],
        ];

        $this->assertTrue($trigger->matches($event, []));
        $this->assertTrue($trigger->matches($event, ['funnel' => 'kurs', 'step' => 'upsell_1']));
        $this->assertFalse($trigger->matches($event, ['funnel' => 'anderer']));
        $this->assertFalse($trigger->matches($event, ['step' => 'upsell_2']));
        $this->assertTrue($trigger->matches($event, ['offer' => 'noten']));
        $this->assertFalse($trigger->matches($event, ['offer' => 'andere']));
    }

    public function test_a_declined_offer_reads_the_offer_off_a_real_step(): void
    {
        $visit = (object) ['id' => 7, 'email' => 'K@Example.com', 'name' => 'Kim'];
        $visit->funnel = (object) ['handle' => 'kurs', 'title' => 'Kurs'];
        $step = new class
        {
            public string $node_key = 'upsell_1';

            public string $type = 'offer';

            public string $label = 'Noten dazu';

            public function config(string $key): mixed
            {
                return ['offer' => 'noten'][$key] ?? null;
            }
        };

        $context = (new FunnelOfferDeclinedTrigger)->buildContext(new FunnelOfferDeclined($visit, $step), [])->all();

        $this->assertSame('noten', $context['step']['offer']);
        $this->assertSame('kurs', $context['visit']['funnel']);
        $this->assertSame('K@Example.com', $context['email']);
    }

    public function test_a_declined_upsell_carries_the_purchase_before_it(): void
    {
        $visit = (object) ['id' => 7, 'email' => null];
        $visit->funnel = (object) ['handle' => 'kurs', 'title' => 'Kurs'];
        $step = (object) ['node_key' => 'upsell_1', 'type' => 'offer', 'config' => ['offer' => 'aus-der-config']];
        $payment = (object) ['id' => 3, 'product' => 'offer:kurs', 'amount_cent' => 9900, 'email' => 'kaeuferin@example.com'];

        $trigger = new FunnelOfferDeclinedTrigger;
        $upsell = new UpsellDeclinedTrigger;
        $event = new UpsellDeclined($visit, $step, 'noten', $payment);

        $context = $upsell->buildContext($event, [])->all();

        // The event's own offer wins over the step config.
        $this->assertSame('noten', $context['step']['offer']);
        $this->assertSame('offer:kurs', $context['payment']['product']);
        // No address on the visit: the payment has one.
        $this->assertSame('kaeuferin@example.com', $context['email']);

        $this->assertTrue($upsell->matches($event, ['offer' => 'noten', 'bought_offer' => 'kurs']));
        $this->assertFalse($upsell->matches($event, ['bought_offer' => 'anderes']));
        // Bought through an instalment option of the same offer: still that offer.
        $raten = new UpsellDeclined($visit, $step, 'noten', (object) ['id' => 4, 'product' => 'offer:kurs:raten3']);
        $this->assertTrue($upsell->matches($raten, ['bought_offer' => 'kurs']));
        $this->assertFalse($upsell->matches($event, ['offer' => 'aus-der-config']));
        $this->assertSame('funnels.upsell_declined', $upsell::handle());
        $this->assertNotSame($trigger::handle(), $upsell::handle());
    }

    public function test_a_declined_upsell_starts_a_run_and_a_plain_decline_does_not(): void
    {
        Queue::fake();

        $automation = $this->automationStartingOn('funnels.upsell_declined', ['offer' => 'noten']);

        $visit = (object) ['id' => 1, 'email' => 'k@example.com'];
        $visit->funnel = (object) ['handle' => 'kurs', 'title' => 'Kurs'];
        $step = (object) ['node_key' => 'upsell_1', 'type' => 'offer', 'config' => ['offer' => 'noten']];

        event(new FunnelOfferDeclined($visit, $step));
        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());

        event(new UpsellDeclined($visit, $step, 'noten', (object) ['id' => 3, 'product' => 'offer:kurs']));
        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    // --- A2: offer and pricing option --------------------------------------

    public function test_the_offer_filter_matches_every_way_of_buying_that_offer(): void
    {
        $trigger = new PT\PaymentPaidTrigger;
        $paid = fn (string $product) => ['payment' => ['product' => $product]];

        foreach (['offer:kurs', 'offer:kurs:raten3', 'offer:kurs:=2500'] as $product) {
            $this->assertTrue($trigger->matches($paid($product), ['offer' => 'kurs']), $product);
        }

        $this->assertFalse($trigger->matches($paid('offer:kurs-plus'), ['offer' => 'kurs']));
        $this->assertFalse($trigger->matches($paid('kurs'), ['offer' => 'kurs']));
    }

    public function test_the_pricing_option_filter_matches_only_that_option(): void
    {
        $trigger = new PT\SubscriptionStartedTrigger;
        $started = fn (string $product) => ['subscription' => ['product' => $product]];

        $this->assertTrue($trigger->matches($started('offer:kurs:raten3'), ['pricing_option' => 'offer:kurs:raten3']));
        $this->assertFalse($trigger->matches($started('offer:kurs:raten6'), ['pricing_option' => 'offer:kurs:raten3']));
        $this->assertFalse($trigger->matches($started('offer:kurs'), ['pricing_option' => 'offer:kurs:raten3']));
        $this->assertFalse($trigger->matches($started('offer:andere:raten3'), ['pricing_option' => 'offer:kurs:raten3']));
    }

    public function test_the_product_filter_stays_exact_as_stored_flows_expect(): void
    {
        $trigger = new PT\PaymentPaidTrigger;

        $this->assertTrue($trigger->matches(['payment' => ['product' => 'offer:kurs']], ['product' => 'offer:kurs']));
        $this->assertFalse($trigger->matches(['payment' => ['product' => 'offer:kurs:raten3']], ['product' => 'offer:kurs']));
    }

    public function test_the_purchase_triggers_offer_both_new_filter_fields(): void
    {
        foreach ([PT\PaymentPaidTrigger::class, PT\SubscriptionStartedTrigger::class, PT\SubscriptionRenewedTrigger::class, PT\SubscriptionPausedTrigger::class] as $class) {
            $fields = collect($class::schema())->keyBy('handle');

            $this->assertSame('offers.offers', $fields['offer']['options_source'] ?? null, $class);
            $this->assertSame('offers.pricing_options', $fields['pricing_option']['options_source'] ?? null, $class);
        }
    }

    public function test_the_offer_option_sources_are_empty_without_the_offers_addon(): void
    {
        $this->actingAsSuperUser();

        foreach (['offers.offers', 'offers.pricing_options', 'courses.courses'] as $source) {
            $data = $this->getJson("/cp/automations/api/options/{$source}")->assertOk()->json('data');

            $this->assertSame([], $data, $source);
        }
    }

    public function test_a_replacement_matches_on_what_was_bought(): void
    {
        $trigger = new PT\SubscriptionReplacedTrigger;
        $event = [
            'replaced' => ['product' => 'offer:basis'],
            'purchase' => ['product' => 'offer:plus:jahr'],
            'replacement' => ['product' => 'offer:plus:jahr'],
            'creditCent' => 500,
            'creditDays' => 12,
        ];

        $this->assertTrue($trigger->matches($event, ['offer' => 'plus']));
        $this->assertFalse($trigger->matches($event, ['offer' => 'basis']));
        $this->assertTrue($trigger->matches($event, ['replaced_product' => 'offer:basis']));
        $this->assertSame(12, $trigger->buildContext($event, [])->get('credit.days'));
    }

    // --- people -------------------------------------------------------------

    public function test_a_course_event_names_the_learner_by_address(): void
    {
        $user = User::make()->email('lerner@example.com')->set('name', 'Lena Lerner');
        $user->save();

        $context = (new CT\LearnerEnrolledTrigger)
            ->buildContext(new LearnerEnrolled((string) $user->id(), 'c-1', 'stimme'), [])
            ->all();

        $this->assertSame('lerner@example.com', $context['user']['email']);
        $this->assertSame('Lena Lerner', $context['user']['name']);
        $this->assertSame('stimme', $context['course']['slug']);
    }

    public function test_a_lesson_event_reads_its_state(): void
    {
        $user = User::make()->email('atem@example.com');
        $user->save();

        $state = (object) [
            'user_id' => (string) $user->id(),
            'course_entry_id' => 'c-1',
            'course_slug' => 'stimme',
            'lesson_entry_id' => 'l-1',
            'lesson_slug' => 'atem',
            'section_title' => 'Grundlagen',
        ];

        $trigger = new CT\LessonCompletedTrigger;
        $event = new LessonCompleted($state, 'manual');

        $this->assertTrue($trigger->matches($event, ['course' => 'stimme', 'lesson' => 'atem']));
        $this->assertFalse($trigger->matches($event, ['lesson' => 'resonanz']));
        $this->assertSame('atem', $trigger->buildContext($event, [])->get('lesson.slug'));
        $this->assertSame('manual', $trigger->buildContext($event, [])->get('source'));
    }

    public function test_a_course_filter_accepts_the_entry_id_or_the_slug(): void
    {
        $user = User::make()->email('quiz@example.com');
        $user->save();

        $trigger = new CT\QuizFailedTrigger;
        $event = new QuizFailed((string) $user->id(), 'c-1', 'stimme', 'atem', 'quiz-1', 40, null, 9);

        $this->assertTrue($trigger->matches($event, ['course' => 'c-1']));
        $this->assertTrue($trigger->matches($event, ['course' => 'stimme']));
        $this->assertFalse($trigger->matches($event, ['course' => 'anderer']));
        $this->assertSame(40, $trigger->buildContext($event, [])->get('quiz.score'));
    }

    public function test_a_team_seat_is_about_the_member_not_the_buyer(): void
    {
        $context = (new CT\TeamMemberAddedTrigger)
            ->buildContext(new TeamMemberAdded('owner-1', 'c-1', 'stimme', 'Sopran@Example.com', 'offer:team'), [])
            ->all();

        $this->assertSame('Sopran@Example.com', $context['member']['email']);
        $this->assertSame('Sopran@Example.com', $context['email']);
        $this->assertSame('offer:team', $context['product']);
        $this->assertArrayNotHasKey('user', $context);
    }

    public function test_a_commission_carries_its_partner(): void
    {
        $partner = (object) ['id' => 3, 'name' => 'Pia', 'email' => 'pia@example.com', 'code' => 'PIA', 'status' => 'active'];
        $commission = (object) ['id' => 11, 'kind' => 'sale', 'product' => 'offer:kurs', 'amount_cent' => 3000, 'currency' => 'EUR', 'partner' => $partner];

        $trigger = new AT\CommissionEarnedTrigger;
        $context = $trigger->buildContext(new CommissionEarned($commission), [])->all();

        $this->assertSame('pia@example.com', $context['partner']['email']);
        $this->assertSame('pia@example.com', $context['email']);
        $this->assertSame(3000, $context['commission']['amount_cent']);
        $this->assertTrue($trigger->matches(new CommissionEarned($commission), ['kind' => 'sale']));
        $this->assertFalse($trigger->matches(new CommissionEarned($commission), ['kind' => 'jv']));
    }

    // --- the whole path, once per group -------------------------------------

    public function test_a_payments_event_starts_a_run(): void
    {
        Queue::fake();

        $paused = $this->automationStartingOn('payments.subscription_paused');
        $third = $this->automationStartingOn('payments.subscription_attempt_failed', ['attempt' => 3]);

        event(new SubscriptionPaused((object) ['id' => 1, 'product' => 'kurs', 'email' => 'a@example.com'], Carbon::parse('2026-11-01'), 'portal'));
        event(new SubscriptionAttemptFailed((object) ['id' => 1, 'product' => 'kurs'], (object) ['id' => 2, 'product' => 'kurs'], 1));

        $this->assertSame(1, AutomationRun::where('automation_id', $paused->id)->count());
        $this->assertSame(0, AutomationRun::where('automation_id', $third->id)->count());
        $this->assertSame('2026-11-01T00:00:00+00:00', data_get(AutomationRun::where('automation_id', $paused->id)->first()->context, 'resumes_at'));
    }

    public function test_a_course_event_starts_a_run_about_the_learner(): void
    {
        Queue::fake();

        $user = User::make()->email('Lerner@Example.com');
        $user->save();

        $automation = $this->automationStartingOn('courses.learner_enrolled');

        event(new LearnerEnrolled((string) $user->id(), 'c-1', 'stimme'));

        $run = AutomationRun::where('automation_id', $automation->id)->first();

        $this->assertNotNull($run);
        $this->assertSame('lerner@example.com', $run->subject_key);
    }

    public function test_an_affiliate_event_starts_a_run(): void
    {
        Queue::fake();

        $automation = $this->automationStartingOn('affiliates.partner_approved');

        event(new PartnerApproved((object) ['id' => 1, 'name' => 'Pia', 'email' => 'pia@example.com', 'status' => 'active']));

        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    public function test_a_declined_offer_starts_a_run(): void
    {
        Queue::fake();

        $automation = $this->automationStartingOn('funnels.offer_declined', ['funnel' => 'kurs']);

        $visit = (object) ['id' => 1, 'email' => 'k@example.com'];
        $visit->funnel = (object) ['handle' => 'kurs', 'title' => 'Kurs'];

        event(new FunnelOfferDeclined($visit, (object) ['node_key' => 'upsell_1', 'type' => 'offer', 'config' => ['offer' => 'noten']]));

        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    // --- helpers ------------------------------------------------------------

    /** @return list<class-string> */
    private function allNewTriggers(): array
    {
        return [
            PT\SubscriptionPausedTrigger::class,
            PT\SubscriptionResumedTrigger::class,
            PT\SubscriptionPaymentUpcomingTrigger::class,
            PT\SubscriptionCardExpiringTrigger::class,
            PT\SubscriptionCardExpiredTrigger::class,
            PT\SubscriptionAttemptFailedTrigger::class,
            PT\SubscriptionPlanCompletedTrigger::class,
            PT\SubscriptionChangedTrigger::class,
            PT\SubscriptionReplacedTrigger::class,
            PT\CheckoutBlockedTrigger::class,
            PT\PaymentChargedBackTrigger::class,
            FunnelOfferDeclinedTrigger::class,
            UpsellDeclinedTrigger::class,
            CT\LearnerEnrolledTrigger::class,
            CT\LessonCompletedTrigger::class,
            CT\LessonUnlockedTrigger::class,
            CT\QuizPassedTrigger::class,
            CT\QuizFailedTrigger::class,
            CT\CourseCompletedTrigger::class,
            CT\DripPausedTrigger::class,
            CT\DripResumedTrigger::class,
            CT\CourseAccessSuspendedTrigger::class,
            CT\CourseAccessRestoredTrigger::class,
            CT\TeamMemberAddedTrigger::class,
            CT\TeamMemberRemovedTrigger::class,
            AT\CommissionEarnedTrigger::class,
            AT\CommissionReversedTrigger::class,
            AT\PartnerAppliedTrigger::class,
            AT\PartnerApprovedTrigger::class,
        ];
    }

    /**
     * One event carrying every property any of the new triggers reads.
     */
    private function sampleEvent(): object
    {
        $partner = (object) ['id' => 3, 'name' => 'Pia', 'email' => 'pia@example.com', 'code' => 'PIA', 'status' => 'active'];
        $visit = (object) ['id' => 7, 'email' => 'k@example.com'];
        $visit->funnel = (object) ['handle' => 'kurs', 'title' => 'Kurs'];

        return (object) [
            'payment' => (object) ['id' => 1, 'product' => 'offer:kurs:raten3', 'email' => 'a@example.com'],
            'subscription' => (object) ['id' => 2, 'product' => 'offer:kurs:raten3', 'email' => 'a@example.com'],
            'replaced' => (object) ['id' => 2, 'product' => 'offer:basis'],
            'purchase' => (object) ['id' => 4, 'product' => 'offer:plus'],
            'replacement' => (object) ['id' => 5, 'product' => 'offer:plus'],
            'prorationPayment' => (object) ['id' => 6, 'product' => 'offer:plus'],
            'resumesAt' => Carbon::parse('2026-11-01'),
            'dueAt' => Carbon::parse('2026-10-01'),
            'expiresAt' => Carbon::parse('2026-10-31'),
            'expiredAt' => Carbon::parse('2026-09-30'),
            'daysBefore' => 3,
            'attempt' => 2,
            'by' => 'portal',
            'fromProduct' => 'offer:basis',
            'toProduct' => 'offer:plus',
            'fromAmountCent' => 1000,
            'toAmountCent' => 2000,
            'prorationCent' => 500,
            'immediate' => true,
            'creditCent' => 300,
            'creditDays' => 9,
            'reason' => 'captcha',
            'email' => 'x@example.com',
            'ip' => '10.0.0.1',
            'reference' => 'dp_1',
            'amountCent' => 9900,
            'visit' => $visit,
            'step' => (object) ['node_key' => 'upsell_1', 'type' => 'offer', 'label' => 'Noten', 'config' => ['offer' => 'noten']],
            'userId' => 'u-1',
            'ownerId' => 'u-2',
            'courseId' => 'c-1',
            'courseSlug' => 'stimme',
            'lessonSlug' => 'atem',
            'source' => 'manual',
            'assessment' => 'quiz-1',
            'score' => 80,
            'resultKey' => 'gut',
            'responseId' => 12,
            'pausedSeconds' => 3600,
            'product' => 'offer:team',
            'state' => (object) ['user_id' => 'u-1', 'course_entry_id' => 'c-1', 'course_slug' => 'stimme', 'lesson_entry_id' => 'l-1', 'lesson_slug' => 'atem', 'section_title' => 'Grundlagen'],
            'partner' => $partner,
            'commission' => (object) ['id' => 11, 'kind' => 'sale', 'product' => 'offer:kurs', 'amount_cent' => 3000, 'currency' => 'EUR', 'partner' => $partner],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function automationStartingOn(string $handle, array $config = []): Automation
    {
        $automation = Automation::create([
            'name' => "On {$handle}",
            'handle' => 'on-'.str_replace('.', '-', $handle).'-'.bin2hex(random_bytes(4)),
            'enabled' => true,
        ]);

        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't',
            'type' => $handle,
            'config' => $config,
        ]);

        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'log',
            'type' => 'add_log_entry',
            'config' => ['message' => 'fired'],
        ]);

        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => 't',
            'to_node_key' => 'log',
        ]);

        return $automation;
    }
}
