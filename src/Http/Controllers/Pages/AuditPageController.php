<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Goldnead\StatamicAutomations\Models\AutomationAuditLog;
use Goldnead\StatamicAutomations\Support\Setup;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;

/**
 * Read-only audit log: who changed which automation and when.
 */
class AuditPageController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAction('view automations');

        // The log, and the flows it names: the eager load below is an Eloquent
        // relation onto `automations` whatever driver holds the definitions.
        if ($setup = Setup::guard(
            __('statamic-automations::automations.nav.audit'),
            'automation_audit_logs',
            'automations',
        )) {
            return $setup;
        }

        $logs = AutomationAuditLog::query()
            ->with('automation:id,name,handle')
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (AutomationAuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'automation' => $log->automation?->name
                    ?? ($log->meta['name'] ?? '—'),
                'user_label' => $log->user_label ?? '—',
                'created_at' => optional($log->created_at)->toIso8601String(),
            ])
            ->values();

        return Inertia::render('statamic-automations::Audit/Index', [
            'title' => __('Audit log'),
            'logs' => $logs,
            'columns' => collect([
                Column::make('action')->label(__('Action')),
                Column::make('automation')->label(__('Automation')),
                Column::make('user_label')->label(__('User')),
                Column::make('created_at')->label(__('When')),
            ])->map->toArray()->all(),
            'automationsUrl' => cp_route('statamic-automations.automations.index'),
        ]);
    }
}
