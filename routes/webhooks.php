<?php

use Goldnead\StatamicAutomations\Http\Controllers\Web\CalComWebhookController;
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
 */

Route::post(
    config('automations.integrations.cal_com.path', 'cal-com'),
    CalComWebhookController::class
)->name('automations.webhooks.cal_com');
