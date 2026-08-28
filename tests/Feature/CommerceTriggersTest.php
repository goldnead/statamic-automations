<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Events\EntitlementRevoked;
use Goldnead\Invoices\Events\CreditNoteIssued;
use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Integrations\Booking\Triggers as BT;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Triggers as ET;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Integrations\Invoices\Triggers as IT;
use Goldnead\StatamicAutomations\Integrations\Payments\Triggers as PT;
use Goldnead\StatamicAutomations\Listeners\HandleCommerceEvent;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Goldnead\StatamicBooking\Events\BookingMade;
use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Events\SubscriptionRenewed;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Fixtures/CommerceEventStubs.php';

/**
 * The nineteen commerce events, and whether the editor can actually reach them.
 *
 * Four sibling addons fire nineteen events between them. Three of those had a
 * trigger node; the other sixteen fired into nothing, which meant a site that
 * wanted "when a subscription is cancelled, tell me" had to write a listener by
 * hand — the exact work this addon exists to remove.
 *
 * What these tests hold is what decides whether a trigger is usable rather than
 * merely present: the filter actually filters, the flattening survives an event
 * shape it was not expecting, and the whole path from a dispatched event to a
 * started run is connected. A trigger that is registered but never reached is
 * the failure mode that looks like success.
 *
 * Detection is forced here because none of the four addons is a dependency.
 * {@see CommerceIntegrationAbsentTest} is the other half: what a site without
 * them sees.
 */
class CommerceTriggersTest extends TestCase
{
    private const FORCED = ['entitlements', 'booking', 'invoices', 'payments'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The detector probes class_exists() against a configurable list, so
        // pointing it at this test class is enough to make
        // registerOptionalIntegrations() run for all four.
        IntegrationDetector::flush();

        foreach (self::FORCED as $integration) {
            $app['config']->set("automations.integrations.{$integration}.detect", [self::class]);
        }
    }

    protected function tearDown(): void
    {
        // The detector caches statically; don't leak "installed" into the rest
        // of the suite.
        IntegrationDetector::flush();

        parent::tearDown();
    }

    // --- the maps -----------------------------------------------------------

    public function test_every_commerce_event_maps_to_a_trigger_handle(): void
    {
        // A typo in one of these is silent: the event fires, nothing matches,
        // and nobody finds out until an automation "just never runs".
        $this->assertSame([
            'entitlements.granted',
            'entitlements.revoked',
            'entitlements.expired',
            'entitlements.renewed',
            'entitlements.pending',
        ], array_values(HandleCommerceEvent::ENTITLEMENT_TRIGGERS));

        $this->assertSame([
            'booking.made',
            'booking.cancelled',
            'booking.rescheduled',
        ], array_values(HandleCommerceEvent::BOOKING_TRIGGERS));

        $this->assertSame([
            'invoices.issued',
            'invoices.credit_note_issued',
        ], array_values(HandleCommerceEvent::INVOICE_TRIGGERS));
    }

    public function test_the_event_class_strings_use_the_real_psr4_namespaces(): void
    {
        // Two of these packages are named `statamic-*` but root their PSR-4 at
        // `Goldnead\Entitlements` and `Goldnead\Invoices`, with no `Statamic`
        // in the middle. Probing the name that reads more naturally would
        // silently never match, and nothing else in the suite would notice.
        $this->assertArrayHasKey(
            'Goldnead\\Entitlements\\Events\\EntitlementGranted',
            HandleCommerceEvent::ENTITLEMENT_TRIGGERS
        );
        $this->assertArrayHasKey(
            'Goldnead\\Invoices\\Events\\InvoiceIssued',
            HandleCommerceEvent::INVOICE_TRIGGERS
        );
        $this->assertArrayHasKey(
            'Goldnead\\StatamicBooking\\Events\\BookingMade',
            HandleCommerceEvent::BOOKING_TRIGGERS
        );
    }

    // --- registration -------------------------------------------------------

    public function test_all_sixteen_new_triggers_are_registered_when_the_siblings_are_detected(): void
    {
        $nodes = app(NodeRegistry::class);

        foreach ([
            'payments.refunded',
            'payments.subscription_started',
            'payments.subscription_renewed',
            'payments.subscription_cancelled',
            'payments.subscription_ended',
            'payments.subscription_start_failed',
            'entitlements.granted',
            'entitlements.revoked',
            'entitlements.expired',
            'entitlements.renewed',
            'entitlements.pending',
            'booking.made',
            'booking.cancelled',
            'booking.rescheduled',
            'invoices.issued',
            'invoices.credit_note_issued',
        ] as $handle) {
            $this->assertTrue($nodes->has($handle), "The trigger {$handle} is not in the node library.");
        }
    }

