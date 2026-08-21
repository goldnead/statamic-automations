<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationOptOut;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Services\SequenceOptOut;
use Goldnead\StatamicAutomations\Tests\TestCase;

/**
 * Aus einer Serie aussteigen, ohne alles abzubestellen.
 *
 * Die Tests hier halten die Zusagen fest, auf die sich jemand verlaesst, der
 * auf "Diese Serie beenden" klickt: dass es wirkt, dass es nur diese eine
 * Serie betrifft, dass ein zweiter Klick nichts kaputt macht und dass der
 * Weg zurueck offen bleibt.
 */
class SequenceOptOutTest extends TestCase
{
    private function automation(string $name = 'Willkommensstrecke'): Automation
    {
        return Automation::create([
            'name' => $name,
            'handle' => \Illuminate\Support\Str::slug($name),
            'enabled' => true,
        ]);
    }

    private function service(): SequenceOptOut
    {
        return app(SequenceOptOut::class);
    }

    public function test_ein_ausstieg_wird_gemerkt(): void
    {
        $flow = $this->automation();

        $this->assertFalse($this->service()->has($flow->uuid, 'clara@example.com'));

        $this->service()->add($flow->uuid, 'clara@example.com');

        $this->assertTrue($this->service()->has($flow->uuid, 'clara@example.com'));
    }

    public function test_die_schreibweise_der_adresse_ist_egal(): void
    {
        // Sonst haengt der Ausstieg daran, wie jemand sich angemeldet hat.
        $flow = $this->automation();

        $this->service()->add($flow->uuid, '  Clara@Example.COM ');

        $this->assertTrue($this->service()->has($flow->uuid, 'clara@example.com'));
    }

    public function test_ein_zweiter_klick_legt_keine_zweite_zeile_an(): void
    {
        $flow = $this->automation();

        $this->service()->add($flow->uuid, 'clara@example.com');
        $this->service()->add($flow->uuid, 'clara@example.com');

        $this->assertSame(1, AutomationOptOut::query()->count());
    }

    public function test_der_ausstieg_gilt_nur_fuer_diese_eine_serie(): void
    {
        // Der ganze Punkt der Uebung: wer die Willkommensstrecke beendet, soll
        // die Kurs-Serie behalten — und den Newsletter sowieso.
        $willkommen = $this->automation('Willkommensstrecke');
        $kurs = $this->automation('Kurs-Serie');

        $this->service()->add($willkommen->uuid, 'clara@example.com');

        $this->assertTrue($this->service()->has($willkommen->uuid, 'clara@example.com'));
        $this->assertFalse($this->service()->has($kurs->uuid, 'clara@example.com'));
    }

    public function test_der_ausstieg_gilt_nur_fuer_diese_eine_person(): void
    {
        $flow = $this->automation();

        $this->service()->add($flow->uuid, 'clara@example.com');

        $this->assertFalse($this->service()->has($flow->uuid, 'bernd@example.com'));
    }

    public function test_der_weg_zurueck_ist_offen(): void
    {
        $flow = $this->automation();

        $this->service()->add($flow->uuid, 'clara@example.com');
        $this->assertTrue($this->service()->remove($flow->uuid, 'clara@example.com'));

        $this->assertFalse($this->service()->has($flow->uuid, 'clara@example.com'));

        // Geloescht, nicht auf inaktiv gesetzt: eine liegengebliebene Zeile
        // wird beim naechsten Query uebersehen.
        $this->assertSame(0, AutomationOptOut::query()->count());
    }

    public function test_die_seite_zeigt_laufende_und_verlassene_serien(): void
    {
        $laufend = $this->automation('Noch dabei');
        $verlassen = $this->automation('Beendet');

        AutomationRun::create([
            'automation_id' => $laufend->id,
            'automation_uuid' => $laufend->uuid,
            'subject_key' => 'clara@example.com',
            'status' => AutomationRun::STATUS_WAITING,
            'context' => [],
        ]);

        $this->service()->add($verlassen->uuid, 'clara@example.com');

        $zeilen = $this->service()->sequencesFor('clara@example.com')->keyBy('uuid');

        $this->assertCount(2, $zeilen);
        $this->assertFalse($zeilen[$laufend->uuid]->opted_out);

        // Die verlassene bleibt sichtbar — sonst gaebe es keinen Weg zurueck.
        $this->assertTrue($zeilen[$verlassen->uuid]->opted_out);
    }

    public function test_abgeschlossene_laeufe_zaehlen_nicht_als_laufende_serie(): void
    {
        // Jemandem den Ausstieg aus etwas anzubieten, das vorbei ist, waere
        // eine Auswahl ohne Wirkung.
        $flow = $this->automation();

        AutomationRun::create([
            'automation_id' => $flow->id,
            'automation_uuid' => $flow->uuid,
            'subject_key' => 'clara@example.com',
            'status' => AutomationRun::STATUS_SUCCESS,
            'context' => [],
        ]);

        $this->assertCount(0, $this->service()->sequencesFor('clara@example.com'));
    }

    public function test_eine_leere_adresse_erzeugt_keinen_ausstieg(): void
    {
        $flow = $this->automation();

        $this->assertNull($this->service()->add($flow->uuid, '   '));
        $this->assertSame(0, AutomationOptOut::query()->count());
    }
}
