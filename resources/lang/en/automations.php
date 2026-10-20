<?php

return [
    'activity' => [
        'heading' => 'Activity',
        'node_removed_suffix' => '(no longer in the flow)',
    ],

    // The kind's name on the funnels map picker and card.
    'map_type_label' => 'Automation',

    // The wording on a funnels map edge, one per relation an automation implies.
    'map_edge_waits_for' => 'awaited by',
    'map_edge_starts' => 'starts',
    'map_edge_triggers' => 'triggers',
    'map_edge_triggers_by_grant' => 'product grants it, triggers',
    'map_edge_grants' => 'grants access',

    // A broken reference this automation carries, as the map shows it.
    'map_problem_template' => 'step ":step" sends template ":template", which does not exist',
    'map_problem_automation' => 'step ":step" points at automation ":automation", which does not exist',

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
        'connections' => 'Manage connections and their credentials',
    ],
    'errors' => [
        'max_depth' => 'Maximum sub-automation depth (:max) reached.',
        'automation_not_found' => "Automation ':ref' not found.",
    ],
];
