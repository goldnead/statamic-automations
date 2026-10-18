<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitForAutomationNode;
use Goldnead\StatamicAutomations\Services\SequenceOptOut;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * "Wait for automation": hold this run while the same person is still inside a
 * different automation, and tell the graph how that other one ended.
 *
 * The scenario behind it: two series mail the same person, and a person who is
 * in the welcome series must not also get the second mail on the same day. The
 * node sits at the start of the second series and lets it go once the first one
 * is done with her.
 */
class WaitForAutomationNodeTest extends TestCase
{
    private Automation $target;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-18 12:00:00');

        $this->target = Automation::create(['name' => 'Welcome', 'handle' => 'welcome', 'enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_person_who_never_had_a_run_passes_at_once(): void
    {
        $run = $this->runOwn();

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($run->nodeRuns()->where('node_key', 'after')->exists());
        $this->assertSame('none', $this->nodeOut($run, 'w')['state']);
        $this->assertFalse($this->nodeOut($run, 'w')['ran_to_end']);
    }

    public function test_it_waits_for_a_run_that_starts_a_moment_later(): void
    {
        $run = $this->runOwn(['wait_if_missing_minutes' => 10]);

        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);
        $job = $this->pendingJob($run);
        $this->assertSame('w', $job->node_key);
        // Counted from the start of the OWN run, not from whenever the node is re-run.
        $this->assertSame(Carbon::now()->addMinutes(10)->timestamp, $job->due_at->timestamp);

        // Nobody turns up: after the minutes are over the run goes on, state none.
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->artisan('automations:run-due')->assertExitCode(0);

        $run->refresh();
        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('none', $this->nodeOut($run, 'w')['state']);
    }

    public function test_the_run_that_shows_up_during_the_grace_minutes_is_waited_for(): void
    {
        $run = $this->runOwn(['wait_if_missing_minutes' => 10]);
        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);

        $other = $this->targetRun(AutomationRun::STATUS_RUNNING);

        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->artisan('automations:run-due')->assertExitCode(0);

        $run->refresh();
        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status, 'The late run is now being waited for.');
        $this->assertFalse($run->nodeRuns()->where('node_key', 'after')->exists());

        $other->forceFill(['status' => AutomationRun::STATUS_SUCCESS, 'finished_at' => now()])->save();
        Carbon::setTestNow(Carbon::now()->addHours(7));
        $this->artisan('automations:run-due')->assertExitCode(0);

