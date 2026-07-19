<?php

/**
 * Coverage for the `send_email` node's email affordance endpoints:
 *   - GET /cp/automations/api/email-templates          (picker list)
 *   - GET /cp/automations/api/email-templates/preview  (rendered preview)
 *
 * Both couple to the OPTIONAL email-templates addon through the same public
 * facade the action uses. The addon is not vendored here, so we require the
 * test stub (which declares the facade + DTO) exactly like the action test.
 */

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;

// Stand-in for the OPTIONAL email-templates addon (not vendored in this repo).
require_once __DIR__.'/../Fixtures/EmailTemplatesStub.php';

beforeEach(function () {
    EmailTemplates::reset();
    $this->actingAsSuperUser();

    // The list endpoint reads managed `et_templates` entries...
    CollectionFacade::make('et_templates')->title('Email Templates')->save();

    Entry::make()
        ->collection('et_templates')
        ->locale('default')
        ->slug('welcome')
        ->data([
            'title' => 'Willkommen',
            'subject' => 'Hallo {{ subscriber.first_name }}',
            'preview' => 'Schön, dass du da bist',
        ])
        ->save();

    // ...while the preview endpoint resolves the branded HTML through the facade.
    EmailTemplates::$entries = ['welcome' => [
        'title' => 'Willkommen',
        'subject' => 'Hallo {{ subscriber.first_name }}',
        'body' => '<h1>Hallo {{ subscriber.first_name }} {{ subscriber.last_name }}</h1><p>{{ subscriber.email }}</p>',
    ]];
});

it('lists the managed email templates', function () {
    $data = $this->getJson('/cp/automations/api/email-templates')
        ->assertOk()
        ->json('data');

    expect($data)->toBeArray()->not->toBeEmpty();

    $welcome = collect($data)->firstWhere('slug', 'welcome');
    expect($welcome)->not->toBeNull();
    expect($welcome['title'])->toBe('Willkommen');
    expect($welcome['preview'])->toBe('Schön, dass du da bist');
    expect($welcome)->toHaveKeys(['slug', 'title', 'subject', 'preview']);
});

it('renders a template preview with sample merge tokens resolved', function () {
    $data = $this->getJson('/cp/automations/api/email-templates/preview?slug=welcome')
        ->assertOk()
        ->json('data');

    // Sample data substituted, no literal tokens left behind.
    expect($data['html'])->toContain('Max');
    expect($data['html'])->toContain('Mustermann');
    expect($data['html'])->toContain('max@example.com');
    expect($data['html'])->not->toContain('{{');

    // Subject tokens resolved too.
    expect($data['subject'])->toBe('Hallo Max');
    expect($data['title'])->toBe('Willkommen');
    expect($data['preview'])->toBe('Schön, dass du da bist');
});

it('404s for an unknown template slug', function () {
    $this->getJson('/cp/automations/api/email-templates/preview?slug=does-not-exist')
        ->assertNotFound();
});

it('404s when no slug is provided', function () {
    $this->getJson('/cp/automations/api/email-templates/preview')
        ->assertNotFound();
});