    public function test_the_four_new_actions_are_registered(): void
    {
        $nodes = app(NodeRegistry::class);

        foreach ([
            'entitlements.grant',
            'entitlements.revoke',
            'invoices.issue',
            'invoices.issue_credit_note',
        ] as $handle) {
            $this->assertTrue($nodes->has($handle), "The action {$handle} is not in the node library.");
        }
    }

    public function test_no_booking_action_is_registered(): void
    {
        // Deliberate, not forgotten. The booking addon exposes no public way to
        // create, move or cancel a booking; writing to its model directly would
        // skip its idempotency key and fire none of its events. If it ever
        // grows one, this test is the reminder to revisit.
        $nodes = app(NodeRegistry::class);

        $this->assertFalse($nodes->has('booking.cancel'));
        $this->assertFalse($nodes->has('booking.reschedule'));
    }

    public function test_every_new_trigger_describes_its_output(): void
    {
        // The output schema is what the data picker reads. Without it a trigger
        // is a black box: it fires, and nothing downstream knows what it may
        // reference.
        foreach ([
            PT\PaymentRefundedTrigger::class => ['payment', 'refund'],
            PT\SubscriptionStartedTrigger::class => ['subscription', 'payment'],
            PT\SubscriptionRenewedTrigger::class => ['subscription', 'payment'],
            PT\SubscriptionCancelledTrigger::class => ['subscription'],
            PT\SubscriptionEndedTrigger::class => ['subscription'],
            PT\SubscriptionStartFailedTrigger::class => ['payment', 'reason'],
            ET\EntitlementGrantedTrigger::class => ['entitlement', 'previous_state', 'actor'],
            ET\EntitlementRevokedTrigger::class => ['entitlement', 'reason', 'previous_state', 'actor'],
            ET\EntitlementExpiredTrigger::class => ['entitlement', 'granted_access_until'],
            ET\EntitlementRenewedTrigger::class => ['entitlement', 'previous_expires_at', 'actor'],
            ET\EntitlementPendingTrigger::class => ['entitlement', 'previous_state', 'actor'],
            BT\BookingMadeTrigger::class => ['booking'],
            BT\BookingCancelledTrigger::class => ['booking'],
            BT\BookingRescheduledTrigger::class => ['booking'],
            IT\InvoiceIssuedTrigger::class => ['invoice'],
            IT\CreditNoteIssuedTrigger::class => ['credit_note', 'reverses'],
        ] as $class => $keys) {
            $schema = $class::outputSchema();

            $this->assertSame($keys, array_keys($schema), $class.' describes the wrong output.');

            // And the schema has to match what buildContext() actually puts in
            // the context. A promise the trigger does not keep is worse than no
            // promise, because the editor offers a token that resolves to
            // nothing at run time.
            $context = (new $class)->buildContext($this->sampleEvent(), []);

            $this->assertSame(
                $keys,
                array_keys($context->all()),
                $class.' builds a context that does not match its output schema.'
            );
        }
    }

    public function test_each_flattening_fragment_matches_its_schema_fragment(): void
    {
        // The nested blocks are the real drift surface, and the traits say so
        // themselves: a promise kept in two places drifts. The test above checks
        // the top level; this one holds each `*Of()` against the `*OutputSchema()`
        // that describes it, so adding a column to one and not the other fails
        // here rather than in somebody's data picker.
        $event = $this->sampleEvent();

        $pairs = [
            [new PT\PaymentPaidTrigger, 'paymentOf', 'paymentOutputSchema'],
            [new PT\SubscriptionEndedTrigger, 'subscriptionOf', 'subscriptionOutputSchema'],
            [new ET\EntitlementGrantedTrigger, 'entitlementOf', 'entitlementOutputSchema'],
            [new ET\EntitlementGrantedTrigger, 'actorOf', 'actorOutputSchema'],
            [new BT\BookingMadeTrigger, 'bookingOf', 'bookingOutputSchema'],
            [new IT\InvoiceIssuedTrigger, 'invoiceOf', 'invoiceOutputSchema'],
        ];

        foreach ($pairs as [$trigger, $flatten, $schema]) {
            $flattenMethod = new \ReflectionMethod($trigger, $flatten);
            $schemaMethod = new \ReflectionMethod($trigger, $schema);

            // `invoiceOf()` takes the key it reads; the others default it.
            $values = $flatten === 'invoiceOf'
                ? $flattenMethod->invoke($trigger, $event, 'invoice')
                : $flattenMethod->invoke($trigger, $event);

            $this->assertSame(
                array_keys($schemaMethod->invoke(null)),
                array_keys($values),
                "{$flatten}() and {$schema}() have drifted apart.",
            );
        }
    }

