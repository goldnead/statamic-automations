<?php

return [
    'nav' => [
        'automations' => 'Automations',
        'dashboard' => 'Dashboard',
        'runs' => 'Runs',
        'templates' => 'Templates',
        'audit' => 'Audit log',
    ],
    'permissions' => [
        'group' => 'Automations',
        'view' => 'View automations',
        'create' => 'Create automations',
        'edit' => 'Edit automations',
        'delete' => 'Delete automations',
        'enable' => 'Enable / disable automations',
        'test' => 'Run automation tests',
        'view_runs' => 'View automation runs',
        'retry_runs' => 'Retry automation runs',
        'settings' => 'Manage automation settings',
    ],
    'errors' => [
        'ai_requires_pro' => 'The AI action requires a Pro license.',
        'max_depth' => 'Maximum sub-automation depth (:max) reached.',
        'automation_not_found' => "Automation ':ref' not found.",
    ],
];
