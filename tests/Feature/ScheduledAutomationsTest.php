<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

function makeScheduledAutomation(array $config, bool $enabled = true): Automation
{
    $automation = Automation::create(['name' => 'Scheduled', 'handle' => 'sched', 'enabled' => $enabled]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 't',
        'type' => 'scheduled',
        'config' => $config,
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id,
        'node_key' => 'log',
        'type' => 'add_log_entry',
        'config' => ['message' => 'tick'],
    ]);

    return $automation;
}

beforeEach(function () {
    Queue::fake();
});

it('dispatches a run for a due cron schedule', function () {
    // "* * * * *" is due every minute.
    $automation = makeScheduledAutomation(['frequency' => 'cron', 'cron' => '* * * * *']);

    $this->artisan('automations:run-scheduled')->assertSuccessful();

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1);
});

it('does not dispatch a schedule that is not due', function () {
    // 2:17 AM on the 30th of February never occurs → never due now.
    $automation = makeScheduledAutomation(['frequency' => 'cron', 'cron' => '17 2 30 2 *']);

    $this->artisan('automations:run-scheduled')->assertSuccessful();

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});

it('warns about an invalid cron expression instead of silently never firing', function () {
    $automation = makeScheduledAutomation(['frequency' => 'cron', 'cron' => 'not a cron']);

    Log::spy();

    $this->artisan('automations:run-scheduled')->assertSuccessful();

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => ($context['automation_id'] ?? null) === $automation->id)
        ->once();
});

it('warns about an unknown frequency', function () {
    $automation = makeScheduledAutomation(['frequency' => 'fortnightly']);

    Log::spy();

    $this->artisan('automations:run-scheduled')->assertSuccessful();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => ($context['automation_id'] ?? null) === $automation->id)
        ->once();
});

it('warns about a broken schedule once an hour, not every minute', function () {
    $automation = makeScheduledAutomation(['frequency' => 'cron', 'cron' => 'not a cron']);

    Log::spy();

    $this->artisan('automations:run-scheduled')->assertSuccessful();
    $this->artisan('automations:run-scheduled')->assertSuccessful();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => ($context['automation_id'] ?? null) === $automation->id)
        ->once();
});

it('does not warn about a valid schedule', function () {
    makeScheduledAutomation(['frequency' => 'daily', 'time' => '09:00']);

    Log::spy();

    $this->artisan('automations:run-scheduled')->assertSuccessful();

    Log::shouldNotHaveReceived('warning');
});

it('skips disabled scheduled automations', function () {
    $automation = makeScheduledAutomation(['frequency' => 'cron', 'cron' => '* * * * *'], enabled: false);

    $this->artisan('automations:run-scheduled')->assertSuccessful();

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});
