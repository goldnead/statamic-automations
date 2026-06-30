<?php

namespace Goldnead\StatamicAutomations\Support;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationAuditLog;

/**
 * Records who changed what on an automation. Entries are append-only and
 * surfaced in the CP audit screen. Recording is best-effort: a logging
 * failure must never break the user's actual operation.
 */
class AuditLogger
{
    public function record(string $action, ?Automation $automation = null, array $meta = []): void
    {
        try {
            $user = auth()->user();

            AutomationAuditLog::create([
                'automation_id' => $automation?->id,
                'action' => $action,
                'user_id' => $user?->id,
                'user_label' => $user?->email ?? $user?->name ?? null,
                'meta' => $meta ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Auditing is best-effort — never disrupt the primary action.
        }
    }
}
