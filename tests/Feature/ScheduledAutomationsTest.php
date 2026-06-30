<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
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

it('skips disabled scheduled automations', function () {
    $automation = makeScheduledAutomation(['frequency' => 'cron', 'cron' => '* * * * *'], enabled: false);

    $this->artisan('automations:run-scheduled')->assertSuccessful();

    expect(AutomationRun::where('automation_id', $automation->id)->count())->toBe(0);
});
