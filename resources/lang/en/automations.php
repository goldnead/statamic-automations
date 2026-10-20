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

    // Drawing a connection on the funnels map: the consequence in words, and why a line is refused.
    'map_connect_product' => 'From now on ":automation" starts whenever access ":slug" is granted, which happens when someone buys ":object".',
    'map_connect_shared' => 'Other products grant it too and start it as well: :products.',
    'map_live_note' => 'The automation is active, so this applies immediately.',
    'map_connect_changed' => '":automation" was changed in the meantime. Nothing was written, please look again.',
    'map_connect_grants_nothing' => '":object" grants no access, so it cannot start ":automation".',
    'map_connect_grants_many' => '":object" grants several accesses (:slugs). Choose one in the automation editor.',
    'map_connect_grants_unknown' => 'We cannot tell which access ":object" grants here. Choose it in the automation editor.',
    'map_connect_lead_magnet' => 'From now on ":automation" starts for everyone who gets ":object".',
    'map_disconnect' => '":automation" is switched off and no longer waits for ":object". An empty trigger means every access, so switch it on only after you chose a new one.',
    'map_disconnect_running' => 'This automation is switched on. Without a product it would start on every access that is granted. Switch it off first.',
    'map_connect_unsupported' => 'This pair cannot be connected here.',
    'map_connect_no_automation' => 'The automation ":id" does not exist.',
    'map_connect_no_source' => '":id" does not exist.',
    'map_connect_no_trigger' => '":automation" has no trigger step.',
    'map_connect_many_triggers' => '":automation" has more than one trigger step.',
    'map_connect_other_trigger' => '":automation" has a different trigger.',
    'map_connect_other_slug' => '":automation" already starts for ":slug". Release that first.',
    'map_connect_already' => '":automation" is already connected to ":object".',
    'map_disconnect_not_connected' => '":automation" does not start for ":object".',

    // A broken reference this automation carries, as the map shows it.
    'map_detail_mails' => 'Mails',
    'map_detail_wait_none' => 'right away',
    'map_detail_wait_days' => 'after :count day|after :count days',
    'map_detail_wait_hours' => 'after :count hour|after :count hours',
    'map_detail_wait_minutes' => 'after :count minute|after :count minutes',
    'map_detail_mail_fallback' => 'Mail without subject',
    'map_detail_mail_campaign' => 'Campaign ":campaign"',
    'map_detail_mail_template' => 'Mail without subject (template ":template")',
    'map_var_first_name' => 'First name',
    'map_var_last_name' => 'Last name',
    'map_var_name' => 'Name',
    'map_var_email' => 'Email',
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
