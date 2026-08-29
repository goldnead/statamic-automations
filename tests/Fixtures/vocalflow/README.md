# VocalFlow-Nutzlasten

Die Dateien hier sind aus VocalFlows Quelltext erhoben und nicht ausgedacht:
jedes Feld steht so in der `getModelData()`- beziehungsweise
`getMetadata()`-Methode der zugehoerigen Ereignisklasse
(`apps/api/app/Events/*.php`), der Umschlag in `BaseWebhookEvent::getBasePayload()`
und die Ergaenzungen in `metadata` in `WebhookEventListener`.

Sie sind hier, damit die Tests gegen die Form pruefen, die VocalFlow wirklich
schickt, und nicht gegen die Form, die der Flattener erwartet. Der Unterschied
ist der ganze Punkt: eine ausgedachte Nutzlast bestaetigt nur die eigene
Annahme, und genau daran scheitert ein Anschluss spaeter im Betrieb.

## Vier Eigenheiten, die man beim Lesen leicht fuer Tippfehler haelt

- **`data` ist verschachtelt.** Es gibt kein `data.student_id`. Die Kennung des
  Studenten steht in `data.session.student_id` beziehungsweise
  `data.task.student_id`, die Person daneben in `data.student`.
- **`student.id` und `session.student_id` sind zwei verschiedene Kennungen.**
  Die erste ist die laufende Nummer des Kontos (Zahl), die zweite dessen UUID
  (Zeichenkette). Sie heissen fast gleich und sind nicht austauschbar.
- **`task.created` traegt kein `task.student_id`.** Nur `task.assigned` legt es
  bei. Das ist kein Fehler in der Fixture, sondern VocalFlows Verhalten.
- **`session.completed` laesst `session_type.duration_minutes` weg**, obwohl
  `session.created` es mitschickt.
- **`due_date` ist ein voller Zeitstempel**, kein blosses Datum: das Feld haengt
  an einem `datetime`-Cast und geht durch dasselbe `toISOString()` wie alle
  anderen Zeitpunkte.
- **`follow_up_required` und `referral_eligible` schliessen einander aus.**
  VocalFlow setzt `referral_eligible` nur, wenn die Bewertung mindestens 4 ist
  **und** kein Nachfassen noetig ist. Eine Nutzlast mit beidem auf `true` kann
  dort nicht entstehen; deshalb liegen hier zwei Abschluss-Nutzlasten
  nebeneinander, `session-completed.json` (Empfehlung) und
  `session-completed-follow-up.json` (Nachfassen). Ein Ablauf, der die eine als
  Vorlage nimmt und beide Mails schickt, waere der Fehler, den diese Trennung
  verhindert.

## Die Zeitstempel liegen alle am selben Tag

Das ist kein Zufall: der Empfaenger verwirft einen Umschlag, der aelter als
`max_age_minutes` ist, und Fixtures, die ueber mehrere Tage streuen, waeren je
nach Testzeitpunkt teils abgelaufen. Die Tests stellen die Uhr fest auf diesen
Tag.

## Zwei Zusaetze mit Absicht

In `task-assigned.json`, `task-created.json`, `task-updated.json` und
`task-deleted.json` stehen Schraegstriche (`3/4-Takt`, eine URL) und in allen
Dateien ein Umlaut im Namen. Beides ist echter deutscher Unterrichtsinhalt und
beides ist hier **noetig**, nicht Zierde: VocalFlow signiert eine kanonisch
kodierte Fassung, die Bytes auf der Leitung escapen Schraegstriche und
Nicht-ASCII-Zeichen aber anders. Ohne ein `/` und ein `ö` irgendwo in der
Nutzlast waeren beide Kodierungen identisch, und der Test, der den Unterschied
festhalten soll, wuerde stillschweigend nichts pruefen.

## `task-deleted.json` ist die einzige konstruierte Datei

`task.deleted` steht in VocalFlows Abo-Liste (`WebhookSubscription::EVENT_*`),
aber es gibt dort **keine Ereignisklasse dazu** und niemanden, der es
verschickt. Diese Nutzlast ist deshalb nach dem Muster der drei anderen
Aufgaben-Ereignisse gebaut und nicht beobachtet. Was sie belegt, ist, dass der
Auslöser auf seinen Namen anspringt und der Flattener die uebliche Form
verarbeitet — nicht, dass VocalFlow genau das schickt.

## `session-published.json` kommt ueber einen anderen Kanal

Sie ist kein Umschlag, sondern die flache Nutzlast des eigenen Endpunkts
(`NotifyPublishedSessionToAccount`): zwei Felder, kein `event`, kein
`timestamp`, und die Anfrage traegt statt einer Signatur ein
`Authorization: Bearer`.
