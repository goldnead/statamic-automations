<?php

use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
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
