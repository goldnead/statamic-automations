<?php

use Goldnead\BrandContext\Http\Middleware\SetBrandFromRouteValue;
use Goldnead\StatamicAutomations\Http\Controllers\Web\SequenceOptOutController;
use Goldnead\StatamicAutomations\Models\Automation;
use Illuminate\Support\Facades\Route;

/*
 * Oeffentliche Routen. Ohne Sitzung, geoeffnet von einem Mailprogramm oder dem
 * Browser eines Fremden.
 *
 * **Zwei Schritte, wie beim Double-Opt-in und aus demselben Grund.** Der
 * Link-Scanner eines Mailservers ruft jeden Link in einer Mail auf, bevor der
 * Mensch sie ueberhaupt sieht. Ein GET, das schon austraegt, wuerde Leute aus
 * Serien werfen, die nie geklickt haben. GET zeigt deshalb eine Seite mit
 * Knopf, erst der POST traegt aus.
 *
 * **Die Marke kommt aus der Automation.** Ohne sie ist unter Mehrmarken-Betrieb
 * keine Marke aktiv, der fail-closed-Scope verbirgt genau den Datensatz, auf
 * den der Link zeigt, und der Link laeuft ins 404. Eine Automations-UUID
 * adressiert genau einen Datensatz ueber alle Marken hinweg — das ist es, was
 * die Ableitung sicher macht und nicht zu einem Loch.
 *
 * Der Token im Pfad ist der dauerhafte Marketing-Token derselben Person, kein
 * eigener. Ein zweiter Tokenraum waere ein zweiter Ort, an dem ein Ausstieg
 * scheitern kann.
 */
$brandFromAutomation = SetBrandFromRouteValue::class.':'.Automation::class.',uuid,sequence';

Route::prefix(config('automations.routes.prefix', '!/automations'))->group(function () use ($brandFromAutomation) {
    Route::get('/serie/{token}/{sequence}', [SequenceOptOutController::class, 'show'])
        ->name('automations.sequence.opt-out')
        ->middleware($brandFromAutomation);

    Route::post('/serie/{token}/{sequence}', [SequenceOptOutController::class, 'store'])
        ->name('automations.sequence.opt-out.post')
        ->middleware($brandFromAutomation);

    // Zurueck in die Serie. Steht auf derselben Seite, damit ein
    // versehentlicher Ausstieg nicht endgueltig ist.
    Route::post('/serie/{token}/{sequence}/zurueck', [SequenceOptOutController::class, 'destroy'])
        ->name('automations.sequence.opt-in.post')
        ->middleware($brandFromAutomation);
});
