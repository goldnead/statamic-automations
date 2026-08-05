<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Sequence\MailSteps;
use Goldnead\StatamicAutomations\Sequence\RuleFields;
use Goldnead\StatamicAutomations\Sequence\RuleProjection;
use Goldnead\StatamicAutomations\Sequence\RuleShape;
use Inertia\Inertia;

/**
 * The rules screen: every automation that is one trigger and one mail.
 *
 * **Which automations appear.** Those with exactly one mail node. An automation
 * that sends nothing is not a rule and has nothing to read here; one that sends
 * three is a sequence, and the mail list on its own page is the screen for
 * that — listing it here as a failed rule would send its editor to the wrong
 * surface. What is left are rules and near-misses: the two-node case, and the
 * ones with a delay or a branch in between, which show why they cannot be
 * edited here and link to the canvas ({@see RuleShape}).
 *
 * The row itself comes from {@see RuleProjection}; this controller only adds
 * what a projection has no business knowing: where to write it and where the
 * canvas is.
 */
class RulesPageController extends Controller
{
    public function index(RuleProjection $projection, MailSteps $mails, RuleFields $fields)
    {
        $this->authorizeAction('view automations');

        // Resolved once per node type rather than once per row: a template
        // field's options are read out of the entries collection behind it, and
        // twenty rules on one node type would be twenty identical queries.
        $templateOptions = [];

        $rules = Automation::query()
            ->with(['nodes', 'edges'])
            ->orderBy('name')
            ->get()
            ->map(function (Automation $automation) use ($projection, $mails, $fields, &$templateOptions) {
                $mailNodes = $automation->nodes->filter(fn (AutomationNode $node) => $mails->isMail($node));

                if ($mailNodes->count() !== 1) {
                    return null;
                }

                $type = (string) $mailNodes->first()->type;

                return [
                    ...$projection->forAutomation($automation),
                    'edit_url' => cp_route('statamic-automations.automations.edit', $automation),
                    'update_url' => cp_route('statamic-automations.api.automations.rule.update', $automation),
                    'template_options' => $templateOptions[$type] ??= $fields->options($type, RuleFields::TEMPLATE),
                ];
            })
            ->filter()
            ->values();

        return Inertia::render('statamic-automations::Rules/Index', [
            'title' => __('Mail rules'),
            'rules' => $rules,
            'automationsUrl' => cp_route('statamic-automations.automations.index'),
            'canEdit' => $this->userCan('edit automations'),
        ]);
    }

    protected function userCan(string $permission): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'can')) {
            return (bool) $user->can($permission);
        }

        return true;
    }
}
