<?php

namespace Goldnead\StatamicAutomations\Console\Commands;

use Cron\CronExpression;
use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates every enabled automation with a `scheduled` trigger and
 * dispatches a run for those due at the current minute. Registered on
 * Laravel's scheduler (everyMinute) by the ServiceProvider.
 */
class RunScheduledAutomations extends Command
{
    use RunsForEachBrand;

    protected $signature = 'automations:run-scheduled {--brand= : Restrict to one brand handle or id}';

    protected $description = 'Dispatch automations whose schedule is due now.';

    public function handle(WorkflowRunner $runner, AutomationRepository $repository): int
    {
        // A scheduled run has no session and therefore no brand; without
        // this the fail-closed scope hides every row and the command
        // reports success while doing nothing at all.
        return $this->forEachBrand(fn () => $this->handleForBrand($runner, $repository));
    }

    protected function handleForBrand(WorkflowRunner $runner, AutomationRepository $repository): int
    {
        $automations = $repository->enabled()
            ->filter(fn ($automation) => $automation->nodes->contains(fn ($n) => $n->type === 'scheduled'));

        $dispatched = 0;

        foreach ($automations as $automation) {
            $node = $automation->nodes->first(fn ($n) => $n->type === 'scheduled');
            if ($node === null) {
                continue;
            }

            $config = $node->config ?? [];
            $tz = ($config['timezone'] ?? '') ?: config('app.timezone', 'UTC');
            $now = Carbon::now($tz);

            if (! $this->isDue($config, $now, $automation)) {
                continue;
            }

            $context = AutomationContext::make([
                'scheduled' => ['at' => $now->toIso8601String(), 'frequency' => $config['frequency'] ?? null],
            ]);
            $run = $runner->createRun($automation, $context, $node);
            RunAutomation::dispatch($run->id, $context->all(), false);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} scheduled automation(s).");

        return self::SUCCESS;
    }

    protected function isDue(array $config, Carbon $now, ?object $automation = null): bool
    {
        $expression = $this->cronExpression($config);
        if ($expression === null) {
            $this->warnInvalidSchedule($automation, $config, 'no usable cron expression or unknown frequency');

            return false;
        }

        try {
            return (new CronExpression($expression))->isDue($now->toDateTime());
        } catch (\Throwable $e) {
            $this->warnInvalidSchedule($automation, $config, $e->getMessage());

            return false;
        }
    }

    /**
     * A schedule that can never fire used to fail without a trace. The
     * command runs every minute, so the warning is throttled to once an
     * hour per automation.
     */
    protected function warnInvalidSchedule(?object $automation, array $config, string $reason): void
    {
        $id = $automation->id ?? null;

        // The throttle must never take the command down: an unreachable cache
        // store would otherwise stop every valid schedule as well, every minute.
        try {
            $key = 'automations:invalid-schedule:'.($automation->uuid ?? $id ?? 'unknown');

            if (! Cache::add($key, true, now()->addHour())) {
                return;
            }
        } catch (\Throwable) {
            // Warn without a throttle.
        }

        Log::warning('[automations] schedule never fires: '.$reason, [
            'automation_id' => $id,
            'frequency' => $config['frequency'] ?? null,
            'cron' => $config['cron'] ?? null,
        ]);
    }

    protected function cronExpression(array $config): ?string
    {
        $frequency = $config['frequency'] ?? 'daily';

        if ($frequency === 'cron') {
            return ($config['cron'] ?? '') ?: null;
        }

        [$hour, $minute] = $this->parseTime($config['time'] ?? '09:00');

        return match ($frequency) {
            'hourly' => "{$minute} * * * *",
            'daily' => "{$minute} {$hour} * * *",
            'weekly' => "{$minute} {$hour} * * ".((string) ($config['day'] ?? '1')),
            'monthly' => "{$minute} {$hour} ".((string) ($config['day'] ?? '1')).' * *',
            default => null,
        };
    }

    /**
     * @return array{0:int,1:int}
     */
    protected function parseTime(string $time): array
    {
        $parts = explode(':', $time);
        $hour = isset($parts[0]) ? max(0, min(23, (int) $parts[0])) : 9;
        $minute = isset($parts[1]) ? max(0, min(59, (int) $parts[1])) : 0;

        return [$hour, $minute];
    }
}
