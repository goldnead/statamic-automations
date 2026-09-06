<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;

/**
 * Overview dashboard: KPIs, recent failures, a 14-day run trend and which
 * sister addons were detected.
 */
class DashboardPageController extends Controller
{
    public function index(Request $request, IntegrationDetector $detector)
    {
        $this->authorizeAction('view automations');

        $since = now()->subDays(30);

        $runs = AutomationRun::query()->where('created_at', '>=', $since);
        $total = (clone $runs)->count();
        $succeeded = (clone $runs)->where('status', AutomationRun::STATUS_SUCCESS)->count();
        $failed = (clone $runs)->where('status', AutomationRun::STATUS_FAILED)->count();

        $recentFailures = AutomationRun::query()
            ->where('status', AutomationRun::STATUS_FAILED)
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (AutomationRun $r) => [
                'id' => $r->id,
                'automation_id' => $r->automation_id,
                'trigger_type' => $r->trigger_type,
                'error_message' => $r->error_message,
                'failed_at' => optional($r->finished_at ?? $r->created_at)->toIso8601String(),
                'show_url' => cp_route('statamic-automations.runs.show', $r->id),
            ])
            ->values();

        return Inertia::render('statamic-automations::Dashboard', [
            'title' => __('Automations'),
            'stats' => [
                'automations' => app(AutomationRepository::class)->count(),
                'enabled' => app(AutomationRepository::class)->enabledCount(),
                'runs_30d' => $total,
                'success_rate' => $total > 0 ? (int) round(($succeeded / $total) * 100) : null,
                'failed_30d' => $failed,
            ],
            'trend' => $this->trend(),
            'recentFailures' => $recentFailures,
            'failureColumns' => collect([
                Column::make('trigger_type')->label(__('Trigger')),
                Column::make('error_message')->label(__('Error')),
                Column::make('failed_at')->label(__('Failed at')),
            ])->map->toArray()->all(),
            'createUrl' => cp_route('statamic-automations.automations.create'),
            'automationsUrl' => cp_route('statamic-automations.automations.index'),
            'runsUrl' => cp_route('statamic-automations.runs.index'),
            'canCreate' => $this->userCan('create automations'),
            // A detection, not a setting: whether a sister addon is installed
            // is composer's answer, and a control for it would be a switch that
            // does nothing. It sat on the old settings screen, which was the
            // only place a human could see it; that screen moved into
            // brand-context on 2026-09-06 and the shared layer takes editable
            // settings only, by contract. So the read-only panel lands here
            // rather than being lost in the move.
            'integrations' => $detector->snapshot(),
        ]);
    }

    /**
     * Per-day run counts for the last 14 days, split by success/failure.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function trend(): array
    {
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $days[$date] = ['date' => $date, 'success' => 0, 'failed' => 0, 'total' => 0];
        }

        AutomationRun::query()
            ->where('created_at', '>=', now()->subDays(14)->startOfDay())
            ->get(['status', 'created_at'])
            ->each(function (AutomationRun $r) use (&$days) {
                $date = $r->created_at?->toDateString();
                if (! isset($days[$date])) {
                    return;
                }
                $days[$date]['total']++;
                if ($r->status === AutomationRun::STATUS_SUCCESS) {
                    $days[$date]['success']++;
                } elseif ($r->status === AutomationRun::STATUS_FAILED) {
                    $days[$date]['failed']++;
                }
            });

        return array_values($days);
    }

    protected function userCan(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'can') && $user->can($permission);
    }
}
