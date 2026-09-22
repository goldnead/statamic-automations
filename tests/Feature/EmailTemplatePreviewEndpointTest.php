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
use Statamic\Facades\Role;
use Statamic\Facades\User;

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

    /**
     * Die Vorschau rendert mit demselben Resolver wie der Versand — inklusive
     * Filterkette. Der eigene Ersetzer davor kannte keine Filter und ließ
     * `{{ contact.first_name | upper }}` als Ganzes stehen, also zeigte die
     * Vorschau nachweislich etwas anderes als das, was rausging.
     */
    it('applies the same filters the send does', function () {
        AutomationNode::create([
            'automation_id' => $this->automation->id,
            'node_key' => 'mail',
            'type' => 'send_email',
            'config' => [
                'to' => 'a@b.test',
                'subject' => 'Hallo {{ contact.first_name | upper }}',
                'body' => 'Kurz: {{ contact.name | slug }}',
            ],
        ]);

        $data = $this->getJson("/cp/automations/api/automations/{$this->automation->id}/mails/mail/preview")
            ->assertOk()
            ->json('data');

        expect($data['subject'])->toBe('Hallo MAX');
        expect($data['html'])->toContain('max-mustermann');
    });

    /**
     * `| default:` ist der Filter für den fehlenden Wert, und der Versand
     * schickt dafür den Ersatz raus. Die Regel „kein Beispiel, also bleibt der
     * Platzhalter stehen" hätte hier genau den Fall getroffen, für den es den
     * Filter gibt.
     */
    it('lets a default filter answer a placeholder it has no sample for', function () {
        AutomationNode::create([
            'automation_id' => $this->automation->id,
            'node_key' => 'mail',
            'type' => 'send_email',
            'config' => [
                'to' => 'a@b.test',
                'subject' => 'Hallo {{ payment.name | default:Kunde }}',
                'body' => 'Und ohne Ersatz: {{ payment.amount_cent }}.',
            ],
        ]);

        $data = $this->getJson("/cp/automations/api/automations/{$this->automation->id}/mails/mail/preview")
            ->assertOk()
            ->json('data');

        expect($data['subject'])->toBe('Hallo Kunde');
        // Und der andere bleibt stehen, wie gehabt.
        expect($data['html'])->toContain('{{ payment.amount_cent }}');
    });

    /**
     * Der Resolver des Versands fragt bei `secret.*` den SecretStore. Eine
     * Vorschau hat dort nichts verloren, und der Kontext einer Vorschau kennt
     * den Schlüssel nicht — also bleibt der Platzhalter stehen, statt einen
     * Zugangsschlüssel in einen CP-Rahmen zu schreiben.
     */
    it('never resolves a secret into the preview', function () {
        AutomationNode::create([
            'automation_id' => $this->automation->id,
            'node_key' => 'mail',
            'type' => 'send_email',
            'config' => [
                'to' => 'a@b.test',
                'subject' => 'Schlüssel',
                'body' => 'Token: {{ secret.mailgun_key }}',
            ],
        ]);

        $html = $this->getJson("/cp/automations/api/automations/{$this->automation->id}/mails/mail/preview")
            ->assertOk()
            ->json('data.html');

        expect($html)->toContain('{{ secret.mailgun_key }}');
    });
});

/**
 * Die Vorschau im Node-Stack zeigt, was im Formular steht — nicht, was in der
 * Datenbank liegt. Dafür derselbe Endpunkt per POST, mit der ungespeicherten
 * Konfiguration im Rumpf.
 */
