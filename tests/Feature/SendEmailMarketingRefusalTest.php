<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
use Illuminate\Support\Facades\Log;

/**
 * The transactional node refuses marketing mail — and refuses nothing else.
 *
 * Both halves matter. A refusal that also stopped the unsubscribe alert to the
 * team would be reverted within a week, and the two shipped templates that do
 * exactly that (`marketing_unsubscribe_alert`, `marketing_campaign_sent_
 * notification`) run on the very trigger the check looks at.
 */
beforeEach(function () {
    config(['mail.default' => 'array']);
});

/** The node as it behaves on an install that HAS goldnead/statamic-marketing. */
function nodeWithMarketing(): SendEmailAction
{
    return new class extends SendEmailAction
    {
        protected static function marketingSendNodeInstalled(): bool
        {
            return true;
        }
    };
}

/** The context a `marketing.subscribed` trigger builds. */
function subscriberContext(string $email = 'leser@example.test', bool $testMode = false): AutomationContext
{
    return AutomationContext::make([
        'subscriber' => [
            'email' => $email,
            'first_name' => 'Lea',
            'list' => 'newsletter',
            'status' => 'subscribed',
        ],
    ], testMode: $testMode);
}

it('refuses a mail to the subscriber the marketing run is about', function () {
    $result = nodeWithMarketing()->execute(
        subscriberContext(),
        ['to' => 'leser@example.test', 'subject' => 'Welcome aboard!', 'body' => 'Hi Lea'],
    );

    expect($result->isSuccess())->toBeFalse();
    expect($result->error)->toContain('marketing.send_email');
    expect($result->error)->toContain('newsletter');
});

it('refuses on a test run too, so the mistake surfaces on Test rather than three days later', function () {
    $result = nodeWithMarketing()->execute(
        subscriberContext(testMode: true),
        ['to' => 'leser@example.test', 'subject' => 'Welcome aboard!', 'body' => 'Hi Lea'],
    );

    expect($result->isSuccess())->toBeFalse();
});

it('ignores case and whitespace around the address', function () {
    $result = nodeWithMarketing()->execute(
        subscriberContext('Leser@Example.test'),
        ['to' => ' leser@example.TEST ', 'subject' => 'Welcome', 'body' => 'Hi'],
    );

    expect($result->isSuccess())->toBeFalse();
});

it('lets a notice to the team through on the same trigger', function () {
    $result = nodeWithMarketing()->execute(
        subscriberContext(testMode: true),
        ['to' => 'admin@example.com', 'subject' => 'Unsubscribe: leser@example.test', 'body' => 'FYI'],
    );

    expect($result->isSuccess())->toBeTrue();
});

it('leaves runs that are not about a marketing subscription alone', function () {
    $context = AutomationContext::make([
        'form' => ['email' => 'leser@example.test'],
    ], testMode: true);

    $result = nodeWithMarketing()->execute(
        $context,
        ['to' => 'leser@example.test', 'subject' => 'Your download', 'body' => 'Here you go'],
    );

    expect($result->isSuccess())->toBeTrue();
});

it('leaves a subscriber payload without a list alone', function () {
    $context = AutomationContext::make([
        'subscriber' => ['email' => 'leser@example.test'],
    ], testMode: true);

    $result = nodeWithMarketing()->execute(
        $context,
        ['to' => 'leser@example.test', 'subject' => 'Hi', 'body' => 'Hi'],
    );

    expect($result->isSuccess())->toBeTrue();
});

it('warns instead of refusing when there is no marketing node to hand off to', function () {
    Log::spy();

    $result = (new SendEmailAction)->execute(
        subscriberContext(testMode: true),
        ['to' => 'leser@example.test', 'subject' => 'Welcome aboard!', 'body' => 'Hi Lea'],
    );

    expect($result->isSuccess())->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'marketing mail'))
        ->once();
});

it('can be switched off site-wide', function () {
    config(['automations.send_email.refuse_marketing_recipients' => false]);

    $result = nodeWithMarketing()->execute(
        subscriberContext(testMode: true),
        ['to' => 'leser@example.test', 'subject' => 'Welcome aboard!', 'body' => 'Hi Lea'],
    );

    expect($result->isSuccess())->toBeTrue();
});
