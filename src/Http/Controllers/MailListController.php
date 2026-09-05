<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Sequence\ChainEditor;
use Goldnead\StatamicAutomations\Sequence\LinearityRule;
use Goldnead\StatamicAutomations\Sequence\MailListProjection;
use Goldnead\StatamicAutomations\Sequence\MailSteps;
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
     * An empty answer is a real answer, and it is given for three reasons: a
     * branched flow has no editable list, a reader may not change one, and a
     * selection that names something this list does not hold is a stale table
     * — a second tab, a colleague, an undo. Offering "delete" in any of those
     * cases produces a toolbar whose only button is guaranteed to fail.
     */
    public function actionList(Request $request, Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('view automations');

        $selections = $this->selections($request);
        $mails = $this->editableMails($automationFlow);

        if ($selections === [] || $mails === null || ! $this->canEdit()) {
            return response()->json([]);
        }

        // Every selected key has to be a mail this list currently holds. The
        // client picks the ids, and the table it picked them from may be
        // minutes old.
        if (array_diff($selections, array_column($mails, 'node_key')) !== []) {
            return response()->json([]);
        }

        $count = count($selections);

        return response()->json([[
            'handle' => 'delete',
            // `title` is what the floating toolbar prints on its button, so it
            // is the string that has to count. `buttonText` is the confirmation
            // dialog's run button and counts for the same reason.
            'title' => $count === 1 ? __('Delete mail') : __('Delete :count mails', ['count' => $count]),
            'icon' => 'trash',
            'component' => null,
            'runnable' => true,
            'confirm' => true,
            'dangerous' => true,
            'buttonText' => $count === 1 ? __('Delete mail') : __('Delete :count mails', ['count' => $count]),
            // Two source strings rather than one with "(s)" in it: Statamic's
            // action runner passes these through `__n`, which chooses between
            // the halves of a `singular|plural` string — but only against its
            // own JS dictionary, and these are already translated by the time
            // they get there. So the choice is made here, where the count is.
            //
            // One mail is named, because the reader may have opened the menu on
            // the wrong row and the name is the only thing that says so.
            'confirmationText' => $count === 1
                ? __('Delete “:label”?', ['label' => $this->labelFor($mails, $selections[0])])
                : __('Delete :count mails?', ['count' => $count]),
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
     *
     * The guard runs BEFORE that snapshot, and this is the one write path where
     * that distinction earns its keep: it is the only one where the client
     * chooses a list of ids, so a stale table is the ordinary case rather than
     * a bug. A refusal that had already written a version would leave a
     * "Removed mails from the list" entry behind that removed nothing, and
     * VersionManager keeps only the last 25 — enough of them and the real
     * history is pushed out.
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
            guard: function () use ($automationFlow, $keys) {
                $mails = $this->editableMails($automationFlow);

                if ($mails === null) {
                    throw new RuntimeException($this->notEditableMessage($automationFlow));
                }

                $unknown = array_values(array_diff($keys, array_column($mails, 'node_key')));

                if ($unknown !== []) {
                    throw new RuntimeException(
                        'This automation has no mail with the key '
                        .implode(', ', array_map(fn (string $key) => "'{$key}'", $unknown))
                        .'. Reload the list and try again.'
                    );
                }
            },
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

    /**
     * The mails this list currently holds, or null when it may not be edited.
     *
     * The projection's own verdict, not a second reading of the rule: the list
     * on screen is editable exactly when it says so, and two callers asking the
     * same question in two ways is how they come to disagree.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function editableMails(Automation $automation): ?array
    {
        $list = $this->projection->forAutomation($automation);

        return ($list['editable'] ?? false) ? array_values($list['mails'] ?? []) : null;
    }

    /** The same sentence ChainEditor::assertEditable refuses with. */
    protected function notEditableMessage(Automation $automation): string
    {
        $reasons = $this->projection->forAutomation($automation)['reasons'] ?? [];

        return 'This automation is not a straight line, so its mail list cannot be edited: '
            .implode(' ', $reasons)
            .' Edit it on the canvas instead.';
    }

    /**
     * The name to quote in the confirmation, without its Antlers placeholders.
     *
     * `display_label` rather than `label`: a mail's stored name is a subject
     * template, so “Zahlung bestätigt, {{ contact.first_name }}” is what the
     * column correctly shows and what a question must not. The projection cuts
     * it at the first `{{` — see {@see MailSteps::withoutPlaceholders} for why
     * it cuts rather than resolves.
     *
     * @param  list<array<string, mixed>>  $mails
     */
    protected function labelFor(array $mails, string $nodeKey): string
    {
        foreach ($mails as $mail) {
            if (($mail['node_key'] ?? null) === $nodeKey) {
                return (string) (($mail['display_label'] ?? null) ?: ($mail['label'] ?: $nodeKey));
            }
        }

        return $nodeKey;
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
     * @param  (callable(): void)|null  $guard  Runs BEFORE the snapshot and may
     *                                          throw the same RuntimeException a refusal
     *                                          throws. A version written in front of a
     *                                          write that never happened is a lie in the
     *                                          history, and the history is pruned to 25.
     */
    protected function write(Automation $automation, string $message, callable $apply, ?string $success = null, ?callable $guard = null): JsonResponse
    {
        try {
            if ($guard !== null) {
                $guard();
            }
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'list' => $this->projection->forAutomation($automation),
            ], 422);
        }

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
