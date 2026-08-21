<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Web;

use Goldnead\StatamicAutomations\Integrations\Marketing\MarketingAdapter;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationOptOut;
use Goldnead\StatamicAutomations\Services\SequenceOptOut;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Aus einer Serie aussteigen — ohne alles andere abzubestellen.
 *
 * Was diese Seite von der Abmeldung unterscheidet: sie fasst die
 * Listen-Anmeldung nicht an. Wer die Willkommensstrecke nicht zu Ende lesen
 * will, aber weiter den Newsletter bekommen moechte, hatte bis hierher nur die
 * Wahl zwischen beidem und nichts.
 *
 * Der Token identifiziert die Person, die UUID die Serie. Beides steckt im
 * Link, den die Mail traegt; eine E-Mail-Adresse in der URL waere eine
 * Einladung, Fremde auszutragen.
 */
class SequenceOptOutController
{
    public function __construct(
        protected SequenceOptOut $optOuts,
        protected MarketingAdapter $marketing,
    ) {}

    public function show(string $token, string $sequence): View
    {
        [$email, $flow] = $this->resolve($token, $sequence);

        return view('statamic-automations::sequence-opt-out', [
            'token' => $token,
            'automation' => $flow,
            'email' => $email,
            'optedOut' => $this->optOuts->has($flow->uuid, $email),
            'done' => false,
        ]);
    }

    public function store(Request $request, string $token, string $sequence): RedirectResponse
    {
        [$email, $flow] = $this->resolve($token, $sequence);

        $this->optOuts->add($flow->uuid, $email, AutomationOptOut::SOURCE_MAIL_LINK);

        return redirect()
            ->route('automations.sequence.opt-out', ['token' => $token, 'sequence' => $flow->uuid])
            ->with('automations.sequence.status', 'opted_out');
    }

    public function destroy(Request $request, string $token, string $sequence): RedirectResponse
    {
        [$email, $flow] = $this->resolve($token, $sequence);

        $this->optOuts->remove($flow->uuid, $email);

        return redirect()
            ->route('automations.sequence.opt-out', ['token' => $token, 'sequence' => $flow->uuid])
            ->with('automations.sequence.status', 'opted_in');
    }

    /**
     * Token und Serie aufloesen, oder 404.
     *
     * Ein 404 fuer beide Faelle — unbekannter Token, unbekannte Serie — und
     * bewusst ohne Unterscheidung: eine Seite, die "diesen Token gibt es, aber
     * die Serie nicht" sagt, beantwortet einem Fremden die Frage, ob ein Token
     * echt ist.
     *
     * @return array{0: string, 1: Automation}
     */
    protected function resolve(string $token, string $sequence): array
    {
        $email = $this->marketing->emailForToken($token);

        abort_if($email === null || $email === '', 404);

        $flow = Automation::query()
            ->where('uuid', $sequence)
            ->first();

        abort_if($flow === null, 404);

        return [$email, $flow];
    }
}
