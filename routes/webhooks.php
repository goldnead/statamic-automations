<?php

use Goldnead\StatamicAutomations\Http\Controllers\Web\CalComWebhookController;
use Goldnead\StatamicAutomations\Http\Controllers\Web\VocalFlowSessionPublishedController;
use Goldnead\StatamicAutomations\Http\Controllers\Web\VocalFlowWebhookController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * Eingehende Webhooks von Diensten ausserhalb von Statamic.
 *
 * Diese Datei wird nicht ueber Statamics `$routes['web']` eingebunden, sondern
 * vom ServiceProvider selbst, und zwar ohne Middleware-Gruppe. Das ist Absicht
 * und der Grund steht hier, damit es niemand aus Ordnungsliebe
 * "aufraeumt":
 *
 * Die `statamic.web`-Gruppe bringt Sitzung, CSRF-Token und den statischen
 * Cache mit. Ein Server, der einen Webhook schickt, hat kein CSRF-Token und
 * bekaeme 419, noch bevor der Controller die Signatur ueberhaupt sehen kann.
 * Eine Sitzung braucht er auch nicht, und der statische Cache hat an einem POST
 * ohnehin nichts zu suchen.
 *
 * Was an die Stelle der Middleware tritt, ist die Signaturpruefung im
 * Controller. Die ist fuer diesen Zweck die staerkere Schranke: sie prueft
 * nicht, ob jemand ein Formular unserer Seite offen hatte, sondern ob die Bytes
 * mit dem Secret des Webhooks signiert wurden.
 *
 * Die Middleware haengt je Dienst an der einzelnen Route und nicht an der
 * Gruppe. Sonst truege jeder neue Dienst die Einstellung des ersten: eine
 * Drosselung, die fuer cal.coms Termine passt, ist fuer VocalFlows Aufgaben
 * nicht automatisch die richtige, und ein Betrieb, der die eine hochsetzt, will
 * die andere nicht mitziehen.
 */

Route::post(
    config('automations.integrations.cal_com.path', 'cal-com'),
    CalComWebhookController::class
)
    ->middleware((array) config('automations.integrations.cal_com.middleware', []))
    ->name('automations.webhooks.cal_com');

/*
 * VocalFlow, zwei Tueren.
 *
 * Die erste nimmt die Ereignisse entgegen (HMAC ueber die kanonische Nutzlast),
 * die zweite die veroeffentlichte Session (Bearer-Token, zwei Felder). Warum
 * das zwei Routen und zwei Controller sind und nicht eine mit einer Weiche,
 * steht in VocalFlowSessionPublishedController.
 */

$vocalFlowMiddleware = (array) config('automations.integrations.vocalflow.middleware', []);
$vocalFlowPath = (string) config('automations.integrations.vocalflow.path', 'vocalflow');
$vocalFlowPublishedPath = (string) config('automations.integrations.vocalflow.published_path', 'vocalflow/session-published');

Route::post($vocalFlowPath, VocalFlowWebhookController::class)
    ->middleware($vocalFlowMiddleware)
    ->name('automations.webhooks.vocalflow');

/*
 * Nur wenn sich die beiden Pfade unterscheiden.
 *
 * Stehen sie auf demselben Wert — ein Tippfehler in zwei Umgebungsvariablen,
 * die einander aehnlich sehen — registriert Laravel zwei POST-Routen auf
 * derselben Adresse und die zweite gewinnt. Dann liefe **jedes** signierte
 * Ereignis in die Bearer-Tuer, bekaeme 401, und im Zustellprotokoll stuende
 * "nicht berechtigt" fuer einen Anschluss, dessen Secret voellig richtig
 * eingetragen ist. Das ist ein Fehler, den man Tage sucht.
 *
 * Die stille Aufloesung ist die richtige Richtung: der Ereignis-Kanal steht,
 * die zweite Tuer fehlt, und im Log steht warum. Eine Ausnahme zu werfen wuerde
 * die ganze Seite an einem falsch gesetzten Pfad aufhaengen.
 */
if ($vocalFlowPublishedPath !== $vocalFlowPath) {
    Route::post($vocalFlowPublishedPath, VocalFlowSessionPublishedController::class)
        ->middleware($vocalFlowMiddleware)
        ->name('automations.webhooks.vocalflow_session_published');
} else {
    Log::error('statamic-automations: `vocalflow.published_path` ist derselbe Pfad wie `vocalflow.path`. Die Route fuer die veroeffentlichte Session wurde nicht registriert.', [
        'path' => $vocalFlowPath,
    ]);
}
