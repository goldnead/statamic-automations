<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Sequence\RuleEditor;
use Goldnead\StatamicAutomations\Sequence\RuleProjection;
use Goldnead\StatamicAutomations\Sequence\RuleShape;
use Goldnead\StatamicAutomations\Support\DispatchMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as ValidationRule;
use RuntimeException;

/**
 * One automation, read and written as a single rule: "when X, send Y to Z".
 *
 * Reading is always allowed. Writing is allowed only while {@see RuleShape}
 * says the automation is a rule, and a write against anything else answers 422
 * with the reasons rather than doing something approximate to the graph.
 *
 * The write snapshots a version first, like every other write path in this
 * addon: an edit made from a row is still an edit to the graph and has to be
 * revertable from the same history.
 *
 * **No store, no destroy.** The row edits automations that already exist. See
 * {@see RuleEditor} for why creating one from a row is a different cut.
 */
class RuleController extends Controller
{
    public function __construct(
        protected RuleProjection $projection,
        protected RuleEditor $editor,
    ) {}

    public function show(Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json($this->projection->forAutomation($automationFlow));
    }

    public function update(Request $request, Automation $automationFlow): JsonResponse
    {
        $this->authorizeAction('edit automations');

        // `sometimes` throughout, because the row sends what was changed. A
        // payload that always carried every field would make a toggle on one
        // row overwrite a template somebody else had just picked.
        $data = $request->validate([
            'recipient' => ['sometimes', 'nullable', 'string'],
            'template' => ['sometimes', 'nullable', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'dispatch_mode' => ['sometimes', 'string', ValidationRule::enum(DispatchMode::class)],
        ]);

        return $this->write($automationFlow, 'Edited the rule', fn () => $this->editor->update($automationFlow, $data));
    }

    /**
     * Snapshot, apply, and answer with the row as it now is.
     *
     * The refusal path is a 422 carrying the shape's own reasons and the row
     * itself, not a bare "not allowed": an editor told *why* the row is locked
     * can decide whether to simplify the flow or go to the canvas, and one told
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
                'rule' => $this->projection->forAutomation($automation),
            ], 422);
        }

        return response()->json($this->projection->forAutomation($automation));
    }
}
