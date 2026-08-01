<?php

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

// Stand-in for the OPTIONAL email-templates addon (not vendored in this repo).
require_once __DIR__.'/../Fixtures/EmailTemplatesStub.php';

beforeEach(function () {
    EmailTemplates::reset();
    config(['mail.default' => 'array']);
});

it('exposes a template select in the schema when the email-templates addon is installed', function () {
    $schema = collect(SendEmailAction::schema());

    expect($schema->pluck('handle'))->toContain('template');

    $field = $schema->firstWhere('handle', 'template');
    expect($field['type'])->toBe('select');
    expect($field['required'])->toBeFalse();
});

it('hides the template field when the email-templates addon is absent', function () {
    $action = new class extends SendEmailAction
    {
        protected static function emailTemplatesInstalled(): bool
        {
            return false;
        }
    };

    expect(collect($action::schema())->pluck('handle'))->not->toContain('template');
});

it('sends the rendered template html when a managed template is selected', function () {
    EmailTemplates::$entries = ['welcome' => ['body' => '<h1>Willkommen</h1>', 'subject' => 'Hallo']];

    $result = (new SendEmailAction)->execute(
        AutomationContext::make([], testMode: true),
        ['to' => 'a@b.test', 'subject' => 'Sub', 'body' => 'plain fallback', 'template' => 'welcome'],
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['preview']['html'])->toBe('<h1>Willkommen</h1>');
});

it('dispatches the resolved html body to the mailer as HTML', function () {
    EmailTemplates::$entries = ['welcome' => ['body' => '<p>HTML BODY</p>']];

    $result = (new SendEmailAction)->execute(
        AutomationContext::make([]),
        ['to' => 'a@b.test', 'subject' => 'Sub', 'body' => 'plain', 'template' => 'welcome'],
    );

    expect($result->output['format'])->toBe('html');

    $messages = Mail::mailer()->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    expect($messages->first()->getOriginalMessage()->getHtmlBody())->toContain('HTML BODY');
});

it('falls back to the inline body when the selected template cannot be resolved', function () {
    // No matching managed entry → resolver invokes the inline-body fallback.
    $result = (new SendEmailAction)->execute(
        AutomationContext::make([], testMode: true),
        ['to' => 'a@b.test', 'subject' => 'Sub', 'body' => 'INLINE FALLBACK', 'template' => 'missing'],
    );

    expect($result->output['preview']['html'])->toBe('INLINE FALLBACK');
});

it('resolves tokens in the template body against the flow context', function () {
    EmailTemplates::$entries = ['welcome' => [
        'body' => '<p>Hallo {{ subscriber.first_name }}, willkommen!</p>',
        'subject' => 'Hi {{ subscriber.first_name }}',
    ]];

    $context = AutomationContext::make(
        ['subscriber' => ['first_name' => 'Adrian']],
        testMode: true,
    );

    $result = (new SendEmailAction)->execute(
        $context,
        ['to' => 'a@b.test', 'subject' => '(no subject)', 'body' => 'plain fallback', 'template' => 'welcome'],
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['preview']['html'])->toBe('<p>Hallo Adrian, willkommen!</p>');
    expect($result->output['preview']['html'])->not->toContain('{{');
    // Subject came from the template (inline was the placeholder) → resolved too.
    expect($result->output['preview']['subject'])->toBe('Hi Adrian');
});

it('resolves tokens in the rendered html dispatched to the mailer', function () {
    EmailTemplates::$entries = ['welcome' => [
        'body' => '<p>Hallo {{ subscriber.first_name }}</p>',
    ]];

    $result = (new SendEmailAction)->execute(
        AutomationContext::make(['subscriber' => ['first_name' => 'Adrian']]),
        ['to' => 'a@b.test', 'subject' => 'Sub', 'body' => 'plain', 'template' => 'welcome'],
    );

    $html = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
    expect($html)->toContain('Hallo Adrian');
    expect($html)->not->toContain('{{');
});

it('sends at most once per recipient when a dedupe key is set', function () {
    Cache::flush();

    $config = ['to' => 'dedupe@b.test', 'subject' => 'Sub', 'body' => 'PLAIN', 'dedupe' => 'welcome'];

    $first = (new SendEmailAction)->execute(AutomationContext::make([]), $config);
    $second = (new SendEmailAction)->execute(AutomationContext::make([]), $config);

    expect($first->isSuccess())->toBeTrue();
    expect($first->output['skipped'] ?? false)->toBeFalse();

    expect($second->isSuccess())->toBeTrue();
    expect($second->output['skipped'] ?? null)->toBeTrue();
    expect($second->output['reason'] ?? null)->toBe('dedupe');

    // Only the first send reached the mailer.
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('does not dedupe across different recipients', function () {
    Cache::flush();

    (new SendEmailAction)->execute(AutomationContext::make([]), ['to' => 'one@b.test', 'subject' => 'S', 'body' => 'B', 'dedupe' => 'welcome']);
    $other = (new SendEmailAction)->execute(AutomationContext::make([]), ['to' => 'two@b.test', 'subject' => 'S', 'body' => 'B', 'dedupe' => 'welcome']);

    expect($other->output['skipped'] ?? false)->toBeFalse();
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(2);
});

it('remains backward compatible: no template selected sends plain text', function () {
    $result = (new SendEmailAction)->execute(
        AutomationContext::make([]),
        ['to' => 'a@b.test', 'subject' => 'Sub', 'body' => 'PLAIN TEXT'],
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['format'])->toBe('text');

    $email = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    expect($email->getTextBody())->toContain('PLAIN TEXT');
    expect($email->getHtmlBody())->toBeNull();
});
