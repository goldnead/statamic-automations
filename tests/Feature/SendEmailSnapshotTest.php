<?php

/**
 * Was der E-Mail-Knoten in die Schnappschuss-Schicht schreibt — und vor allem,
 * was er dort NICHT hineinschreibt.
 *
 * Die Schicht liegt in `goldnead/statamic-email-templates` und hält eine Zeile
 * je Fassung einer Mail, nie eine je Empfänger. Der ganze Entwurf hängt an
 * einer Bedingung: übergeben wird die Vorlage mit ihren `{{ … }}`, niemals die
 * gerenderte Mail eines Menschen. Dann steht kein personenbezogener Text in
 * der Tabelle, und sie braucht weder Frist noch Löschkonzept.
 *
 * Der Erbauer der Schicht hat ausdrücklich hinterlassen, dass seine Wächter-
 * Heuristik das für automations nicht erzwingen kann: sie erkennt signierte
 * URLs und den Zählpixel, aber kein „Petra" in einem Satz. Die Regel wird hier
 * eingehalten, nicht dort erzwungen — deshalb prüfen diese Tests genau das.
 *
 * Der Weg führt absichtlich durch `NodeExecutor`: der löst die Konfiguration
 * gegen den Lauf auf, bevor die Aktion sie sieht (`$config['body']` trägt dann
 * den Vornamen), und er ist es auch, der dem Knoten seine Kennung mitgibt.
 */

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\EmailTemplates\Snapshots\Snapshots;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\NodeExecutor;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Fixtures/EmailTemplatesStub.php';
require_once __DIR__.'/../Fixtures/EmailTemplateSnapshotsStub.php';

beforeEach(function () {
    EmailTemplates::reset();
    Snapshots::reset();
    config(['mail.default' => 'array']);

    $this->automation = Automation::create(['name' => 'Willkommen', 'handle' => 'willkommen']);
});

/**
 * @param  array<string, mixed>  $config
 */
function mailNode(array $config): AutomationNode
{
    return AutomationNode::create([
        'automation_id' => test()->automation->id,
        'node_key' => 'mail',
        'type' => 'send_email',
        'config' => $config,
    ]);
}

function runNode(AutomationNode $node, array $data): void
{
    $context = AutomationContext::make($data);
    $context->set('_automation', ['uuid' => (string) test()->automation->uuid, 'name' => 'Willkommen']);

    app(NodeExecutor::class)->execute($node, $context);
}

it('records the inline mail with its placeholders and without the recipient', function () {
    $node = mailNode([
        'to' => '{{ contact.email }}',
        'subject' => 'Hallo {{ contact.first_name }}',
        'body' => 'Hallo {{ contact.first_name }}, schön dass du da bist.',
    ]);

    runNode($node, ['contact' => ['first_name' => 'Petra', 'email' => 'petra@example.test']]);

    // Erst der Gegenbeweis: die Mail, die rausging, war personalisiert. Ohne
    // ihn belegt der Rest nichts — eine Fassung ohne Namen wäre auch dann
    // sauber, wenn gar nichts eingesetzt worden wäre.
    $sent = Mail::mailer()->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1);
    expect($sent->first()->getOriginalMessage()->getTextBody())->toContain('Petra');

    expect(Snapshots::$recorded)->toHaveCount(1);
    $call = Snapshots::$recorded[0];

    expect($call['owner_type'])->toBe('automations:node');
    expect($call['owner_id'])->toBe($this->automation->uuid.':'.$node->uuid);

    expect($call['template']['body'])->toContain('{{ contact.first_name }}');
    expect($call['template']['body'])->not->toContain('Petra');
    expect($call['template']['subject'])->toContain('{{ contact.first_name }}');
    expect($call['template']['subject'])->not->toContain('Petra');
});

it('records a managed template as it stands, not as it was rendered', function () {
    EmailTemplates::$entries = ['willkommen' => [
        'subject' => 'Willkommen, {{ contact.first_name }}',
        'body' => '<p>Hallo {{ contact.first_name }}, hier ist deine Übung.</p>',
    ]];

    $node = mailNode([
        'to' => '{{ contact.email }}',
        'subject' => '',
        'body' => '',
        'template' => 'willkommen',
    ]);

    runNode($node, ['contact' => ['first_name' => 'Petra', 'email' => 'petra@example.test']]);

    $sent = Mail::mailer()->getSymfonyTransport()->messages();
    expect($sent->first()->getOriginalMessage()->getHtmlBody())->toContain('Petra');

    expect(Snapshots::$recorded)->toHaveCount(1);
    $template = Snapshots::$recorded[0]['template'];

    expect($template['slug'])->toBe('willkommen');
    expect($template['source'])->toBe('entry');
    expect($template['body'])->toContain('{{ contact.first_name }}');
    expect($template['body'])->not->toContain('Petra');
});

it('records nothing when a mail is not actually sent', function () {
    $node = mailNode([
        'to' => '{{ contact.email }}',
        'subject' => 'Hallo',
        'body' => 'Text',
    ]);

    $context = AutomationContext::make(['contact' => ['email' => 'petra@example.test']], testMode: true);
    $context->set('_automation', ['uuid' => (string) $this->automation->uuid, 'name' => 'Willkommen']);

    app(NodeExecutor::class)->execute($node, $context);

    expect(Snapshots::$recorded)->toBeEmpty();
});

it('records nothing when the node cannot be identified', function () {
    // Ohne `_automation.uuid` gibt es keinen Eigentümer für die Zeile. Lieber
    // kein Eintrag als einer, der zu niemandem gehört.
    $node = mailNode([
        'to' => 'petra@example.test',
        'subject' => 'Hallo',
        'body' => 'Text',
    ]);

    app(NodeExecutor::class)->execute($node, AutomationContext::make([]));

    expect(Snapshots::$recorded)->toBeEmpty();
});