    // --- filters ------------------------------------------------------------

    public function test_a_subscription_trigger_filters_by_product(): void
    {
        $trigger = new PT\SubscriptionCancelledTrigger;
        $event = ['subscription' => ['product' => 'kurs', 'status' => 'cancelled']];

        $this->assertTrue($trigger->matches($event, []));
        $this->assertTrue($trigger->matches($event, ['product' => 'kurs']));
        $this->assertFalse($trigger->matches($event, ['product' => 'noten']));
    }

    public function test_a_renewal_matches_on_either_side_of_the_event(): void
    {
        // SubscriptionRenewed carries both models. The subscription names the
        // product; the payment for that cycle may name it differently or not at
        // all. One filter field has to work for both, or the field is a trap.
        $trigger = new PT\SubscriptionRenewedTrigger;

        $this->assertTrue($trigger->matches(
            ['subscription' => ['product' => 'kurs'], 'payment' => ['product' => null]],
            ['product' => 'kurs']
        ));

        $this->assertTrue($trigger->matches(
            ['subscription' => ['product' => null], 'payment' => ['product' => 'kurs']],
            ['product' => 'kurs']
        ));

        $this->assertFalse($trigger->matches(
            ['subscription' => ['product' => 'noten'], 'payment' => ['product' => 'noten']],
            ['product' => 'kurs']
        ));
    }

    public function test_a_refund_can_be_narrowed_to_full_refunds(): void
    {
        // The one filter that guards money. A partial refund is still a paid
        // order, so anything that withdraws access must not run on one.
        $trigger = new PT\PaymentRefundedTrigger;
        $partial = ['payment' => ['product' => 'kurs'], 'amountCent' => 500, 'isFull' => false];
        $full = ['payment' => ['product' => 'kurs'], 'amountCent' => 9900, 'isFull' => true];

        $this->assertTrue($trigger->matches($partial, []));
        $this->assertFalse($trigger->matches($partial, ['only_full' => true]));
        $this->assertTrue($trigger->matches($full, ['only_full' => true]));

        // And the verdict reaches the context under a name a template can print.
        $context = $trigger->buildContext($partial, [])->all();

        $this->assertSame(500, $context['refund']['amount_cent']);
        $this->assertFalse($context['refund']['is_full']);
    }

    public function test_an_entitlement_trigger_filters_by_product_and_source(): void
    {
        // Same product, different source, very different mail: a course won by
        // opt-in and one that was bought are both legitimate grants.
        $trigger = new ET\EntitlementGrantedTrigger;
        $event = ['entitlement' => ['product_slug' => 'kurs', 'source' => 'mollie']];

        $this->assertTrue($trigger->matches($event, []));
        $this->assertTrue($trigger->matches($event, ['product_slug' => 'kurs', 'source' => 'mollie']));
        $this->assertFalse($trigger->matches($event, ['source' => 'newsletter_optin']));
        $this->assertFalse($trigger->matches($event, ['product_slug' => 'noten']));
    }

    public function test_a_booking_trigger_filters_by_endpoint(): void
    {
        // A site runs several endpoints at once. Without this filter the
        // paid-lesson mail goes to somebody who booked a free call.
        $trigger = new BT\BookingMadeTrigger;
        $event = ['booking' => ['endpoint' => 'lesson', 'email' => 'wer@example.com']];

        $this->assertTrue($trigger->matches($event, []));
        $this->assertTrue($trigger->matches($event, ['endpoint' => 'lesson']));
        $this->assertFalse($trigger->matches($event, ['endpoint' => 'consultation']));
    }

    // --- shapes it was not expecting ----------------------------------------

