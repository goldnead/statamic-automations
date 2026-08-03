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
     * Snapshot, apply, and answer with the list as it now is.
     *
     * The refusal path is a 422 carrying the rule's own reasons, not a bare
     * "not allowed": an editor who is told *why* the list is locked can decide
     * whether to unbranch the flow or to go to the canvas, and one who is told
     * nothing files a bug.
     *
     * @param  callable(): Automation  $apply
     */
    protected function write(Automation $automation, string $message, callable $apply): JsonResponse
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

        return response()->json($this->projection->forAutomation($automation));
    }
}
