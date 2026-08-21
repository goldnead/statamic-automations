<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wer aus EINER Serie aussteigt, ohne alle Werbemails abzubestellen.
 *
 * Bis hierher gab es nur ganz oder gar nicht: die Abmeldung von einer Liste
 * stoppt zwar auch laufende Serien (der Sende-Knoten prueft vor jedem Schritt
 * und bricht ab, wenn jemand nicht mehr angemeldet ist), aber sie kostet
 * denjenigen eben auch den Newsletter. Wer eine fuenfteilige Willkommensstrecke
 * nicht zu Ende lesen will, sonst aber gerne weiter Post bekommt, hatte keine
 * Wahl — ausser der, die ihn ganz verliert.
 *
 * Eine Zeile hier heisst genau: "diese Person will von dieser Automation nichts
 * mehr". Nicht mehr und nicht weniger. Die Listen-Anmeldung bleibt unberuehrt.
 *
 * `subject_key` statt einer Kontakt-Kennung, weil das der Schluessel ist, mit
 * dem auch die Laeufe selbst gefuehrt werden (`automation_runs.subject_key`) —
 * bei Marketing-Serien die normalisierte E-Mail-Adresse. Ein Fremdschluessel
 * auf einen Kontakt waere enger, wuerde aber genau die Faelle ausschliessen,
 * in denen jemand aussteigen will, bevor ueberhaupt ein Kontakt existiert.
 *
 * Der eindeutige Schluessel ist (brand, automation, subject): ein zweiter Klick
 * auf denselben Link darf keine zweite Zeile erzeugen und keinen Fehler werfen.
 * Wieder-Eintritt ist ein Loeschen der Zeile, kein Statusfeld — ein Ausstieg,
 * der als `active = false` herumliegt, ist eine Einladung, ihn beim naechsten
 * Query zu uebersehen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('brand_id')->default(0)->index();

            // Die Automation, nicht der Lauf: der Ausstieg gilt auch fuer einen
            // spaeteren zweiten Durchlauf derselben Serie.
            $table->uuid('automation_uuid')->index();

            $table->string('subject_key')->index();

            $table->timestamp('opted_out_at');

            // Woher der Ausstieg kam: `mail_link`, `preference_center`, `cp`.
            // Steht im Zeitstrahl der Begruendung wegen — bei einer
            // Widerspruchsfrage ist "wer hat das ausgeloest" die erste Frage.
            $table->string('source', 32)->default('mail_link');

            $table->timestamps();

            $table->unique(['brand_id', 'automation_uuid', 'subject_key'], 'automation_opt_outs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_opt_outs');
    }
};