describe('mail of an unsaved form', function () {
    beforeEach(function () {
        $this->automation = Automation::create(['name' => 'Willkommen', 'handle' => 'willkommen']);

        AutomationNode::create([
            'automation_id' => $this->automation->id,
            'node_key' => 'mail',
            'type' => 'send_email',
            'config' => [
                'to' => 'a@b.test',
                'subject' => 'Gespeicherter Betreff',
                'body' => 'Gespeicherter Text.',
            ],
        ]);

        $this->url = "/cp/automations/api/automations/{$this->automation->id}/mails/mail/preview";
    });

    it('renders the config from the body instead of the stored node', function () {
        $data = $this->postJson($this->url, ['config' => [
            'subject' => 'Getippter Betreff, {{ contact.first_name }}',
            'body' => 'Getippter Text.',
        ]])->assertOk()->json('data');

        expect($data['subject'])->toBe('Getippter Betreff, Max');
        expect($data['html'])->toContain('Getippter Text.');
        expect($data['html'])->not->toContain('Gespeicherter Text.');
    });

    it('still renders the stored node when no config comes along', function () {
        // Der alte Weg bleibt, und zwar auf demselben Pfad: die Mails-Liste
        // ruft ihn per GET auf, und sie meint die gespeicherte Mail.
        $data = $this->getJson($this->url)->assertOk()->json('data');

        expect($data['html'])->toContain('Gespeicherter Text.');
    });

    it('renders a template picked in the form but not yet saved', function () {
        $data = $this->postJson($this->url, ['config' => [
            'template' => 'welcome',
            'subject' => '',
        ]])->assertOk()->json('data');

        expect($data['source'])->toBe('template');
        expect($data['html'])->toContain('Mustermann');
    });

    it('shows a mail step that exists on the canvas and not yet in the database', function () {
        // Frisch eingefügter Mail-Knoten, noch nie gespeichert. Ohne Rumpf ist
        // das ein 404, mit Rumpf ist es eine Mail.
        $url = "/cp/automations/api/automations/{$this->automation->id}/mails/mail_neu/preview";

        $this->getJson($url)->assertNotFound();

        $data = $this->postJson($url, ['config' => [
            'subject' => 'Ganz neu',
            'body' => 'Hallo {{ contact.first_name }}.',
        ]])->assertOk()->json('data');

        expect($data['subject'])->toBe('Ganz neu');
        expect($data['html'])->toContain('Max');
        expect($data['snapshot'])->toBeNull();
    });

    /**
     * Der Anfangszustand jedes frisch eingefügten Mail-Knotens. Aus dem
     * Formular heraus ist das kein Fehler, sondern der Anfang — und eine
     * entprellte Vorschau, die dafür 404 bekommt, schreibt eine Log-Warnung
     * pro Tastendruck und zeigt einen roten Kasten für den Normalfall.
     */
    it('answers an empty mail step with nothing to show, not with an error', function () {
        $data = $this->postJson($this->url, ['config' => ['template' => '', 'subject' => '', 'body' => '']])
            ->assertOk()
            ->json('data');

        expect($data['source'])->toBe('empty');
        expect($data['html'])->toBe('');
    });

    it('still calls a stored step without template and text a defect', function () {
        // Auf dem gespeicherten Weg ist es einer: den hat jemand so abgelegt,
        // und die Mails-Liste soll sagen, dass daran etwas fehlt.
        AutomationNode::create([
            'automation_id' => $this->automation->id,
            'node_key' => 'leer',
            'type' => 'send_email',
            'config' => ['to' => 'a@b.test'],
        ]);

        $this->getJson("/cp/automations/api/automations/{$this->automation->id}/mails/leer/preview")
            ->assertNotFound();
    });

    it('does not trip over a config value that is not a string', function () {
        $data = $this->postJson($this->url, ['config' => [
            'subject' => ['nicht', 'eine', 'zeichenkette'],
            'body' => 'Text.',
        ]])->assertOk()->json('data');

        expect($data['subject'])->toBe('');
    });

    it('needs the same permission as the stored way', function () {
        // Ein zweiter Weg zu denselben Daten ist ein Leck, sobald er eine
        // Berechtigung weniger verlangt als der erste.
        $role = Role::make('kein-einblick')->title('Kein Einblick')->addPermission(['access cp']);
        Role::save($role);

        $user = User::make()->email('niemand@example.com');
        $user->assignRole('kein-einblick');
        $user->save();

        $this->actingAs($user);

        $this->getJson($this->url)->assertForbidden();
        $this->postJson($this->url, ['config' => ['subject' => 'x', 'body' => 'y']])
            ->assertForbidden();
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
