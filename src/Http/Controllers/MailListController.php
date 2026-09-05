<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Sequence\ChainEditor;
use Goldnead\StatamicAutomations\Sequence\LinearityRule;
use Goldnead\StatamicAutomations\Sequence\MailListProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The mail list of one automation: what it sends, in what order, how far apart.
 *
 * Reading is always allowed. Writing is allowed only while
 * {@see LinearityRule} says the graph is
 * a straight line, and a write against a branched automation answers 422 with
 * the reasons rather than doing something approximate.
 *
 * The three write endpoints snapshot a version first, like every other write
 * path in this addon: an edit made from a list is still an edit to the graph
 * and has to be revertable from the same history.
 */
class MailListController extends Controller
{
    public function __construct(
        protected MailListProjection $projection,
        protected ChainEditor $editor,
    ) {}

    public function show(Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json($this->projection->forAutomation($automationFlow));
    }

    public function reorder(Request $request, Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('edit automations');

        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string'],
        ]);

        return $this->write($automationFlow, 'Reordered the mail list', fn () => $this->editor->reorder($automationFlow, $data['order']));
    }

    public function store(Request $request, Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('edit automations');

        $data = $request->validate([
            'type' => ['required', 'string'],
            'label' => ['nullable', 'string'],
            'config' => ['nullable', 'array'],
            'after' => ['nullable', 'string'],
            'delay' => ['nullable', 'array'],
            'delay.amount' => ['nullable', 'integer', 'min:0'],
            'delay.unit' => ['nullable', 'string', 'in:minutes,hours,days'],
        ]);

        return $this->write($automationFlow, 'Added a mail from the list', fn () => $this->editor->insert($automationFlow, $data));
    }

    public function destroy(Automation $automationFlow, string $nodeKey): JsonResponse
    {
        $this->authorizeAction('edit automations');

        return $this->write($automationFlow, 'Removed a mail from the list', fn () => $this->editor->remove($automationFlow, $nodeKey));
    }

    /**
     * The actions a selection of mails may perform.
     *
     * This is the `POST {url}/list` half of Statamic's action contract — the
     * Control Panel's `Listing` asks it whenever a checkbox is ticked or a row
     * menu is opened, and hides the checkbox column entirely when no
     * `action-url` is given. It is answered by hand rather than through
     * `Statamic\Actions\Action`, because that base class is built around
     * Statamic items (entries, terms, users) that can be looked up from a
     * repository, and a mail is a node inside one automation's graph.
     *
     * An empty answer is a real answer: a branched flow has no editable list,
     * and offering "delete" against it would produce a toolbar whose only
     * button always fails.
     */
    public function actionList(Request $request, Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('view automations');

        $selections = $this->selections($request);

        // The projection's own verdict, not a second reading of the rule: the
        // list on screen is editable exactly when it says so, and two callers
        // asking the same question in two ways is how they come to disagree.
        $editable = (bool) ($this->projection->forAutomation($automationFlow)['editable'] ?? false);

        if ($selections === [] || ! $editable || ! $this->canEdit()) {
            return response()->json([]);
        }

        return response()->json([[
            'handle' => 'delete',
            'title' => __('Delete mail'),
            'icon' => 'trash',
            'component' => null,
            'runnable' => true,
            'confirm' => true,
            'dangerous' => true,
            'buttonText' => __('Delete mail'),
            // Two source strings rather than one with "(s)" in it: Statamic's
            // action runner passes this through `__n`, which chooses between
            // the halves of a `singular|plural` string — but only against its
            // own JS dictionary, and this one is already translated by the time
            // it gets there. So the choice is made here, where the count is.
            'confirmationText' => count($selections) === 1
                ? __('Delete this mail?')
                : __('Delete :count mails?', ['count' => count($selections)]),
            'warningText' => __('The waiting time in front of each one goes too. Anything else in that gap is kept and moves to the next mail.'),
            'dirtyWarningText' => null,
            'bypassesDirtyWarning' => false,
            'requiresElevatedSession' => false,
            'fields' => [],
            'values' => [],
            'meta' => [],
            'context' => [],
        ]]);
    }

    /**
     * Run one action against a selection of mails.
     *
     * One snapshot for the whole selection, not one per mail: an editor who
     * deletes three mails and then reverts means the three, and three
     * consecutive versions would make them undo it three times.
     */
    public function runAction(Request $request, Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('edit automations');

        $data = $request->validate([
            'action' => ['required', 'string', 'in:delete'],
            'selections' => ['required', 'array', 'min:1'],
            'selections.*' => ['required', 'string'],
        ]);

        $keys = array_values(array_unique($data['selections']));

        return $this->write(
            $automationFlow,
            'Removed mails from the list',
            function () use ($automationFlow, $keys) {
                $automation = $automationFlow;

                foreach ($keys as $key) {
                    $automation = $this->editor->remove($automation, $key);
                }

                return $automation;
            },
            count($keys) === 1
                ? __('The mail was removed.')
                : __(':count mails were removed.', ['count' => count($keys)]),
        );
    }

    /**
     * @return list<string>
     */
    protected function selections(Request $request): array
    {
        /** @var array<int, mixed> $raw */
        $raw = $request->input('selections', []);

        return array_values(array_filter(
            array_map(fn ($value) => is_string($value) ? $value : null, is_array($raw) ? $raw : []),
        ));
    }

    protected function canEdit(): bool
    {
        $user = auth()->user();

        return $user !== null && (! method_exists($user, 'can') || $user->can('edit automations'));
    }

    /**
     * Snapshot, apply, and answer with the list as it now is.
     *
     * The refusal path is a 422 carrying the rule's own reasons, not a bare
     * "not allowed": an editor who is told *why* the list is locked can decide
     * whether to unbranch the flow or to go to the canvas, and one who is told
     * nothing files a bug.
     *
     * @param  callable(): Automation  $apply
     * @param  string|null  $success  When given, the answer is `{message}` for
     *                                Statamic's action runner, which shows it
     *                                as a toast and then refreshes the listing.
     *                                Without it the answer is the list itself,
     *                                which is what the panel's own writes read.
     */
    protected function write(Automation $automation, string $message, callable $apply, ?string $success = null): JsonResponse
    {
        app(VersionManager::class)->snapshot($automation, $message);

        try {
            $automation = $apply();
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'list' => $this->projection->forAutomation($automation),
            ], 422);
        }

        if ($success !== null) {
            return response()->json(['message' => $success]);
        }

        return response()->json($this->projection->forAutomation($automation));
    }
}
