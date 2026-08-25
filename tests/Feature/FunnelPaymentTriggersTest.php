<?php

use Goldnead\StatamicAutomations\Integrations\Funnels\Triggers\FunnelOfferAcceptedTrigger;
use Goldnead\StatamicAutomations\Integrations\Funnels\Triggers\FunnelStepEnteredTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Triggers\CheckoutAbandonedTrigger;
use Goldnead\StatamicAutomations\Integrations\Payments\Triggers\PaymentPaidTrigger;
use Goldnead\StatamicAutomations\Listeners\HandleFunnelOrPaymentEvent;

/**
 * The triggers that make a funnel and a payment visible to the engine.
 *
 * Both sibling addons already fired these events; what was missing was any way
 * to pick them in the editor, so they went nowhere. The tests here are about the
 * two things that decide whether a trigger is usable: does its filter actually
 * filter, and does it survive an event shape it was not expecting — because
 * these classes have to load on a site where neither sibling is installed.
 */
it('maps every funnel and payment event to a trigger handle', function () {
    // A typo in one of these maps is silent: the event fires, nothing matches,
    // and nobody finds out until an automation "just never runs".
    expect(HandleFunnelOrPaymentEvent::FUNNEL_TRIGGERS)->toHaveCount(4)
        ->and(HandleFunnelOrPaymentEvent::PAYMENT_TRIGGERS)->toHaveCount(3)
        ->and(array_values(HandleFunnelOrPaymentEvent::FUNNEL_TRIGGERS))->toBe([
            'funnels.completed',
            'funnels.form_submitted',
            'funnels.step_entered',
            'funnels.offer_accepted',
        ])
        ->and(array_values(HandleFunnelOrPaymentEvent::PAYMENT_TRIGGERS))->toBe([
            'payments.paid',
            'payments.failed',
            'payments.checkout_abandoned',
        ]);
});

it('runs on every funnel when no funnel is named', function () {
    $trigger = new FunnelStepEnteredTrigger;

    expect($trigger->matches(['visit' => ['funnel' => 'kurs'], 'step' => ['key' => 'entry_1']], []))->toBeTrue();
});

it('runs only on the funnel that was named', function () {
    $trigger = new FunnelStepEnteredTrigger;
    $event = ['visit' => ['funnel' => 'kurs'], 'step' => ['key' => 'entry_1']];

    expect($trigger->matches($event, ['funnel' => 'kurs']))->toBeTrue()
        ->and($trigger->matches($event, ['funnel' => 'anderer']))->toBeFalse();
});

it('can be narrowed to one step, which the busiest trigger needs', function () {
    $trigger = new FunnelStepEnteredTrigger;
    $event = ['visit' => ['funnel' => 'kurs'], 'step' => ['key' => 'angebot']];

    expect($trigger->matches($event, ['step' => 'angebot']))->toBeTrue()
        ->and($trigger->matches($event, ['step' => 'danke']))->toBeFalse()
        // Both filters together, which is the combination somebody actually
        // uses: this step, of this funnel.
        ->and($trigger->matches($event, ['funnel' => 'kurs', 'step' => 'angebot']))->toBeTrue()
        ->and($trigger->matches($event, ['funnel' => 'anderer', 'step' => 'angebot']))->toBeFalse();
});

it('filters a payment by product', function () {
    $trigger = new PaymentPaidTrigger;
    $event = ['payment' => ['product' => 'kurs', 'amount_cent' => 9900]];

    expect($trigger->matches($event, []))->toBeTrue()
        ->and($trigger->matches($event, ['product' => 'kurs']))->toBeTrue()
        ->and($trigger->matches($event, ['product' => 'noten']))->toBeFalse();
});

it('survives an event that carries nothing it expected', function () {
    // These classes are loaded on sites where neither sibling addon exists, and
    // an event from somewhere else must produce an empty context rather than an
    // error inside a queue worker nobody is watching.
    $trigger = new FunnelOfferAcceptedTrigger;
    $event = new stdClass;

    expect($trigger->matches($event, []))->toBeTrue();

    $context = $trigger->buildContext($event, [])->all();

    expect($context['visit'])->toBe([])
        ->and($context['step'])->toBe([])
        ->and($context['payment'])->toBe([]);
});

it('flattens a real object-shaped event into context', function () {
    $visit = (object) ['id' => 7, 'email' => 'k@example.com', 'name' => 'Kim', 'payment_id' => 3];
    $visit->funnel = (object) ['handle' => 'kurs', 'title' => 'Kurs'];

    $event = (object) [
        'visit' => $visit,
        'step' => (object) ['node_key' => 'angebot', 'type' => 'offer', 'label' => 'Angebot'],
        'payment' => (object) ['id' => 3, 'product' => 'offer:cd', 'amount_cent' => 600, 'currency' => 'EUR', 'status' => 'paid'],
    ];

    $context = (new FunnelOfferAcceptedTrigger)->buildContext($event, [])->all();

    expect($context['visit']['funnel'])->toBe('kurs')
        ->and($context['visit']['email'])->toBe('k@example.com')
        ->and($context['step']['key'])->toBe('angebot')
        ->and($context['payment']['amount_cent'])->toBe(600);
});

it('filters an abandoned checkout by product like its siblings do', function () {
    $trigger = new CheckoutAbandonedTrigger;
    $event = ['payment' => ['product' => 'kurs', 'email' => 'wer@example.com']];

    expect($trigger->matches($event, []))->toBeTrue()
        ->and($trigger->matches($event, ['product' => 'kurs']))->toBeTrue()
        ->and($trigger->matches($event, ['product' => 'etwas-anderes']))->toBeFalse();
});

it('survives an abandoned event shape it was not expecting', function () {
    // These classes load on sites where statamic-payments is not installed, and
    // an event without a payment must not take the run down.
    $trigger = new CheckoutAbandonedTrigger;

    expect($trigger->matches([], ['product' => 'kurs']))->toBeFalse()
        ->and($trigger->matches((object) [], []))->toBeTrue()
        ->and($trigger->buildContext([], [])->toArray())->toBe(['payment' => []]);
});

it('offers the abandoned trigger under Payments, testable like the others', function () {
    expect(CheckoutAbandonedTrigger::handle())->toBe('payments.checkout_abandoned')
        ->and(CheckoutAbandonedTrigger::group())->toBe('Payments')
        ->and(CheckoutAbandonedTrigger::supportsTestMode())->toBeTrue()
        ->and(CheckoutAbandonedTrigger::outputSchema()['payment'])->toHaveKey('email');
});
