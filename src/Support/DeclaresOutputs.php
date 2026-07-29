<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * For a node that declares its output handles.
 *
 * The node writes `outputSpec()` (see {@see NodeOutputs} for the grammar) and
 * gets `outputs()` — the resolved handles for a given config — for free. Both
 * the canvas and `FlowValidator` read the same spec, so a node's handles exist
 * once, in the node.
 *
 * A node is free to skip this and implement `outputs(array $config = [])`
 * directly. The registry can serialise such a node's outputs to the canvas
 * only as they resolve under an empty config, which is exact for fixed
 * outputs and approximate for config-dependent ones — the reason `outputSpec()`
 * exists.
 */
trait DeclaresOutputs
{
    /**
     * The output handles this node can route through under `$config`.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    public static function outputs(array $config = []): array
    {
        return NodeOutputs::handles(static::outputSpec(), $config);
    }
}
