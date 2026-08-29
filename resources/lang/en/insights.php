<?php

/*
 * The words for the figures this addon contributes to statamic-insights.
 *
 * Their own file rather than a section of automations.php: the analytics addon
 * is optional, and a reader of that file should not have to work out which half
 * of it only applies when a sibling is installed.
 */

return [
    'group' => 'Automations',

    'runs' => 'Runs',
    'runs_description' => 'Automation runs that started in this period. Test runs are not counted.',

    'failures' => 'Failed runs',
    'failures_description' => 'Runs that ended with an error. A run that a stop node ended left the flow and is not a failure.',

    'success_rate' => 'Success rate',
    'success_rate_description' => 'Of the runs that reached a verdict, the share that succeeded. A run still waiting in a delay has no verdict yet and is left out.',

    'duration_p50' => 'Run time (median)',
    'duration_p50_description' => 'The run in the middle, in seconds. An actual run, never an interpolated value.',

    'opt_outs' => 'Series opt-outs',
    'opt_outs_description' => 'People who left a single automation without unsubscribing from anything else.',

    'breakdown_status' => 'Status',
    'breakdown_trigger' => 'Trigger',
    'breakdown_automation' => 'Automation',

    'no_status' => 'Without a status',
    'no_trigger' => 'Without a trigger',
    'no_automation' => 'Without an automation',

    'status' => [
        'queued' => 'Queued',
        'running' => 'Running',
        'waiting' => 'Waiting',
        'success' => 'Succeeded',
        'stopped' => 'Stopped',
        'cancelled' => 'Cancelled',
        'failed' => 'Failed',
    ],
];
