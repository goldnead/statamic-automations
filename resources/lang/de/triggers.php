<?php

/*
 * Deutsche Beschriftung der Auslöser in der Knoten-Bibliothek.
 *
 * Nach Handle geordnet (`payments.subscription_paused` → `payments` →
 * `subscription_paused`), nicht nach dem englischen Text. JSON-Übersetzungen
 * gelten für die ganze Seite; ein Schlüssel wie „Courses" oder „Quiz Passed"
 * hätte die Oberfläche eines anderen Addons mit übersetzt. Hier hat jeder
 * Eintrag seinen eigenen Namensraum. Was fehlt, zeigt den englischen Text der
 * Klasse. `groups` sind die Gruppennamen der Auslöser.
 */

return [
    'groups' => [
        'Payments' => 'Zahlungen',
        'Funnels' => 'Funnels',
        'Courses' => 'Kurse',
        'Affiliates' => 'Partnerprogramm',
    ],

    'payments' => [
        'checkout_abandoned' => [
            'label' => 'Kauf abgebrochen',
            'description' => 'Wenn ein Kauf begonnen und über die Wartezeit hinaus nicht bezahlt wurde.',
        ],
        'checkout_blocked' => [
            'label' => 'Kauf gesperrt',
            'description' => 'Wenn die Sperrliste, die Mengenbremse oder das Captcha einen Kauf abweist. Für Hinweise an dein Team: keine Mail an blocked.email schicken, dort steht, was der abgewiesene Besuch eingetippt hat.',
        ],
        'charged_back' => [
            'label' => 'Zahlung zurückgebucht',
            'description' => 'Wenn die Bank eine Zahlung zurückholt. Den Zugang entzieht das Zahlungs-Addon selbst; hier geht es darum, rechtzeitig einen Menschen zu informieren.',
        ],
        'failed' => [
            'label' => 'Zahlung fehlgeschlagen',
            'description' => 'Wenn eine Zahlung als fehlgeschlagen, abgelaufen oder abgebrochen gemeldet wird.',
        ],
        'paid' => [
            'label' => 'Zahlung eingegangen',
            'description' => 'Einmal, sobald der Anbieter eine Zahlung als bezahlt bestätigt.',
        ],
        'refunded' => [
            'label' => 'Zahlung erstattet',
            'description' => 'Wenn eine Erstattung zu einer Zahlung verbucht wird, ganz oder teilweise.',
        ],
        'subscription_attempt_failed' => [
            'label' => 'Abo-Abbuchung fehlgeschlagen',
            'description' => 'Einmal je fehlgeschlagener Abbuchung eines laufenden Abos, mit der Zahl der Fehlschläge in Folge.',
        ],
        'subscription_cancelled' => [
            'label' => 'Abo gekündigt',
            'description' => 'Wenn der Anbieter bestätigt, dass ein Abo gekündigt wurde.',
        ],
        'subscription_card_expired' => [
            'label' => 'Abo-Karte abgelaufen',
            'description' => 'Wenn die Karte hinter einem Abo abgelaufen ist und die nächste Abbuchung scheitern würde.',
        ],
        'subscription_card_expiring' => [
            'label' => 'Abo-Karte läuft bald ab',
            'description' => 'Wenn die Karte hinter einem Abo bald abläuft. Nur Stripe-Karten und Mollie-Kreditkartenmandate.',
        ],
        'subscription_changed' => [
            'label' => 'Abo gewechselt',
            'description' => 'Wenn ein Abo auf ein anderes Produkt wechselt, nach oben oder nach unten.',
        ],
        'subscription_ended' => [
            'label' => 'Abo beendet',
            'description' => 'Wenn ein Abo sein eigenes Ende erreicht, zum Beispiel eine abbezahlte Ratenzahlung. Bei einer abbezahlten Ratenzahlung feuert im selben Moment auch payments.subscription_plan_completed.',
        ],
        'subscription_paused' => [
            'label' => 'Abo pausiert',
            'description' => 'Wenn ein Abo pausiert wird, im Control Panel oder von der Kundin im Kundenbereich.',
        ],
        'subscription_payment_upcoming' => [
            'label' => 'Abo-Abbuchung steht bevor',
            'description' => 'Eine eingestellte Zahl von Tagen, bevor ein Abo wieder abgebucht wird.',
        ],
        'subscription_plan_completed' => [
            'label' => 'Abo-Ratenzahlung abgeschlossen',
            'description' => 'Einmal, wenn die letzte Rate einer Ratenzahlung bezahlt ist. Im selben Moment feuert auch payments.subscription_ended; nimm einen der beiden, nicht beide.',
        ],
        'subscription_renewed' => [
            'label' => 'Abo verlängert',
            'description' => 'Einmal je Abo-Zeitraum, der abgebucht und bezahlt ist.',
        ],
        'subscription_replaced' => [
            'label' => 'Abo ersetzt',
            'description' => 'Wenn ein Kauf ein früheres Abo derselben Person beendet, so wie das Produkt es vorsieht.',
        ],
        'subscription_resumed' => [
            'label' => 'Abo fortgesetzt',
            'description' => 'Wenn ein pausiertes Abo wieder läuft, von Hand oder am beim Pausieren gesetzten Datum.',
        ],
        'subscription_started' => [
            'label' => 'Abo gestartet',
            'description' => 'Wenn der Anbieter ein Abo bestätigt und der erste Zeitraum bezahlt ist.',
        ],
        'subscription_start_failed' => [
            'label' => 'Abo-Start fehlgeschlagen',
            'description' => 'Wenn eine Abo-Zahlung eingegangen ist, aber kein Abo dahinter angelegt wurde.',
        ],
    ],

    'funnels' => [
        'completed' => [
            'label' => 'Funnel abgeschlossen',
            'description' => 'Wenn ein Besuch das Ende eines Funnels erreicht.',
        ],
        'form_submitted' => [
            'label' => 'Funnel-Formular abgeschickt',
            'description' => 'Wenn ein Besuch das Formular auf einem Funnel-Schritt abschickt.',
        ],
        'offer_accepted' => [
            'label' => 'Funnel-Angebot angenommen',
            'description' => 'Wenn ein Besuch ein Angebot im Funnel annimmt und die Zahlung durch ist.',
        ],
        'offer_declined' => [
            'label' => 'Funnel-Angebot abgelehnt',
            'description' => 'Bei jedem Nein auf ein Angebot im Funnel. Nach einem bezahlten Kauf feuert auf denselben Klick auch funnels.upsell_declined; den nehmen, wer nur Käufer erreichen will.',
        ],
        'step_entered' => [
            'label' => 'Funnel-Schritt betreten',
            'description' => 'Wenn ein Besuch auf einem Funnel-Schritt ankommt.',
        ],
        'upsell_declined' => [
            'label' => 'Upsell nach Kauf abgelehnt',
            'description' => 'Wenn jemand nach einem bezahlten Kauf im selben Funnel ein Angebot ablehnt. Enthält, was gekauft wurde. Auf denselben Klick feuert auch funnels.offer_declined.',
        ],
    ],

    'courses' => [
        'access_restored' => [
            'label' => 'Kurszugang wieder offen',
            'description' => 'Wenn ein gesperrter Kurs für eine lernende Person wieder aufgeht.',
        ],
        'access_suspended' => [
            'label' => 'Kurszugang gesperrt',
            'description' => 'Wenn ein Kurs für eine lernende Person gesperrt wird, nach einer fehlgeschlagenen Zahlung oder von Hand.',
        ],
        'course_completed' => [
            'label' => 'Kurs abgeschlossen',
            'description' => 'Einmal, wenn eine lernende Person alle Lektionen eines Kurses abgeschlossen hat.',
        ],
        'drip_paused' => [
            'label' => 'Kursfreigabe pausiert',
            'description' => 'Wenn für eine lernende Person keine neuen Lektionen mehr aufgehen, zum Beispiel nach einer fehlgeschlagenen Zahlung.',
        ],
        'drip_resumed' => [
            'label' => 'Kursfreigabe fortgesetzt',
            'description' => 'Wenn nach einer Pause wieder neue Lektionen aufgehen.',
        ],
        'learner_enrolled' => [
            'label' => 'Kurs: eingeschrieben',
            'description' => 'Einmal, wenn jemand zum ersten Mal in einen Kurs eingeschrieben wird.',
        ],
        'lesson_completed' => [
            'label' => 'Kurs: Lektion abgeschlossen',
            'description' => 'Wenn eine lernende Person eine Lektion abschließt, von Hand, durch Ansehen oder durch ein bestandenes Quiz.',
        ],
        'lesson_unlocked' => [
            'label' => 'Kurs: Lektion freigeschaltet',
            'description' => 'Wenn eine Lektion aufgeht, weil die Person etwas getan oder bezahlt hat. Nicht für Lektionen, die nur durch ein Datum aufgehen.',
        ],
        'quiz_failed' => [
            'label' => 'Kurs: Quiz nicht bestanden',
            'description' => 'Bei jedem Versuch an einem Lektions-Quiz, der nicht bestanden ist.',
        ],
        'quiz_passed' => [
            'label' => 'Kurs: Quiz bestanden',
            'description' => 'Wenn eine lernende Person das Quiz einer Lektion besteht.',
        ],
        'team_member_added' => [
            'label' => 'Kurs: Teamplatz vergeben',
            'description' => 'Wenn eine Käuferin einen Teamplatz eines Kurses vergibt. Der Durchlauf gilt dem neuen Mitglied.',
        ],
        'team_member_removed' => [
            'label' => 'Kurs: Teamplatz frei',
            'description' => 'Wenn eine Käuferin jemanden aus dem Kursteam nimmt. Der Durchlauf gilt dem entfernten Mitglied.',
        ],
    ],

    'affiliates' => [
        'commission_earned' => [
            'label' => 'Partner: Provision verdient',
            'description' => 'Wenn ein Verkauf einem Partner eine Provision oder einen Anteil aus einer Umsatzteilung bringt.',
        ],
        'commission_reversed' => [
            'label' => 'Partner: Provision zurückgenommen',
            'description' => 'Wenn eine Provision nach einer Erstattung, einer Rückbuchung oder von Hand zurückgenommen wird.',
        ],
        'partner_applied' => [
            'label' => 'Partner: Bewerbung eingegangen',
            'description' => 'Wenn sich jemand für das Partnerprogramm bewirbt.',
        ],
        'partner_approved' => [
            'label' => 'Partner: freigegeben',
            'description' => 'Wenn ein Partner freigegeben ist und empfehlen kann.',
        ],
    ],
];
