<?php

namespace Goldnead\StatamicAutomations\Policies;

use Goldnead\StatamicAutomations\Models\Automation;

class AutomationPolicy
{
    public function viewAny($user): bool
    {
        return $this->can($user, 'view automations');
    }

    public function view($user, Automation $automation): bool
    {
        return $this->can($user, 'view automations');
    }

    public function create($user): bool
    {
        return $this->can($user, 'create automations');
    }

    public function update($user, Automation $automation): bool
    {
        return $this->can($user, 'edit automations');
    }

    public function delete($user, Automation $automation): bool
    {
        return $this->can($user, 'delete automations');
    }

    public function enable($user, Automation $automation): bool
    {
        return $this->can($user, 'enable automations');
    }

    public function test($user, Automation $automation): bool
    {
        return $this->can($user, 'run automation tests');
    }

    public function viewRuns($user): bool
    {
        return $this->can($user, 'view automation runs');
    }

    public function retryRuns($user): bool
    {
        return $this->can($user, 'retry automation runs');
    }

    /**
     * Lightweight permission check that works with or without Statamic
     * users (so unit tests do not require a full Statamic boot).
     */
    protected function can($user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'can')) {
            return (bool) $user->can($permission);
        }

        return true;
    }
}
