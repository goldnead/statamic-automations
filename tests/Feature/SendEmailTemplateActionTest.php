<?php

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
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

    $result = (new SendEmailAction())->execute(
        AutomationContext::make([], testMode: true),
        ['to' => 'a@b.test', 'subject' => 'Sub', 'body' => 'plain fallback', 'template' => 'welcome'],
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['preview']['html'])->toBe('<h1>Willkommen</h1>');
});

it('dispatches the resolved html body to the mailer as HTML', function () {
    EmailTemplates::$entries = ['welcome' => ['body' => '<p>HTML BODY</p>']];

    $result = (new SendEmailAction())->execute(
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
    $result = (new SendEmailAction())->execute(
        AutomationContext::make([], testMode: true),
        ['to' => 'a@b.test', 'subject' => 'Sub', 'body' => 'INLINE FALLBACK', 'template' => 'missing'],
    );

    expect($result->output['preview']['html'])->toBe('INLINE FALLBACK');
});

it('remains backward compatible: no template selected sends plain text', function () {
    $result = (new SendEmailAction())->execute(
        AutomationContext::make([]),
        ['to' => 'a@b.test', 'subject' => 'Sub', 'body' => 'PLAIN TEXT'],
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['format'])->toBe('text');

    $email = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    expect($email->getTextBody())->toContain('PLAIN TEXT');
    expect($email->getHtmlBody())->toBeNull();
});
