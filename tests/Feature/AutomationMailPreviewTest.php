<?php

use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * The mail of a step as it would read for a person, in the dry run. The registry hands it over made
 * safe to look at: no script, no remote image, no live link.
 */

function mpAutomation(string $handle = 'post'): Automation
{
    $a = Automation::query()->create(['uuid' => 'uuid-'.$handle, 'name' => ucfirst($handle), 'handle' => $handle, 'enabled' => true]);
    AutomationNode::query()->create(['automation_id' => $a->id, 'node_key' => 't', 'type' => 'manual', 'config' => []]);

    return $a;
}

function mpStep(Automation $a, string $key, string $type, array $config): void
{
    AutomationNode::query()->create(['automation_id' => $a->id, 'node_key' => $key, 'type' => $type, 'config' => $config]);
}

it('writes the mail of a step for the person, with her traits over the sample data', function () {
    $a = mpAutomation();
    mpStep($a, 'm', 'send_email', ['to' => 'x@example.test', 'subject' => 'Hallo {{ contact.first_name }}', 'body' => "Liebe {{ contact.first_name }},\nschön, dass du da bist."]);

    $registry = app(MapObjectRegistry::class);

    $lena = $registry->mailPreview('automation', 'post', ['node' => 'm'], SimPerson::known(['contact.first_name' => 'Lena']));
    $nobody = $registry->mailPreview('automation', 'post', ['node' => 'm'], SimPerson::unknown());

    expect($lena['subject'])->toBe('Hallo Lena')
        ->and($lena['html'])->toContain('Liebe Lena,')
        ->and($lena['html'])->toContain('<br>')
        ->and($nobody['subject'])->toBe('Hallo Max');
});

it('hands over no mail for a step that is none, a step that is gone, or an automation that is gone', function () {
    $a = mpAutomation();
    mpStep($a, 'w', 'delay', ['amount' => 1, 'unit' => 'days']);
    mpStep($a, 'empty', 'send_email', ['to' => 'x@example.test']);

    $registry = app(MapObjectRegistry::class);

    expect($registry->mailPreview('automation', 'post', ['node' => 'w'], SimPerson::unknown()))->toBeNull()
        ->and($registry->mailPreview('automation', 'post', ['node' => 'empty'], SimPerson::unknown()))->toBeNull()
        ->and($registry->mailPreview('automation', 'post', ['node' => 'gone'], SimPerson::unknown()))->toBeNull()
        ->and($registry->mailPreview('automation', 'nope', ['node' => 'm'], SimPerson::unknown()))->toBeNull()
        ->and($registry->mailPreview('automation', 'post', [], SimPerson::unknown()))->toBeNull();
});

it('makes a hostile mail safe before anybody looks at it, and leaves the tracking link a name only', function () {
    $a = mpAutomation();
    mpStep($a, 'm', 'send_email', [
        'to' => 'x@example.test',
        'subject' => 'S',
        'body' => '<p>Hi</p><script>alert(1)</script><img src="https://track.example/o.gif"><a href="https://track.example/c/1?u=https%3A%2F%2Fsite.test">Zum Kurs</a>',
    ]);

    $mail = app(MapObjectRegistry::class)->mailPreview('automation', 'post', ['node' => 'm'], SimPerson::unknown());

    expect($mail['html'])->not->toContain('<script')
        ->and($mail['html'])->not->toMatch('/\ssrc="/')
        ->and($mail['html'])->not->toMatch('/\shref="(?!#")/')
        ->and($mail['links'])->toBe([['text' => 'Zum Kurs', 'url' => 'https://track.example/c/1?u=https%3A%2F%2Fsite.test']])
        ->and($mail['blocked_images'])->toBe(1);
});

it('writes the mail without writing anything', function () {
    $a = mpAutomation();
    mpStep($a, 'm', 'send_email', ['to' => 'x@example.test', 'subject' => 'S', 'body' => 'Text']);

    Mail::fake();
    Queue::fake();
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|pragma)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    app(MapObjectRegistry::class)->mailPreview('automation', 'post', ['node' => 'm'], SimPerson::known(['contact.first_name' => 'Lena']));

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
    expect($writes)->toBe([]);
});

/*
 * The mail of a `marketing.send_email` step. The step's own class (the marketing addon's) renders it,
 * because it owns the template, the campaign and the variables; the orchestrator hands it the config
 * with the run's tokens resolved for the person and the person's four fields, and nothing else. Every
 * real series of adriangoldner.com is made of these steps (template mode: `minikurs-cvt-tag-N`).
 */
class FakeMarketingSendStep
{
    /** @var list<array{config: array, person: array}> */
    public static array $calls = [];

    public static function handle(): string
    {
        return 'marketing.send_email';
    }

    public static function mailPreview(array $config, array $person = []): ?array
    {
        self::$calls[] = ['config' => $config, 'person' => $person];

        return ($config['template'] ?? '') === 'minikurs-cvt-tag-1'
            ? ['subject' => (string) $config['subject'], 'html' => '<p>Hallo '.($person['first_name'] ?? '').'</p><a href="https://example.test/x">Los</a>', 'from' => null]
            : null;
    }
}

class OldMarketingSendStep
{
    public static function handle(): string
    {
        return 'marketing.send_email';
    }
}

function mpMarketingStep(string $class): Automation
{
    FakeMarketingSendStep::$calls = [];
    app(NodeRegistry::class)->register('marketing.send_email', $class, 'action');

    $a = Automation::query()->where('handle', 'serie')->first() ?? mpAutomation('serie');
    AutomationNode::query()->where('automation_id', $a->id)->where('node_key', 'tag1')->delete();
    mpStep($a, 'tag1', 'marketing.send_email', ['template' => 'minikurs-cvt-tag-1', 'subject' => 'Hallo {{ contact.first_name }}', 'list' => 'minikurs']);

    return $a;
}

it('has the marketing send step render its own mail for the person', function () {
    mpMarketingStep(FakeMarketingSendStep::class);

    $mail = app(MapObjectRegistry::class)->mailPreview('automation', 'serie', ['node' => 'tag1'], SimPerson::known(['contact.first_name' => 'Lena']));

    expect($mail['subject'])->toBe('Hallo Lena')
        ->and($mail['html'])->toContain('Hallo Lena')
        ->and($mail['links'])->toBe([['text' => 'Los', 'url' => 'https://example.test/x']])
        ->and(FakeMarketingSendStep::$calls[0]['config']['template'])->toBe('minikurs-cvt-tag-1')
        ->and(FakeMarketingSendStep::$calls[0]['person']['first_name'])->toBe('Lena')
        ->and(FakeMarketingSendStep::$calls[0]['person']['email'])->not->toBe('');
});

it('has no mail for a marketing send step whose addon cannot render it yet, or whose mail is gone', function () {
    mpMarketingStep(OldMarketingSendStep::class);

    expect(app(MapObjectRegistry::class)->mailPreview('automation', 'serie', ['node' => 'tag1'], SimPerson::unknown()))->toBeNull();

    $a = mpMarketingStep(FakeMarketingSendStep::class);
    mpStep($a, 'weg', 'marketing.send_email', ['template' => 'gibt-es-nicht', 'subject' => 'x', 'list' => 'minikurs']);

    expect(app(MapObjectRegistry::class)->mailPreview('automation', 'serie', ['node' => 'weg'], SimPerson::unknown()))->toBeNull();
});
