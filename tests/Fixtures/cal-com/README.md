# Echte cal.com-Nutzlasten

Die Dateien hier sind aus cal.coms Webhook-Dokumentation uebernommen
(https://cal.com/docs/developing/guides/automation/webhooks, Nutzlast-Version
`2021-10-20`), gekuerzt um nichts und veraendert nur an den Stellen, an denen
Namen und Adressen stehen.

Sie sind hier, damit die Tests gegen die Form pruefen, die cal.com wirklich
schickt, und nicht gegen die Form, die der Flattener erwartet. Der Unterschied
ist der ganze Punkt: eine ausgedachte Nutzlast bestaetigt nur die eigene
Annahme, und genau daran scheitert ein Anschluss spaeter im Betrieb.

Drei Eigenheiten, die man beim Lesen leicht fuer Tippfehler haelt und die keine
sind:

- `type` ist der **Slug** der Terminart, `eventTitle` ihr **Titel**.
- `language` ist ein Objekt `{"locale": "en"}`, keine Zeichenkette.
- Die Zeitschreibweise wechselt je Ereignis: `...Z`, `...+00:00`, `....000Z`.
  Alle drei sind UTC.

Zwei Zusaetze gegenueber den Beispielen der Dokumentation, beide in einer echten
Nutzlast genauso vorhanden: in `booking-created.json` steht eine Antwort auf eine
eigene Buchungsfrage (`chor`) und eine ausgefuellte Telefonnummer. Ohne sie
liefen die Felder `booking.answers.*` und `booking.attendee.phone` in den Tests
gegen lauter Leerwerte und bewiesen nichts.

Alle `createdAt` liegen am selben Tag. Das ist kein Zufall: der Empfaenger
verwirft einen Umschlag, der aelter als `max_age_minutes` ist, und Fixtures, die
ueber mehrere Tage streuen, waeren je nach Testzeitpunkt teils abgelaufen. Die
Tests stellen die Uhr fest auf diesen Tag.
