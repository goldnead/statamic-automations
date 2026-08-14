<?php

use Goldnead\StatamicAutomations\Engine\RunLogger;
use Goldnead\StatamicAutomations\Integrations\LeadHub\TimelineRecorder;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * A mail an automation sent, on the recipient's contact record.
 *
 * The contact screen answers "what has this person had from us". Campaigns
 * report themselves there from marketing's side; without this the mails an
 * automation sends — often the first ones a person ever receives — were the one
 * part of that answer missing.
 *
 * Hung off `RunLogger::recordNodeRun()` rather than inside the action, because
 * that is the one place that knows both the run and its outcome, and every path
 * that executes a node comes through it.
 */
beforeEach(function (): void {
    $this->recorder = app(TimelineRecorder::class);

    $this->automation = fn () => Automation::create([
        'name' => 'Willkommensstrecke',
        'handle' => 'willkommen',
        'enabled' => true,
    ]);

    $this->run = function (): AutomationRun {
        $automation = ($this->automation)();

        return AutomationRun::create([
            'automation_id' => $automation->id,
            'automation_uuid' => $automation->uuid,
            'trigger_type' => 'manual',
            'status' => AutomationRun::STATUS_RUNNING,
        ]);
    };

    $this->result = fn (string $to = 'maria@example.com') => ActionResult::success([
        'sent_to' => $to,
        'subject' => 'Schön, dass du da bist',
        'template' => 'welcome',
        'format' => 'html',
    ]);

    // A stand-in CRM, bound where the adapter looks for one. LeadHub is a
    // `suggest` and not a dependency of this addon, so there is no facade to
    // swap — and that is the point: if a class name from the sibling ever crept
    // into the recorder, these tests would still pass while every install
    // without LeadHub broke. The stand-in is what proves it stays optional.
    $this->crm = function (bool $contactExists = true) {
        $fake = new class($contactExists)
        {
            public array $ingested = [];

            public function __construct(private bool $contactExists) {}

            public function findByEmail(string $email): ?array
            {
                return $this->contactExists ? ['email' => $email] : null;
            }

            public function ingest(array $event): mixed
            {
                $this->ingested[] = $event;

                return null;
            }
        };

        config()->set('automations.integrations.leadhub.facade', [$fake]);

        return $fake;
    };
});

it('writes the mail onto the contact timeline', function (): void {
    $crm = ($this->crm)();

    app(RunLogger::class)->recordNodeRun(
        ($this->run)(), 'mail1', 'send_email', [], ($this->result)(),
    );

    expect($crm->ingested)->toHaveCount(1)
        ->and($crm->ingested[0]['type'])->toBe(TimelineRecorder::TYPE_SENT)
        ->and($crm->ingested[0]['summary'])->toContain('Schön, dass du da bist');
});

it('writes nothing for an address with no contact', function (): void {
    // An automation may legitimately mail somebody who is not in the CRM.
    // Filing them here would be the automation quietly creating records.
    $crm = ($this->crm)(contactExists: false);

    app(RunLogger::class)->recordNodeRun(
        ($this->run)(), 'mail1', 'send_email', [], ($this->result)(),
    );

    expect($crm->ingested)->toBe([]);
});

it('writes nothing for a step that failed', function (): void {
    $crm = ($this->crm)();

    app(RunLogger::class)->recordNodeRun(
        ($this->run)(), 'mail1', 'send_email', [], ActionResult::failed('no sender identity'),
    );

    expect($crm->ingested)->toBe([]);
});

it('writes nothing for a step that is not a mail', function (): void {
    $crm = ($this->crm)();

    app(RunLogger::class)->recordNodeRun(
        ($this->run)(), 'wait1', 'delay', [], ActionResult::success(['waited' => true]),
    );

    expect($crm->ingested)->toBe([]);
});

