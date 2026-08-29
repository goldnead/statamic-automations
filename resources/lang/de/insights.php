<?php

/*
 * Die Worte fuer die Zahlen, die dieses Addon an statamic-insights meldet.
 *
 * Eigene Datei statt eines Abschnitts in automations.php: das Analytics-Addon
 * ist optional, und wer automations.php liest, soll nicht heraussuchen muessen,
 * welche Haelfte davon nur mit einem Geschwister-Addon ueberhaupt greift.
 *
 * „Automatisierungen" und nicht „Automationen": so heisst der Bereich in der
 * Navigation dieses Addons, und eine Ueberschrift auf dem gemeinsamen Schirm,
 * die anders heisst als der Menuepunkt, sieht nach einem zweiten Addon aus.
 */

return [
    'group' => 'Automatisierungen',

    'runs' => 'Durchläufe',
    'runs_description' => 'Durchläufe, die in diesem Zeitraum begonnen haben. Testläufe zählen nicht mit.',

    'failures' => 'Fehlgeschlagene Durchläufe',
    'failures_description' => 'Durchläufe, die mit einem Fehler geendet haben. Ein Durchlauf, den ein Stopp-Knoten beendet hat, ist ausgestiegen und kein Fehlschlag.',

    'success_rate' => 'Erfolgsquote',
    'success_rate_description' => 'Von den Durchläufen mit einem Urteil der Anteil, der funktioniert hat. Wer noch in einer Verzögerung wartet, hat kein Urteil und bleibt außen vor.',

    'duration_p50' => 'Laufzeit (Median)',
    'duration_p50_description' => 'Der Durchlauf in der Mitte, in Sekunden. Immer eine wirklich gemessene Laufzeit, nie ein Zwischenwert.',

    'opt_outs' => 'Serien-Ausstiege',
    'opt_outs_description' => 'Personen, die eine einzelne Automation verlassen haben, ohne sich sonst abzumelden.',

    'breakdown_status' => 'Status',
    'breakdown_trigger' => 'Auslöser',
    'breakdown_automation' => 'Automation',

    'no_status' => 'Ohne Status',
    'no_trigger' => 'Ohne Auslöser',
    'no_automation' => 'Ohne Automation',

    'status' => [
        'queued' => 'In der Warteschlange',
        'running' => 'Läuft',
        'waiting' => 'Wartet',
        'success' => 'Erfolgreich',
        'stopped' => 'Gestoppt',
        'cancelled' => 'Abgebrochen',
        'failed' => 'Fehlgeschlagen',
    ],
];
