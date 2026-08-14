<?php

namespace Goldnead\StatamicAutomations\Sequence;

use Goldnead\StatamicAutomations\Http\Controllers\Pages\RulesPageController;
use Goldnead\StatamicAutomations\Models\Automation;

/**
 * "Is any automation shaped like a rule?" — one trigger and one mail.
 *
 * Its own class because the Control Panel navigation asks it while building the
 * sidebar, on every request, including the ones that never open the rules
 * screen. {@see RulesPageController}
 * answers the same question by loading every automation with its nodes and
 * counting in PHP, which is right for a page that then renders each row and
 * wrong for a menu.
 *
 * The shortcut is one `exists()` with a `whereIn` over the handles that count as
 * a mail. That is only correct while {@see MailSteps::handles()} agrees with
 * {@see MailSteps::isMailHandle()} — which is why both live on the same class
 * and a test crosses them.
 */
class MailRules
{
    public function __construct(protected MailSteps $mails) {}

    /**
     * Any failure means "no". This is consulted while the navigation renders,
     * and a fresh install before `migrate` (no table) or an unreachable database
     * must not take the whole sidebar down with it.
     */
    public function any(): bool
    {
        try {
            $handles = $this->mails->handles();

            if ($handles === []) {
                return false;
            }

            return Automation::query()
                ->whereHas('nodes', fn ($query) => $query->whereIn('type', $handles), '=', 1)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