it('leaves the marketing send node to report itself', function (): void {
    // `marketing.send_email` goes out through marketing's tracked send path and
    // writes its own timeline entry. Reporting it here as well would put every
    // such mail on the record twice.
    $crm = ($this->crm)();

    app(RunLogger::class)->recordNodeRun(
        ($this->run)(), 'mail1', 'marketing.send_email', [], ($this->result)(),
    );

    expect($crm->ingested)->toBe([]);
});

it('says in the entry that there is no tracking on these mails', function (): void {
    // The honest half. An automation's mail carries no pixel and no rewritten
    // links, so there is no open and no click to report — and a timeline that
    // stayed quiet about that would read as "never opened".
    $crm = ($this->crm)();

    app(RunLogger::class)->recordNodeRun(
        ($this->run)(), 'mail1', 'send_email', [], ($this->result)(),
    );

    $detail = collect($crm->ingested[0]['payload']['detail'] ?? [])->pluck('value')->implode(' ');

    // Compared against the string itself rather than a word, so the assertion
    // does not depend on the language the test bed runs in.
    expect($detail)->toContain('Willkommensstrecke')
        ->and($detail)->toContain((string) __('statamic-automations::timeline.no_tracking'));
});

it('keys the entry to the run and the step', function (): void {
    // A retried job writes the same entry rather than a second one; a sequence
    // that mails the same person three times writes three, because those are
    // three different steps.
    $crm = ($this->crm)();
    $run = ($this->run)();
    $logger = app(RunLogger::class);

    $logger->recordNodeRun($run, 'mail1', 'send_email', [], ($this->result)());
    $logger->recordNodeRun($run, 'mail2', 'send_email', [], ($this->result)());

    expect($crm->ingested[0]['dedupe_key'])->not->toBe($crm->ingested[1]['dedupe_key']);
});

it('never lets the CRM turn a delivered mail into a failed step', function (): void {
    // Thrown from `ingest()`, not from `findByEmail()`. The adapter catches
    // throwables around the lookup itself, so a fake that fails there proves
    // nothing about the recorder's own catch — the test would stay green with
    // that catch deleted. `ingest()` is the one adapter method without its own
    // guard, and therefore the only path that reaches the caller.
    config()->set('automations.integrations.leadhub.facade', [new class
    {
        public function findByEmail(string $email): ?array
        {
            return ['email' => $email];
        }

        public function ingest(array $event): mixed
        {
            throw new RuntimeException('the CRM is mid-upgrade');
        }
    }]);

    expect(fn () => app(RunLogger::class)->recordNodeRun(
        ($this->run)(), 'mail1', 'send_email', [], ($this->result)(),
    ))->not->toThrow(Throwable::class);
});

it('can be switched off', function (): void {
    $crm = ($this->crm)();
    config()->set('automations.timeline.enabled', false);

    app(RunLogger::class)->recordNodeRun(
        ($this->run)(), 'mail1', 'send_email', [], ($this->result)(),
    );

    expect($crm->ingested)->toBe([]);
});

it('writes nothing for a test run', function (): void {
    // Today this happens to be safe because a test run returns a preview
    // without `sent_to`. But `automations.test_mode.send_real_emails` is a
    // shipped option, and with it on the success path returns a real address
    // again — a safeguard that holds only while another class keeps a key out
    // of its output is not a safeguard.
    $crm = ($this->crm)();
    $run = ($this->run)();
    $run->forceFill(['is_test' => true])->save();

    app(RunLogger::class)->recordNodeRun($run, 'mail1', 'send_email', [], ($this->result)());

    expect($crm->ingested)->toBe([]);
});

it('finds the contact when the step addressed a name, not a bare address', function (): void {
    // `to` may be `Lea <lea@example.test>` or a comma-separated list — both are
    // valid on the node. Handed to the CRM unchanged, the lookup finds nothing
    // and the entry silently never appears.
    $crm = ($this->crm)();

    app(RunLogger::class)->recordNodeRun(
        ($this->run)(), 'mail1', 'send_email', [],
        ($this->result)('Maria Beispiel <maria@example.com>'),
    );

    expect($crm->ingested)->toHaveCount(1)
        ->and($crm->ingested[0]['email'])->toBe('maria@example.com');
});
