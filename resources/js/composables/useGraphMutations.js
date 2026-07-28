/**
 * Every way the builder's graph can change, in one place.
 *
 * Extracted from `pages/Automations/Edit.vue`, which held these fifteen
 * functions inline between the page's save/validate/test plumbing and its
 * layout measurement. They are the part of the page with actual invariants —
 * a node key must be unique, an edge must leave an output its node really has,
 * every mutation must record exactly one undo step — and while they sat in the
 * component the only way to exercise them was to drive a browser. Three of the
 * four defects fixed in 1.5.5 were in here.
 *
 * The composable owns nothing: it reads and writes the `automation` ref it is
 * handed, exactly as the inline code did (whole-object replacement, so the
 * autosave watcher and Vue Flow both see one change per mutation). What it
 * adds is a seam — the pure helpers below take plain data and are unit
 * testable, and the reactive surface can be mounted without Vue Flow.
 *
 * @param {Object} options
 * @param {import('vue').Ref} options.automation      The page's automation ref.
 * @param {import('vue').Ref} options.selectedNodeKey Selection, moved along by
 *        insertions and cleared by deletions, as before.
 * @param {Object}   options.library  `{ triggers, logic, actions }` prop.
 * @param {Object}   options.history  `useHistory()` instance.
 * @param {Function} options.notify   `(level, message) => void`.
 */
import { outputsFor } from './useAutoLayout.js';
import { canDuplicate, canInsert, isTriggerHandle as isTriggerHandleGuard } from './useFlowGuards.js';
import { defaultConfigForSchema } from './useNodeValidation.js';

/** Random 4-char suffix, the shape node keys have had since the first release. */
function randomSuffix() {
    return Math.random().toString(36).slice(2, 6);
}

/**
 * A node key that is not already in `taken`.
 *
 * `node_key` carries a `unique(automation_id, node_key)` in the schema (see
 * database/migrations/…_create_automation_nodes_table.php), and the generator
 * is four random base-36 characters — ~1.7M values per type prefix, which is a
 * birthday collision at a few hundred nodes of one type and a plain accident
 * at any size. Nothing checked, so the collision surfaced as an SQL error on
 * save, on a graph the user had already built.
 *
 * Random first, so keys keep looking as they always have. The counter is only
 * there so the loop provably terminates: if the random draw somehow cannot
 * find a free key, a scan of `${base}_2`, `${base}_3`, … must.
 *
 * @param {string} handle Node type handle, e.g. `send_email` or `acme.branch`.
 * @param {Iterable<string>} taken Keys already used in this automation.
 * @param {Function} [random] Suffix generator, injectable for tests.
 */
export function uniqueNodeKey(handle, taken = [], random = randomSuffix) {
    const base = String(handle ?? '').replace(/\W/g, '_');
    const used = taken instanceof Set ? taken : new Set(taken);

    for (let attempt = 0; attempt < 20; attempt++) {
        const key = `${base}_${random()}`;
        if (!used.has(key)) return key;
    }

    let n = 2;
    while (used.has(`${base}_${n}`)) n++;

    return `${base}_${n}`;
}

/**
 * The output handle a node continues on — its FIRST declared output, or null
 * when it declares none (a `stop`, or a `parallel` with no branches yet).
 *
 * There is no `default` output on a `branch` (true/false), a `loop`
 * (loop/done) or an inline `parallel` (its configured branch handles). Code
 * that appended on a hard-coded `'default'` produced an edge leaving a handle
 * the node does not have: invisible on the canvas (Vue Flow cannot resolve the
 * source handle), never followed at run time (`WorkflowRunner::nextNode()`
 * matches `from_output` exactly), and rejected outright by `FlowValidator` for
 * a branch, which turned "Duplicate" on a branch node into a one-click
 * invalid graph.
 */
export function continuationOutput(node) {
    return outputsFor(node)[0]?.handle ?? null;
}

export function sameEdge(a, b) {
    return (
        a.from_node_key === b.from_node_key &&
        (a.from_output || 'default') === (b.from_output || 'default') &&
        a.to_node_key === b.to_node_key
    );
}

/**
 * First output anywhere in the graph that has no edge yet — the default target
 * for the left library ("add to the end of the flow").
 */
export function firstOpenOutput(nodes = [], edges = []) {
    const taken = new Set(edges.map((e) => `${e.from_node_key}::${e.from_output || 'default'}`));

    for (const n of nodes) {
        for (const out of outputsFor(n)) {
            if (!taken.has(`${n.node_key}::${out.handle}`)) {
                return { fromNodeKey: n.node_key, output: out.handle };
            }
        }
    }

    return null;
}

