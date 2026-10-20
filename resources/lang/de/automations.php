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
    'map_edge_triggers_by_grant' => 'Produkt gewährt Zugang, löst aus',
    'map_edge_grants' => 'gewährt Zugang',

    // Eine Verbindung auf der Funnel-Landkarte ziehen: die Folge in Klartext und der Grund einer Absage.
    'map_connect_product' => 'Ab jetzt startet „:automation“, sobald der Zugang „:slug“ gewährt wird, etwa wenn jemand „:object“ kauft.',
    'map_connect_shared' => 'Andere Produkte gewähren ihn auch und starten sie ebenfalls: :products.',
    'map_live_note' => 'Die Automatisierung ist aktiv, die Änderung gilt sofort.',
    'map_connect_changed' => '„:automation“ wurde zwischenzeitlich geändert. Es wurde nichts geschrieben, bitte neu ansehen.',
    'map_connect_grants_nothing' => '„:object“ gewährt keinen Zugang und kann „:automation“ deshalb nicht starten.',
    'map_connect_grants_many' => '„:object“ gewährt mehrere Zugänge (:slugs). Wähle einen im Automatisierungs-Editor.',
    'map_connect_grants_unknown' => 'Hier lässt sich nicht feststellen, welchen Zugang „:object“ gewährt. Wähle ihn im Automatisierungs-Editor.',
    'map_connect_lead_magnet' => 'Ab jetzt startet „:automation“ für alle, die „:object“ bekommen.',
    'map_disconnect' => '„:automation“ ist ausgeschaltet und wartet nicht mehr auf „:object“. Ein leerer Auslöser heißt jeder Zugang, schalte sie erst wieder ein, wenn du einen neuen gewählt hast.',
    'map_disconnect_running' => 'Diese Automatisierung ist eingeschaltet. Ohne Produkt würde sie bei jeder Freischaltung starten. Schalte sie erst aus.',
    'map_connect_unsupported' => 'Dieses Paar lässt sich hier nicht verbinden.',
    'map_connect_no_automation' => 'Die Automatisierung „:id“ gibt es nicht.',
    'map_connect_no_source' => '„:id“ gibt es nicht.',
    'map_connect_no_trigger' => '„:automation“ hat keinen Auslöser-Schritt.',
    'map_connect_many_triggers' => '„:automation“ hat mehr als einen Auslöser-Schritt.',
    'map_connect_other_trigger' => '„:automation“ hat einen anderen Auslöser.',
    'map_connect_other_slug' => '„:automation“ startet schon für „:slug“. Erst lösen.',
    'map_connect_already' => '„:automation“ ist schon mit „:object“ verbunden.',
    'map_disconnect_not_connected' => '„:automation“ startet nicht für „:object“.',

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