        $run->refresh();
        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('ran_to_end', $this->nodeOut($run, 'w')['state']);
    }

    public function test_the_grace_minutes_do_not_apply_once_a_run_exists(): void
    {
        $this->targetRun(AutomationRun::STATUS_SUCCESS, finishedAt: Carbon::now()->subDays(2));

        $run = $this->runOwn(['wait_if_missing_minutes' => 10]);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('ran_to_end', $this->nodeOut($run, 'w')['state']);
    }

    #[DataProvider('openStatuses')]
    public function test_it_waits_while_the_other_run_is_open_and_asks_again_after_poll_hours(string $status): void
    {
        $this->targetRun($status);

        $run = $this->runOwn();

        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);
        $this->assertSame(Carbon::now()->addHours(6)->timestamp, $this->pendingJob($run)->due_at->timestamp, 'Default is 6 hours.');
        $this->assertFalse($run->nodeRuns()->where('node_key', 'after')->exists());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function openStatuses(): array
    {
        return [
            'queued' => [AutomationRun::STATUS_QUEUED],
            'running' => [AutomationRun::STATUS_RUNNING],
            'waiting' => [AutomationRun::STATUS_WAITING],
        ];
    }

    public function test_poll_hours_is_configurable(): void
    {
        $this->targetRun(AutomationRun::STATUS_WAITING);

        $run = $this->runOwn(['poll_hours' => 2]);

        $this->assertSame(Carbon::now()->addHours(2)->timestamp, $this->pendingJob($run)->due_at->timestamp);
    }

    public function test_it_goes_on_once_the_other_run_has_ended_and_reports_ran_to_end(): void
    {
        $other = $this->targetRun(AutomationRun::STATUS_WAITING);
        $run = $this->runOwn();
        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);

        $other->forceFill(['status' => AutomationRun::STATUS_SUCCESS, 'finished_at' => now()])->save();
        Carbon::setTestNow(Carbon::now()->addHours(7));
        $this->artisan('automations:run-due')->assertExitCode(0);

        $run->refresh();
        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($run->nodeRuns()->where('node_key', 'after')->exists());
        $this->assertSame(
            ['state' => 'ran_to_end', 'ran_to_end' => true],
            array_intersect_key($this->nodeOut($run, 'w'), ['state' => 1, 'ran_to_end' => 1]),
        );
    }

    #[DataProvider('earlyEnds')]
    public function test_a_run_that_ended_early_is_not_ran_to_end(string $status): void
    {
        $this->targetRun($status, finishedAt: Carbon::now()->subHour());

        $run = $this->runOwn();

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('ended_early', $this->nodeOut($run, 'w')['state']);
        $this->assertFalse($this->nodeOut($run, 'w')['ran_to_end']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function earlyEnds(): array
    {
        return [
            'stopped' => [AutomationRun::STATUS_STOPPED],
            'failed' => [AutomationRun::STATUS_FAILED],
            'cancelled' => [AutomationRun::STATUS_CANCELLED],
        ];
    }

    public function test_the_newest_run_decides(): void
    {
        $this->targetRun(AutomationRun::STATUS_SUCCESS, finishedAt: Carbon::now()->subDays(5), createdAt: Carbon::now()->subDays(6));
        $this->targetRun(AutomationRun::STATUS_FAILED, finishedAt: Carbon::now()->subDay(), createdAt: Carbon::now()->subDays(2));

        $this->assertSame('ended_early', $this->nodeOut($this->runOwn(), 'w')['state']);
    }

    public function test_an_opt_out_counts_as_ended_at_once_even_while_the_run_still_waits(): void
    {
        $this->targetRun(AutomationRun::STATUS_WAITING);
        app(SequenceOptOut::class)->add((string) $this->target->uuid, 'Anna@Example.test');

        $run = $this->runOwn();

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('opted_out', $this->nodeOut($run, 'w')['state']);
        $this->assertFalse($this->nodeOut($run, 'w')['ran_to_end'], 'An opt-out is not a run to the end.');
    }

    public function test_an_opt_out_beats_a_successful_run_and_the_settle_wait(): void
    {
        $this->targetRun(AutomationRun::STATUS_SUCCESS, finishedAt: Carbon::now()->subHour());
        app(SequenceOptOut::class)->add((string) $this->target->uuid, 'anna@example.test');

        $run = $this->runOwn(['settle' => 'next_day']);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('opted_out', $this->nodeOut($run, 'w')['state']);
    }

    public function test_an_opt_out_from_another_series_does_not_count(): void
    {
        $this->targetRun(AutomationRun::STATUS_WAITING);
        $other = Automation::create(['name' => 'Other', 'handle' => 'other', 'enabled' => true]);
        app(SequenceOptOut::class)->add((string) $other->uuid, 'anna@example.test');

        $this->assertSame(AutomationRun::STATUS_WAITING, $this->runOwn()->status);
    }

    public function test_settle_next_day_holds_the_run_until_the_next_calendar_day_in_the_configured_timezone(): void
    {
        // 12:00 UTC is 14:00 in Berlin on the 18th. The other series ended at 10:00 today.
        $this->targetRun(AutomationRun::STATUS_SUCCESS, finishedAt: Carbon::now()->subHours(2));

        $run = $this->runOwn(['settle' => 'next_day']);

        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);
        // Midnight of the 19th in Berlin (CEST, UTC+2) is 22:00 UTC on the 18th.
        $this->assertSame(
            Carbon::parse('2026-10-18 22:00:00', 'UTC')->timestamp,
            $this->pendingJob($run)->due_at->timestamp,
        );
        $this->assertFalse($run->nodeRuns()->where('node_key', 'after')->exists());

        Carbon::setTestNow(Carbon::parse('2026-10-18 22:01:00', 'UTC'));
        $this->artisan('automations:run-due')->assertExitCode(0);

        $run->refresh();
        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('ran_to_end', $this->nodeOut($run, 'w')['state']);
        $this->assertTrue($this->nodeOut($run, 'w')['ran_to_end']);
    }

    public function test_the_calendar_day_is_the_one_of_the_configured_timezone_not_utc(): void
    {
        // 23:30 UTC on the 18th is already the 19th in Berlin. An other series that
        // ended at 21:30 UTC ended on the 18th in Berlin: the next day has begun.
        Carbon::setTestNow(Carbon::parse('2026-10-18 23:30:00', 'UTC'));
        $this->targetRun(AutomationRun::STATUS_SUCCESS, finishedAt: Carbon::parse('2026-10-18 21:30:00', 'UTC'));

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $this->runOwn(['settle' => 'next_day'])->status);
    }

    public function test_settle_uses_the_automations_timezone_setting(): void
    {
        config()->set('automations.timezone', 'UTC');
        $this->targetRun(AutomationRun::STATUS_SUCCESS, finishedAt: Carbon::now()->subHours(2));

        $run = $this->runOwn(['settle' => 'next_day']);

        $this->assertSame(
            Carbon::parse('2026-10-19 00:00:00', 'UTC')->timestamp,
            $this->pendingJob($run)->due_at->timestamp,
        );
    }

    public function test_settle_does_not_hold_a_run_that_ended_on_an_earlier_day(): void
    {
        $this->targetRun(AutomationRun::STATUS_SUCCESS, finishedAt: Carbon::now()->subDay());

        $run = $this->runOwn(['settle' => 'next_day']);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('ran_to_end', $this->nodeOut($run, 'w')['state']);
    }

    public function test_settle_none_is_the_default_and_does_not_hold_a_run_that_ended_today(): void
    {
        $this->targetRun(AutomationRun::STATUS_SUCCESS, finishedAt: Carbon::now()->subHour());

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $this->runOwn()->status);
        $this->assertSame(AutomationRun::STATUS_SUCCESS, $this->runOwn(['settle' => 'none'])->status);
    }

    public function test_settle_does_not_hold_a_run_that_ended_early(): void
    {
        // Only a run to the end earns the extra day.
        $this->targetRun(AutomationRun::STATUS_FAILED, finishedAt: Carbon::now()->subHour());

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $this->runOwn(['settle' => 'next_day'])->status);
    }

    public function test_other_people_test_runs_and_the_own_run_are_not_the_other_series(): void
    {
        $this->targetRun(AutomationRun::STATUS_WAITING, subject: 'bert@example.test');
        $this->targetRun(AutomationRun::STATUS_WAITING, isTest: true);

        $run = $this->runOwn();

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('none', $this->nodeOut($run, 'w')['state']);
    }

    public function test_pointing_at_its_own_automation_does_not_wait_for_itself(): void
    {
        $own = $this->ownAutomation(['automation' => 'own-flow'], 'own-flow');

        $run = $this->start($own);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('none', $this->nodeOut($run, 'w')['state']);
    }

    public function test_the_automation_can_be_named_by_uuid(): void
    {
        $this->targetRun(AutomationRun::STATUS_WAITING);

        $this->assertSame(AutomationRun::STATUS_WAITING, $this->runOwn(['automation' => (string) $this->target->uuid])->status);
    }

    public function test_test_mode_passes_straight_through(): void
    {
        $this->targetRun(AutomationRun::STATUS_WAITING);

        $result = (new WaitForAutomationNode(app(SequenceOptOut::class)))->execute(
            AutomationContext::make([], true),
            ['email' => 'anna@example.test', 'automation' => 'welcome'],
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('none', $result->output['state']);
        $this->assertFalse($result->output['ran_to_end']);
    }

    public function test_it_fails_on_bad_configuration(): void
    {
        $this->assertSame(AutomationRun::STATUS_FAILED, $this->runOwn(['automation' => 'does-not-exist'])->status);
        $this->assertSame(AutomationRun::STATUS_FAILED, $this->runOwn(['automation' => ''])->status);
        $this->assertSame(AutomationRun::STATUS_FAILED, $this->runOwn(['email' => 'kein-mail'])->status);
        $this->assertSame(AutomationRun::STATUS_FAILED, $this->runOwn(['email' => ''])->status);
        $this->assertSame(AutomationRun::STATUS_FAILED, $this->runOwn(['settle' => 'sometime'])->status);
        $this->assertSame(AutomationRun::STATUS_FAILED, $this->runOwn(['poll_hours' => 0])->status);
        $this->assertSame(AutomationRun::STATUS_FAILED, $this->runOwn(['wait_if_missing_minutes' => -1])->status);
    }

    public function test_a_later_node_reads_the_outputs_through_the_node_key(): void
    {
        $this->targetRun(AutomationRun::STATUS_SUCCESS, finishedAt: Carbon::now()->subDays(2));
        $run = $this->runOwn();

        // The follow-up node's message was written with the tokens below.
        $this->assertSame('state=ran_to_end flag=1', $this->nodeOut($run, 'after')['message']);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $config
     */
    private function runOwn(array $config = []): AutomationRun
    {
        return $this->start($this->ownAutomation($config));
    }

    private function start(Automation $automation): AutomationRun
    {
        $context = AutomationContext::make(['contact' => ['email' => 'Anna@Example.test']]);
        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));

        return $runner->execute($run, $context);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function ownAutomation(array $config = [], ?string $handle = null): Automation
    {
        $automation = Automation::create(['name' => 'Own', 'handle' => $handle ?? 'own-'.uniqid(), 'enabled' => true]);

        $nodes = [
            ['t', 'manual', []],
            ['w', 'wait_for_automation', array_merge(['email' => '{{ contact.email }}', 'automation' => 'welcome'], $config)],
            ['after', 'add_log_entry', ['message' => 'state={{ nodes.w.state }} flag={{ nodes.w.ran_to_end }}']],
        ];

        foreach ($nodes as [$key, $type, $nodeConfig]) {
            AutomationNode::create([
                'automation_id' => $automation->id, 'node_key' => $key, 'type' => $type, 'config' => $nodeConfig,
            ]);
        }

        foreach ([['t', 'w'], ['w', 'after']] as [$from, $to]) {
            AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => $from, 'to_node_key' => $to]);
        }

        return $automation->fresh()->load(['nodes', 'edges']);
    }

    private function targetRun(
        string $status,
        string $subject = 'anna@example.test',
        bool $isTest = false,
        ?Carbon $finishedAt = null,
        ?Carbon $createdAt = null,
    ): AutomationRun {
        $run = AutomationRun::create([
            'automation_id' => $this->target->id,
            'automation_uuid' => $this->target->uuid,
            'subject_key' => $subject,
            'status' => $status,
            'is_test' => $isTest,
            'started_at' => $createdAt ?? now(),
            'finished_at' => $finishedAt,
        ]);

        if ($createdAt !== null) {
            $run->forceFill(['created_at' => $createdAt])->save();
        }

        return $run;
    }

    private function pendingJob(AutomationRun $run): AutomationScheduledJob
    {
        $job = AutomationScheduledJob::where('automation_run_id', $run->id)
            ->where('status', AutomationScheduledJob::STATUS_PENDING)
            ->first();

        $this->assertNotNull($job, 'The run should be parked on a scheduled job.');

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeOut(AutomationRun $run, string $nodeKey): array
    {
        return (array) $run->nodeRuns()->where('node_key', $nodeKey)->orderByDesc('id')->first()?->output;
    }
}