export function useGraphMutations({ automation, selectedNodeKey, library, history, notify }) {
    // Node positions are DERIVED from the graph (see useAutoLayout) — never
    // stored or dragged. Every mutation below therefore only touches nodes +
    // edges; the canvas recomputes the vertical layout on its own.
    // `position_x/y` are written as 0 purely to keep the payload well-formed.

    const nodes = () => automation.value.nodes;
    const edges = () => automation.value.edges;

    function findHandleMeta(handle) {
        return [
            ...(library.triggers ?? []),
            ...(library.logic ?? []),
            ...(library.actions ?? []),
        ].find((m) => m.handle === handle);
    }

    // Kind is inferred by which library array the handle lives in — there is
    // no `kind` field on the node/handle itself (mirrors Canvas.vue's
    // nodeKind()). Thin wrapper over the pure guard module so call sites below
    // don't thread `library` through by hand.
    function isTriggerHandle(handle) {
        return isTriggerHandleGuard(handle, library);
    }

    function newNodeKey(handle) {
        return uniqueNodeKey(handle, nodes().map((n) => n.node_key));
    }

    function makeNode(handle) {
        const meta = findHandleMeta(handle);

        return {
            node_key: newNodeKey(handle),
            type: handle,
            label: meta?.label ?? handle,
            position_x: 0,
            position_y: 0,
            // Seed the schema's declared defaults into the model, so a field
            // the panel already shows pre-filled also counts as filled in
            // validation.
            config: defaultConfigForSchema(meta?.schema),
            disabled: false,
        };
    }

    // Append `node` after (fromNodeKey, output). A null fromNodeKey creates a root.
    function appendNode(fromNodeKey, output, node) {
        const next = fromNodeKey
            ? [
                ...edges(),
                {
                    from_node_key: fromNodeKey,
                    from_output: output || 'default',
                    to_node_key: node.node_key,
                    to_input: 'default',
                },
            ]
            : edges();

        automation.value = {
            ...automation.value,
            nodes: [...nodes(), node],
            edges: next,
        };
        selectedNodeKey.value = node.node_key;
        history.record();
    }

    // Split an existing A→B edge by dropping `node` between them: A→node, node→B.
    //
    // The second edge leaves the NEW node, so it has to leave an output the new
    // node has: `true` for a branch, `loop` for a loop, the first configured
    // handle for an inline parallel. A node with no outputs at all (a `stop`)
    // gets no second edge — B is left as a root instead of being wired to a
    // handle that does not exist, which is what the old hard-coded `'default'`
    // produced: an edge invisible on the canvas and never followed at run time.
    function insertOnEdge(edge, node) {
        const rest = edges().filter((e) => !sameEdge(e, edge));
        const out = continuationOutput(node);

        const inserted = [
            ...rest,
            {
                from_node_key: edge.from_node_key,
                from_output: edge.from_output || 'default',
                to_node_key: node.node_key,
                to_input: 'default',
            },
        ];

        if (out !== null) {
            inserted.push({
                from_node_key: node.node_key,
                from_output: out,
                to_node_key: edge.to_node_key,
                to_input: 'default',
            });
        }

        automation.value = {
            ...automation.value,
            nodes: [...nodes(), node],
            edges: inserted,
        };
        selectedNodeKey.value = node.node_key;
        history.record();
    }

    // Single choke point for every way a node can enter the graph (left-library
    // click, pick-mode selection, or a stray append/insert). Enforces the
    // one-trigger-per-flow rule (§ B1): a trigger can only land via the root
    // append target (`fromNodeKey: null`); anywhere else, if the flow already
    // has a trigger, refuse and toast.
    function insertNode(handle, target) {
        if (!findHandleMeta(handle)) return;

        const { ok } = canInsert(handle, library, nodes());
        if (!ok) {
            notify('error', __('A flow can only have one trigger.'));
            return;
        }

        const node = makeNode(handle);
        if (target.kind === 'insert') {
            insertOnEdge(target.edge, node);
        } else {
            appendNode(target.fromNodeKey ?? null, target.output ?? 'default', node);
        }
    }

    // Left-library click OUTSIDE pick mode: drop the node at the end of the
    // current flow (legacy behaviour, routed through the same one-trigger guard
    // as every other insertion path).
    function addNode(handle) {
        if (!nodes().length) {
            insertNode(handle, { kind: 'append', fromNodeKey: null, output: 'default' });
            return;
        }

        const open = firstOpenOutput(nodes(), edges());
        insertNode(handle, {
            kind: 'append',
            fromNodeKey: open?.fromNodeKey ?? null,
            output: open?.output ?? 'default',
        });
    }

    // Swap the trigger at `nodeKey` for a different trigger type, IN PLACE: same
    // node_key (so every outgoing edge stays wired to downstream nodes exactly
    // as it was), only `type`/`label`/`config` change to the new trigger's
    // defaults. Does not touch nodes/edges elsewhere in the graph.
    function replaceTrigger(nodeKey, newType) {
        const meta = findHandleMeta(newType);
        if (!meta) return;
        // Defense in depth: "Replace trigger" only ever offers trigger handles
        // via pickKind's 'replace-trigger' NodeLibrary filter, but re-verify
        // here too — a non-trigger `newType` slipping through would leave the
        // flow without a trigger at all.
        if (!isTriggerHandle(newType)) return;

        automation.value.nodes = nodes().map((n) =>
            n.node_key === nodeKey
                ? { ...n, type: newType, label: meta.label ?? newType, config: defaultConfigForSchema(meta.schema) }
                : n,
        );
        history.record();
    }

    // Delete a node and heal the sequence: if it sat linearly between a parent
    // and a single child, reconnect parent → child so the flow stays unbroken.
    // Deleting a branch (two children) strands its subtrees as new roots —
    // acceptable for now. A flow without a trigger is invalid, so deleting the
    // sole trigger is refused — "Replace trigger" is the only way to change it.
    function removeNode(nodeKey) {
        const target = nodes().find((n) => n.node_key === nodeKey);
        if (target && isTriggerHandle(target.type)) {
            const triggerCount = nodes().filter((n) => isTriggerHandle(n.type)).length;
            if (triggerCount <= 1) {
                notify('error', __("Ein Flow braucht einen Trigger. Nutze 'Trigger ersetzen', um ihn zu wechseln."));
                return;
            }
        }

        const incoming = edges().filter((e) => e.to_node_key === nodeKey);
        const outgoing = edges().filter((e) => e.from_node_key === nodeKey);
        let remaining = edges().filter(
            (e) => e.from_node_key !== nodeKey && e.to_node_key !== nodeKey,
        );

        if (incoming.length === 1 && outgoing.length === 1) {
            const parent = incoming[0];
            const child = outgoing[0];
            const healed = {
                from_node_key: parent.from_node_key,
                from_output: parent.from_output || 'default',
                to_node_key: child.to_node_key,
                to_input: 'default',
            };
            const dup = remaining.some((e) => sameEdge(e, healed));
            if (!dup && healed.from_node_key !== healed.to_node_key) {
                remaining = [...remaining, healed];
            }
        }

        automation.value = {
            ...automation.value,
            nodes: nodes().filter((n) => n.node_key !== nodeKey),
            edges: remaining,
        };
        if (selectedNodeKey.value === nodeKey) selectedNodeKey.value = null;
        history.record();
    }

    // Config and label are text fields: they fire on every keystroke, so their
    // history entries are tagged per node and folded into one undo step per
    // burst (see useHistory's coalescing note). Everything else here records
    // untagged and therefore always gets its own step.
    function updateNodeConfig(config) {
        if (!selectedNodeKey.value) return;

        automation.value.nodes = nodes().map((n) =>
            n.node_key === selectedNodeKey.value ? { ...n, config } : n,
        );
        history.record(`config:${selectedNodeKey.value}`);
    }

    function updateNodeLabel(label) {
        if (!selectedNodeKey.value) return;

        automation.value.nodes = nodes().map((n) =>
            n.node_key === selectedNodeKey.value ? { ...n, label } : n,
        );
        history.record(`label:${selectedNodeKey.value}`);
    }

    // Duplicate a node right after itself in the sequence: on its continuation
    // output, splitting that output's edge when it already has one.
    //
    // Backstop for the one-trigger rule (§ B1): the "Duplicate" actions in
    // NodeCard.vue's card menu and ConfigPanel.vue's footer are kind-gated so a
    // trigger node's Duplicate never renders, but this is the single choke point
    // every duplicate path funnels through, so it re-checks here too — a second
    // trigger has no Delete action to recover from (see removeNode).
    function duplicateNode(nodeKey) {
        const src = nodes().find((n) => n.node_key === nodeKey);
        if (!src) return;

        const { ok } = canDuplicate(src, library, nodes());
        if (!ok) {
            notify('error', __('A flow can only have one trigger.'));
            return;
        }

        const copy = {
            ...src,
            node_key: newNodeKey(src.type),
            label: src.label ? `${src.label} (${__('copy')})` : src.label,
            position_x: 0,
            position_y: 0,
            config: JSON.parse(JSON.stringify(src.config ?? {})),
        };

        // Where the copy hangs off the source. Not `'default'`: a branch has
        // true/false and nothing else, and appending the copy on a handle the
        // source does not have is precisely what FlowValidator refuses.
        const out = continuationOutput(src);

        if (out === null) {
            // The source declares no outputs (a `stop`, or a `parallel` with no
            // branches configured yet). There is no handle to hang the copy off,
            // so it enters the graph unconnected rather than on an invented one.
            automation.value = {
                ...automation.value,
                nodes: [...nodes(), copy],
            };
            selectedNodeKey.value = copy.node_key;
            history.record();
            notify('info', __('Copied node added unconnected — the original has no output to attach it to.'));
            return;
        }

        const cont = edges().find(
            (e) => e.from_node_key === nodeKey && (e.from_output || 'default') === out,
        );

        if (cont) {
            insertOnEdge(
                { from_node_key: nodeKey, from_output: out, to_node_key: cont.to_node_key },
                copy,
            );
        } else {
            appendNode(nodeKey, out, copy);
        }
    }

    function toggleNodeDisabled(nodeKey) {
        automation.value.nodes = nodes().map((n) =>
            n.node_key === nodeKey ? { ...n, disabled: !n.disabled } : n,
        );
        history.record();
    }

    return {
        findHandleMeta,
        isTriggerHandle,
        newNodeKey,
        makeNode,
        appendNode,
        insertOnEdge,
        insertNode,
        addNode,
        replaceTrigger,
        removeNode,
        updateNodeConfig,
        updateNodeLabel,
        duplicateNode,
        toggleNodeDisabled,
    };
}
