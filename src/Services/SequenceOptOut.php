<?php

namespace Goldnead\StatamicAutomations\Services;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationOptOut;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Collection;

/**
 * Aus einer Serie aussteigen, ohne alles abzubestellen.
 *
 * Die eine Stelle, die weiss, ob jemand von einer Automation nichts mehr will.
 * Gefragt wird sie an genau zwei Punkten, und beide sind noetig:
 *
 *  - **bevor ein Lauf beginnt** (EnrollmentGate), damit ein Ausstieg auch fuer
 *    einen spaeteren zweiten Durchlauf gilt. Sonst haette sich jemand aus der
 *    Willkommensstrecke abgemeldet und bekaeme sie beim naechsten Anlass
 *    wieder;
 *  - **vor jedem Sendeschritt**, weil eine Serie tagelang wartet. Wer an Tag 3
 *    aussteigt, darf Mail 4 nicht mehr bekommen — und nur der Sendeknoten
 *    laeuft zwischen den Wartezeiten ueberhaupt noch los.
 *
 * Der Schluessel ist derselbe wie bei den Laeufen (`subject_key`): kleingeschrieben
 * und getrimmt, so wie EnrollmentGate::clean() es macht. Zwei Schreibweisen
 * derselben Adresse muessen dieselbe Person sein, sonst haengt der Ausstieg an
 * der Grossschreibung im Anmeldeformular.
 */
class SequenceOptOut
{
    /** Traegt den Ausstieg ein. Ein zweiter Klick aendert nichts. */
    public function add(string $automationUuid, string $subject, string $source = AutomationOptOut::SOURCE_MAIL_LINK): ?AutomationOptOut
    {
        $subject = $this->key($subject);

        if ($subject === null || trim($automationUuid) === '') {
            return null;
        }

        $existing = $this->query($automationUuid, $subject)->first();

        if ($existing) {
            return $existing;
        }

        return AutomationOptOut::create([
            'automation_uuid' => $automationUuid,
            'subject_key' => $subject,
            'source' => $source,
            'opted_out_at' => now(),
        ]);
    }

    /**
     * Nimmt den Ausstieg zurueck.
     *
     * Loeschen statt eines Statusfeldes: ein Ausstieg, der als `active = false`
     * liegen bleibt, wird beim naechsten Query uebersehen. Wer wieder dabei
     * sein will, hat keine Zeile.
     */
    public function remove(string $automationUuid, string $subject): bool
    {
        $subject = $this->key($subject);

        if ($subject === null) {
            return false;
        }

        return $this->query($automationUuid, $subject)->delete() > 0;
    }

    public function has(string $automationUuid, string $subject): bool
    {
        $subject = $this->key($subject);

        if ($subject === null || trim($automationUuid) === '') {
            return false;
        }

        return $this->query($automationUuid, $subject)->exists();
    }

    /**
     * Die Automations-UUIDs, aus denen diese Person ausgestiegen ist.
     *
     * @return list<string>
     */
    public function forSubject(string $subject): array
    {
        $subject = $this->key($subject);

        if ($subject === null) {
            return [];
        }

        return AutomationOptOut::query()
            ->where('subject_key', $subject)
            ->pluck('automation_uuid')
            ->all();
    }

    /**
     * Die Serien, in denen diese Person gerade steckt — das, was eine
     * Selbstbedienungs-Seite anzeigen muss.
     *
     * „Gerade drin" heisst: ein Lauf wartet noch auf seinen naechsten Schritt.
     * Fertige und abgebrochene Laeufe sind Vergangenheit; sie anzubieten hiesse,
     * jemandem einen Ausstieg aus etwas anzubieten, das ohnehin vorbei ist.
     *
     * Ausgestiegene Serien kommen mit, als `opted_out => true`. Ohne sie waere
     * die Seite nach dem Ausstieg leer, und der Mensch haette keinen Weg
     * zurueck.
     *
     * @return Collection<int,\stdClass&object{uuid:string,name:string,opted_out:bool}>
     */
    public function sequencesFor(string $subject): Collection
    {
        $subject = $this->key($subject);

        if ($subject === null) {
            return collect();
        }

        $laufend = AutomationRun::query()
            ->where('subject_key', $subject)
            ->where('status', AutomationRun::STATUS_WAITING)
            ->pluck('automation_uuid')
            ->unique();

        $ausgestiegen = collect($this->forSubject($subject));

        $uuids = $laufend->merge($ausgestiegen)->unique()->values();

        if ($uuids->isEmpty()) {
            return collect();
        }

        return Automation::query()
            ->whereIn('uuid', $uuids)
            ->get(['uuid', 'name'])
            ->map(fn (Automation $a) => (object) [
                'uuid' => $a->uuid,
                'name' => $a->name,
                'opted_out' => $ausgestiegen->contains($a->uuid),
            ])
            ->values();
    }

    protected function query(string $automationUuid, string $subject)
    {
        return AutomationOptOut::query()
            ->where('automation_uuid', $automationUuid)
            ->where('subject_key', $subject);
    }

    /** Wie EnrollmentGate::clean() — dieselbe Person muss derselbe Schluessel sein. */
    protected function key(string $value): ?string
    {
        $value = mb_strtolower(trim($value));

        return $value === '' ? null : $value;
    }
}
