<?php

return [
    'activity' => [
        'heading' => 'Aktivität',
        'node_removed_suffix' => '(nicht mehr im Ablauf)',
    ],

    // The kind's name on the funnels map picker and card.
    'map_type_label' => 'Automatisierung',

    // Die Beschriftung einer Kante auf der Funnel-Landkarte, je Beziehung, die eine Automatisierung ergibt.
    'map_edge_waits_for' => 'abgewartet von',
    'map_edge_starts' => 'startet',
    'map_edge_triggers' => 'löst aus',
    'map_edge_grants' => 'gewährt Zugang',

    // Ein kaputter Verweis dieser Automatisierung, wie ihn die Landkarte zeigt.
    'map_problem_template' => 'Schritt „:step“ sendet die Vorlage „:template“, die es nicht gibt',
    'map_problem_automation' => 'Schritt „:step“ verweist auf die Automatisierung „:automation“, die es nicht gibt',

    'nav' => [
        'automations' => 'Automatisierungen',
        'dashboard' => 'Übersicht',
        'runs' => 'Durchläufe',
        'templates' => 'Vorlagen',
        'audit' => 'Änderungsprotokoll',
    ],
    'permissions' => [
        'group' => 'Automatisierungen',
        'view' => 'Automatisierungen ansehen',
        'create' => 'Automatisierungen erstellen',
        'edit' => 'Automatisierungen bearbeiten',
        'delete' => 'Automatisierungen löschen',
        'enable' => 'Automatisierungen aktivieren / deaktivieren',
        'test' => 'Automatisierungen testen',
        'view_runs' => 'Durchläufe ansehen',
        'retry_runs' => 'Durchläufe erneut ausführen',
        'settings' => 'Einstellungen verwalten',
        'connections' => 'Verbindungen und ihre Zugangsdaten verwalten',
    ],
    'errors' => [
        'max_depth' => 'Maximale Verschachtelungstiefe (:max) erreicht.',
        'automation_not_found' => "Automatisierung ':ref' wurde nicht gefunden.",
    ],
];
