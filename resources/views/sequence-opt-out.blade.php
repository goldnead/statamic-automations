{{--
    Aus einer Serie aussteigen, ohne alles abzubestellen.

    Bewusst eine eigene Seite und nicht Teil der Abmeldung: die beiden
    Entscheidungen sind verschieden, und wer nur die Willkommensstrecke
    loswerden will, soll dabei nicht versehentlich den Newsletter verlieren.
    Der Satz unter dem Knopf sagt genau das, damit niemand raten muss.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('statamic-automations::sequence.title') }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Arial, sans-serif; line-height: 1.55;
               color: #1c1917; background: #fafaf9; margin: 0; padding: 48px 20px; }
        .box { max-width: 520px; margin: 0 auto; background: #fff; border: 1px solid #e7e5e4;
               border-radius: 10px; padding: 32px; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { margin: 0 0 16px; color: #44403c; }
        .quiet { color: #78716c; font-size: 14px; }
        .btn { display: inline-block; border: 0; border-radius: 8px; padding: 12px 20px;
               font-size: 15px; font-weight: 600; cursor: pointer; background: #1c1917; color: #fff; }
        .btn.secondary { background: #fff; color: #1c1917; border: 1px solid #d6d3d1; }
        .ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534;
              padding: 12px 16px; border-radius: 8px; margin: 0 0 20px; }
    </style>
</head>
<body>
<div class="box">
    @if (session('automations.sequence.status') === 'opted_out')
        <p class="ok">{{ __('statamic-automations::sequence.done_out') }}</p>
    @elseif (session('automations.sequence.status') === 'opted_in')
        <p class="ok">{{ __('statamic-automations::sequence.done_in') }}</p>
    @endif

    <h1>{{ $automation->name }}</h1>

    @if ($optedOut)
        <p>{{ __('statamic-automations::sequence.already_out', ['email' => $email]) }}</p>

        <form method="POST" action="{{ route('automations.sequence.opt-in.post', ['token' => $token, 'sequence' => $automation->uuid]) }}">
            @csrf
            <button type="submit" class="btn secondary">{{ __('statamic-automations::sequence.opt_in_button') }}</button>
        </form>
    @else
        <p>{{ __('statamic-automations::sequence.body', ['email' => $email]) }}</p>

        {{--
            Knopf statt Link: alles, was den Link ohne Menschen dahinter
            oeffnet — SafeLinks, der Scanner eines Mail-Gateways, die Vorschau
            eines Chatprogramms — bleibt hier stehen, weil keines davon ein
            Formular abschickt.
        --}}
        <form method="POST" action="{{ route('automations.sequence.opt-out.post', ['token' => $token, 'sequence' => $automation->uuid]) }}">
            @csrf
            <button type="submit" class="btn">{{ __('statamic-automations::sequence.opt_out_button') }}</button>
        </form>
    @endif

    <p class="quiet" style="margin-top:20px;">{{ __('statamic-automations::sequence.scope_note') }}</p>
</div>
</body>
</html>