    public function test_every_new_trigger_survives_an_event_that_carries_nothing_it_expected(): void
    {
        // These classes are autoloaded on sites where none of the four addons
        // exists, and an event from somewhere else must produce an empty
        // context rather than an error inside a queue worker nobody is
        // watching.
        foreach ($this->allNewTriggers() as $class) {
            $trigger = new $class;
            $event = new \stdClass;

            $this->assertTrue($trigger->matches($event, []), $class.' rejected an unfiltered event.');

            $context = $trigger->buildContext($event, [])->all();

            // Every leaf, not every top-level key: a nested block that comes
            // back as [] proves nothing if the block one level down is full of
            // made-up defaults.
            foreach ($this->leaves($context) as $path => $value) {
                $this->assertTrue(
                    $value === [] || $value === null || $value === false,
                    $class." invented a value for {$path} out of an empty event."
                );
            }
        }
    }

    /**
     * Flatten a context to dotted paths, so a nested value can be named in a
     * failure message.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function leaves(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== []) {
                $flat += $this->leaves($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    public function test_flattening_turns_real_objects_into_printable_values(): void
    {
        // The reason these triggers have classes at all. Every one of the
        // sixteen events carries an Eloquent model, and two also carry a backed
        // enum or a value object — none of which a template can print or a
        // condition node compare.
        $event = (object) [
            'entitlement' => (object) [
                'id' => 7,
                'product_slug' => 'kurs',
                'source' => 'mollie',
                'expires_at' => new \DateTimeImmutable('2027-01-01 12:00:00', new \DateTimeZone('UTC')),
            ],
            'previousState' => SampleState::Pending,
            'actor' => (object) ['type' => 'user', 'id' => '3', 'email' => 'a@example.com', 'name' => 'A'],
        ];

        $context = (new ET\EntitlementGrantedTrigger)->buildContext($event, [])->all();

        $this->assertSame('kurs', $context['entitlement']['product_slug']);
        $this->assertSame('2027-01-01T12:00:00+00:00', $context['entitlement']['expires_at']);
        $this->assertSame('pending', $context['previous_state']);
        $this->assertSame('a@example.com', $context['actor']['email']);
    }

    public function test_a_credit_note_carries_both_documents_under_snake_case_keys(): void
    {
        $event = new CreditNoteIssued(
            creditNote: (object) ['id' => 2, 'number' => 'RE2026-08-0002', 'kind' => 'credit_note'],
            reverses: (object) ['id' => 1, 'number' => 'RE2026-08-0001', 'kind' => 'invoice'],
        );

        $context = (new IT\CreditNoteIssuedTrigger)->buildContext($event, [])->all();

        $this->assertSame('RE2026-08-0002', $context['credit_note']['number']);
        $this->assertSame('RE2026-08-0001', $context['reverses']['number']);
    }

    // --- the whole path -----------------------------------------------------

    public function test_a_dispatched_event_starts_a_run(): void
    {
        Queue::fake();

        $automation = $this->automationStartingOn('entitlements.granted');

        event(new EntitlementGranted(
            entitlement: (object) ['id' => 1, 'product_slug' => 'kurs', 'source' => 'mollie'],
        ));

        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    public function test_a_dispatched_event_respects_the_trigger_filter(): void
    {
        Queue::fake();

        $automation = $this->automationStartingOn('booking.made', ['endpoint' => 'lesson']);

        event(new BookingMade((object) ['id' => 1, 'endpoint' => 'consultation']));

        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());

        event(new BookingMade((object) ['id' => 2, 'endpoint' => 'lesson']));

        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    public function test_a_payment_event_that_used_to_go_nowhere_now_starts_a_run(): void
    {
        Queue::fake();

        $refund = $this->automationStartingOn('payments.refunded');
        $renewal = $this->automationStartingOn('payments.subscription_renewed');

        event(new PaymentRefunded(
            payment: (object) ['id' => 1, 'product' => 'kurs'],
            amountCent: 9900,
            isFull: true,
        ));
        event(new SubscriptionRenewed(
            subscription: (object) ['id' => 5, 'product' => 'kurs'],
            payment: (object) ['id' => 2, 'product' => 'kurs'],
        ));

        $this->assertSame(1, AutomationRun::where('automation_id', $refund->id)->count());
        $this->assertSame(1, AutomationRun::where('automation_id', $renewal->id)->count());
    }

    public function test_an_event_nobody_listens_for_starts_nothing(): void
    {
        Queue::fake();

        $automation = $this->automationStartingOn('entitlements.granted');

        // The listener is subscribed to five entitlement events. A sixth one it
        // does not know must fall through rather than start every automation.
        (new HandleCommerceEvent(
            app(TriggerRegistry::class),
            app(WorkflowRunner::class),
        ))->handle(new \stdClass);

        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());
    }

    public function test_a_revocation_reaches_the_context_with_its_reason(): void
    {
        Queue::fake();

        $this->automationStartingOn('entitlements.revoked');

        event(new EntitlementRevoked(
            entitlement: (object) ['id' => 1, 'product_slug' => 'kurs'],
            reason: 'Chargeback',
        ));

        $run = AutomationRun::query()->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame('Chargeback', data_get($run->context, 'reason'));
    }

    public function test_a_registered_action_that_cannot_do_its_work_fails_the_run_rather_than_escaping_it(): void
    {
        // The property that decides whether an action is safe to ship: the
        // node goes red, the run ends failed, and nothing is thrown out of the
        // runner. A queued run has nobody to throw to, so an exception here
        // would end up in a worker log and nowhere a person looks.
        //
        // The action is registered on this site — the detector says the addon
        // is there — but the service it calls is not. That is the shape of a
        // real breakage: a package upgrade that moves a class, a container
        // binding that did not happen.
        $this->app['config']->set('automations.integrations.entitlements.manager', 'No\\Such\\Manager');

        $automation = Automation::create([
            'name' => 'Grant on save',
            'handle' => 'grant-on-save-'.bin2hex(random_bytes(4)),
            'enabled' => true,
        ]);

        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't',
            'type' => 'entry_saved',
            'config' => ['_dispatch_mode' => 'sync'],
        ]);

        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'grant',
            'type' => 'entitlements.grant',
            'config' => [
                'subject_type' => 'user',
                'subject_id' => '1',
                'product_slug' => 'kurs',
                'source' => 'automation',
            ],
        ]);

        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => 't',
            'to_node_key' => 'grant',
        ]);

        app(TriggerDispatcher::class)
            ->dispatch('entry_saved', ['entry' => ['id' => '42', 'collection' => 'blog']]);

        $run = AutomationRun::where('automation_id', $automation->id)->latest('id')->first();

        $this->assertNotNull($run, 'The run never started.');
        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->error_message, 'The run failed without saying why.');
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @return array<int, class-string>
     */
    private function allNewTriggers(): array
    {
        return [
            PT\PaymentRefundedTrigger::class,
            PT\SubscriptionStartedTrigger::class,
            PT\SubscriptionRenewedTrigger::class,
            PT\SubscriptionCancelledTrigger::class,
            PT\SubscriptionEndedTrigger::class,
            PT\SubscriptionStartFailedTrigger::class,
            ET\EntitlementGrantedTrigger::class,
            ET\EntitlementRevokedTrigger::class,
            ET\EntitlementExpiredTrigger::class,
            ET\EntitlementRenewedTrigger::class,
            ET\EntitlementPendingTrigger::class,
            BT\BookingMadeTrigger::class,
            BT\BookingCancelledTrigger::class,
            BT\BookingRescheduledTrigger::class,
            IT\InvoiceIssuedTrigger::class,
            IT\CreditNoteIssuedTrigger::class,
        ];
    }

    /**
     * One event object carrying every property any of the sixteen reads, so a
     * single fixture can exercise all of them.
     */
    private function sampleEvent(): object
    {
        return (object) [
            'payment' => (object) ['id' => 1, 'product' => 'kurs'],
            'subscription' => (object) ['id' => 2, 'product' => 'kurs'],
            'entitlement' => (object) ['id' => 3, 'product_slug' => 'kurs'],
            'booking' => (object) ['id' => 4, 'endpoint' => 'lesson'],
            'invoice' => (object) ['id' => 5, 'number' => 'RE-1'],
            'creditNote' => (object) ['id' => 6, 'number' => 'RE-2'],
            'reverses' => (object) ['id' => 5, 'number' => 'RE-1'],
            'actor' => (object) ['type' => 'user', 'id' => '9', 'email' => 'a@example.com', 'name' => 'A'],
            'reason' => 'because',
            'amountCent' => 100,
            'isFull' => true,
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

/**
 * A backed enum standing in for `EntitlementState`, to prove the flattening
 * unwraps one. The real enum is not available here and a plain string would
 * prove nothing.
 */
enum SampleState: string
{
    case Pending = 'pending';
}
