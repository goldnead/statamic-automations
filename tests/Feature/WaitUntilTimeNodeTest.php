<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitUntilTimeNode;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * "Wait Until Time": wait for a point in time from the run, plus or minus an offset.
 */
class WaitUntilTimeNodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function execNode(array $config, bool $testMode = false, array $data = []): ActionResult
    {
        return app(WaitUntilTimeNode::class)->execute(
            AutomationContext::make($data, testMode: $testMode),
            $config,
        );
    }

    public function test_it_waits_until_a_future_time(): void
    {
        $result = $this->execNode(['at' => '2026-10-21T09:00:00Z', 'offset_amount' => 0, 'offset_unit' => 'hours']);

        expect($result->isWaiting())->toBeTrue()
            ->and($result->waitUntil)->toBe(['due_at' => '2026-10-21T09:00:00+00:00'])
            ->and($result->output)->toMatchArray(['late' => false, 'waited' => true, 'due_at' => '2026-10-21T09:00:00+00:00']);
    }

    public function test_a_negative_offset_moves_the_target_earlier(): void
    {
        $result = $this->execNode(['at' => '2026-10-21T09:00:00Z', 'offset_amount' => -12, 'offset_unit' => 'hours']);

        expect($result->isWaiting())->toBeTrue()
            ->and($result->waitUntil['due_at'])->toBe('2026-10-20T21:00:00+00:00');

        // The same offset of -24 hours lands at 2026-10-20 09:00, already past at 10:00.
        $past = $this->execNode(['at' => '2026-10-21T09:00:00Z', 'offset_amount' => -24, 'offset_unit' => 'hours']);

        expect($past->isSuccess())->toBeTrue()
            ->and($past->output['late'])->toBeTrue();
    }

    public function test_the_default_offset_is_zero_hours(): void
    {
        $result = $this->execNode(['at' => '2026-10-20T12:00:00Z']);

        expect($result->waitUntil['due_at'])->toBe('2026-10-20T12:00:00+00:00');
    }

    public function test_days_and_minutes_units_are_applied(): void
    {
        $days = $this->execNode(['at' => '2026-10-25T00:00:00Z', 'offset_amount' => -2, 'offset_unit' => 'days']);
        $minutes = $this->execNode(['at' => '2026-10-20T11:00:00Z', 'offset_amount' => 30, 'offset_unit' => 'minutes']);

        expect($days->waitUntil['due_at'])->toBe('2026-10-23T00:00:00+00:00')
            ->and($minutes->waitUntil['due_at'])->toBe('2026-10-20T11:30:00+00:00');
    }

    public function test_a_target_in_the_past_continues_at_once_as_late(): void
    {
        $result = $this->execNode(['at' => '2026-10-20T09:00:00Z', 'offset_amount' => 0, 'offset_unit' => 'hours']);

        expect($result->isSuccess())->toBeTrue()
            ->and($result->isWaiting())->toBeFalse()
            ->and($result->output)->toMatchArray(['late' => true, 'waited' => false, 'due_at' => '2026-10-20T09:00:00+00:00']);
    }

    public function test_a_target_of_right_now_is_late_too(): void
    {
        $result = $this->execNode(['at' => '2026-10-20T10:00:00Z']);

        expect($result->isSuccess())->toBeTrue()
            ->and($result->output['late'])->toBeTrue();
    }

    public function test_an_unreadable_time_fails_and_never_passes(): void
    {
        $result = $this->execNode(['at' => 'not a date at all', 'offset_unit' => 'hours']);

        expect($result->isFailed())->toBeTrue()
            ->and($result->error)->toContain('not a readable date and time');
    }

    public function test_an_empty_time_fails_outside_test_mode(): void
    {
        $result = $this->execNode(['at' => '']);

        expect($result->isFailed())->toBeTrue()
            ->and($result->output)->toHaveKey('missing_data_reference', 'at');
    }

    public function test_an_unknown_unit_fails(): void
    {
        $result = $this->execNode(['at' => '2026-10-21T09:00:00Z', 'offset_unit' => 'weeks']);

        expect($result->isFailed())->toBeTrue();
    }

    public function test_test_mode_reports_the_time_and_does_not_pause(): void
    {
        $result = $this->execNode(['at' => '2026-10-21T09:00:00Z', 'offset_amount' => -1, 'offset_unit' => 'days'], testMode: true);

        expect($result->isSuccess())->toBeTrue()
            ->and($result->isWaiting())->toBeFalse()
            ->and($result->output['would_wait_until'])->toBe('2026-10-20T09:00:00+00:00');
    }

    public function test_test_mode_with_an_empty_time_is_not_an_error(): void
    {
        $result = $this->execNode(['at' => ''], testMode: true);

        expect($result->isSuccess())->toBeTrue()
            ->and($result->output['would_wait_until'])->toBeNull();
    }

    public function test_the_time_is_read_from_the_run_context_through_a_token(): void
    {
        $context = AutomationContext::make(['booking' => ['scheduled_at' => '2026-10-22T14:00:00Z']]);

        $config = app(TokenResolver::class)->resolve(
            ['at' => '{{ booking.scheduled_at }}', 'offset_amount' => -2, 'offset_unit' => 'hours'],
            $context,
        );

        $result = app(WaitUntilTimeNode::class)->execute($context, $config);

        expect($config['at'])->toBe('2026-10-22T14:00:00Z')
            ->and($result->waitUntil['due_at'])->toBe('2026-10-22T12:00:00+00:00');
    }

    public function test_it_is_registered_as_a_logic_node(): void
    {
        expect(WaitUntilTimeNode::handle())->toBe('wait_until_time')
            ->and(WaitUntilTimeNode::group())->toBe('Logic');
    }
}
