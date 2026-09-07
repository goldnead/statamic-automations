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

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Illuminate\Support\Facades\DB;
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

/**
 * F18: die Mail eines gespeicherten Schritts, wie die Mails-Ansicht und der
 * E-Mail-Knoten sie zeigen.
 */
describe('mail of a node', function () {
    beforeEach(function () {
        $this->automation = Automation::create(['name' => 'Willkommen', 'handle' => 'willkommen']);
    });

    it('renders the inline body of a step with sample data', function () {
        AutomationNode::create([
            'automation_id' => $this->automation->id,
            'node_key' => 'mail',
            'type' => 'send_email',
            'config' => [
                'to' => '{{ contact.email }}',
                'subject' => 'Hallo {{ contact.first_name }}',
                'body' => 'Hallo {{ contact.first_name }}, schön dass du da bist.',
            ],
        ]);

        $data = $this->getJson("/cp/automations/api/automations/{$this->automation->id}/mails/mail/preview")
            ->assertOk()
            ->json('data');

        expect($data['source'])->toBe('inline');
        expect($data['subject'])->toBe('Hallo Max');
        expect($data['html'])->toContain('Max');
        // Nichts verschickt, also kein zweiter Reiter.
        expect($data['snapshot'])->toBeNull();
    });

    /**
     * Der Unterschied zwischen "hier fehlt ein Wert" und "hier steht ein
     * Platzhalter, für den es kein Beispiel gibt". Der Motor macht aus beidem
     * Leere; in einer Vorschau liest sich das als kaputte Mail.
     */
    it('leaves a placeholder standing when there is no sample for it', function () {
        AutomationNode::create([
            'automation_id' => $this->automation->id,
            'node_key' => 'mail',
            'type' => 'send_email',
            'config' => [
                'to' => 'a@b.test',
                'subject' => 'Zahlung',
                'body' => 'Hallo {{ contact.first_name }}, {{ payment.amount_cent }} Cent sind da.',
            ],
        ]);

        $html = $this->getJson("/cp/automations/api/automations/{$this->automation->id}/mails/mail/preview")
            ->assertOk()
            ->json('data.html');

        expect($html)->toContain('Max');
        expect($html)->toContain('{{ payment.amount_cent }}');
    });

    it('names the step it could not find', function () {
        $message = $this->getJson("/cp/automations/api/automations/{$this->automation->id}/mails/gibt-es-nicht/preview")
            ->assertNotFound()
            ->json('message');

        expect($message)->toContain('gibt-es-nicht');
    });
});

/**
 * F17: picker list and preview used to disagree about brands. The list was an
 * unscoped collection query, the preview goes through the brand-scoped
 * resolver — so on a multi-brand install every foreign template was offered and
 * answered with a red "Vorschau nicht verfügbar" and no reason.
 *
 * These two cross the boundary the bug lived on: the list must not offer what
 * the resolver refuses, and the refusal must say why.
 */
describe('brand scope', function () {
    beforeEach(function () {
        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();

        foreach (['brand-a' => 'Brand A', 'brand-b' => 'Brand B'] as $handle => $name) {
            DB::table('brands')->insert([
                'handle' => $handle,
                'name' => $name,
                'is_default' => $handle === 'brand-a',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Entry::make()
            ->collection('et_templates')
            ->locale('default')
            ->slug('nur-fuer-b')
            ->data([
                'title' => 'Nur für B',
                'subject' => 'Hallo',
                'brand' => 'brand-b',
            ])
            ->save();

        EmailTemplates::$entries['nur-fuer-b'] = [
            'title' => 'Nur für B',
            'subject' => 'Hallo',
            'body' => '<p>Hallo</p>',
            'brand' => 'brand-b',
        ];
    });

    it('does not offer another brand\'s template in the picker', function () {
        BrandContext::setCurrent('brand-a');

        $slugs = collect($this->getJson('/cp/automations/api/email-templates')
            ->assertOk()
            ->json('data'))
            ->pluck('slug');

        expect($slugs)->not->toContain('nur-fuer-b');

        // …and brand B does see it, so this is a scope and not a blanket hide.
        BrandContext::setCurrent('brand-b');

        $slugsB = collect($this->getJson('/cp/automations/api/email-templates')
            ->assertOk()
            ->json('data'))
            ->pluck('slug');

        expect($slugsB)->toContain('nur-fuer-b');
    });

    it('names the foreign brand instead of a bare "not available"', function () {
        BrandContext::setCurrent('brand-a');

        $message = $this->getJson('/cp/automations/api/email-templates/preview?slug=nur-fuer-b')
            ->assertNotFound()
            ->json('message');

        expect($message)->toContain('nur-fuer-b')
            ->and($message)->toContain('brand-b')
            ->and($message)->toContain('brand-a');
    });
});
