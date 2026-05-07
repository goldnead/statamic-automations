<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    /**
     * Lightweight permission gate that works with Statamic's permission
     * system as well as a vanilla Laravel auth user.
     */
    protected function authorizeAction(string $permission): void
    {
        $user = auth()->user();

        if ($user === null) {
            throw new AuthorizationException("Not authenticated.");
        }

        if (method_exists($user, 'can') && ! $user->can($permission)) {
            throw new AuthorizationException("Permission '{$permission}' is required.");
        }
    }
}
