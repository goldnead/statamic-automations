<?php

namespace Goldnead\StatamicAutomations\Engine;

use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends an alert when an automation run fails. Channels and recipients
 * are config-driven; alerts are throttled per automation so a flapping
 * automation doesn't flood the inbox.
 */
class FailureAlerter
{
    public function notify(AutomationRun $run, ?string $error): void
    {
        $config = config('automations.alerts', []);

        if (! ($config['enabled'] ?? true)) {
            return;
        }

        $throttle = max(0, (int) ($config['throttle_minutes'] ?? 15));
        $key = "automations:alert:{$run->automation_id}";
        if ($throttle > 0 && Cache::has($key)) {
            return;
        }
        if ($throttle > 0) {
            Cache::put($key, true, now()->addMinutes($throttle));
        }

        $channels = (array) ($config['channels'] ?? ['log']);
        $message = sprintf(
            'Automation #%s run #%s failed: %s',
            $run->automation_id,
            $run->id,
            $error ?? 'unknown error',
        );

        if (in_array('log', $channels, true)) {
            Log::channel(config('logging.default'))->error($message, ['automation' => true]);
        }

        $to = $config['mail_to'] ?? null;
        if (in_array('mail', $channels, true) && ! empty($to)) {
            try {
                Mail::raw($message, function ($mail) use ($to) {
                    $mail->to($to)->subject('Statamic Automations — run failed');
                });
            } catch (\Throwable $e) {
                Log::warning('Automations failure alert email could not be sent: '.$e->getMessage());
            }
        }
    }
}
