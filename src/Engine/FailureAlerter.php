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
        $key = 'automations:alert:'.$this->throttleSubject($run);
        if ($throttle > 0 && Cache::has($key)) {
            return;
        }
        if ($throttle > 0) {
            Cache::put($key, true, now()->addMinutes($throttle));
        }

        $channels = (array) ($config['channels'] ?? ['log']);
        $message = sprintf(
            'Automation %s run #%s failed: %s',
            $this->automationLabel($run),
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

    /**
     * What the throttle window is scoped to.
     *
     * `automation_id` alone is wrong for a run whose automation has been
     * deleted: the foreign key is ON DELETE SET NULL, so every orphaned run in
     * the installation collapsed onto the single key `automations:alert:` and
     * the first one silenced all the others for the whole window. The uuid is
     * a plain column and survives the delete, so it identifies the automation
     * even after its row is gone; the run id is the last resort for a run that
     * predates the uuid column.
     */
    protected function throttleSubject(AutomationRun $run): string
    {
        if ($run->automation_id !== null) {
            return (string) $run->automation_id;
        }

        if (! empty($run->automation_uuid)) {
            return 'uuid-'.$run->automation_uuid;
        }

        return 'run-'.$run->id;
    }

    /**
     * How the automation is named in the alert. A deleted automation has no
     * id left to print — "Automation # run #12 failed" told nobody anything.
     */
    protected function automationLabel(AutomationRun $run): string
    {
        if ($run->automation_id !== null) {
            return '#'.$run->automation_id;
        }

        if (! empty($run->automation_uuid)) {
            return $run->automation_uuid.' (deleted)';
        }

        return '(unknown)';
    }
}
