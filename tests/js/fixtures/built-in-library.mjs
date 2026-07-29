/**
 * A node-library payload of the shape the server renders, carrying the output
 * specs exactly as the registry ships them.
 *
 * `node-output-specs.json` next to this file is written by
 * `tests/Feature/NodeOutputSpecContractTest.php` from the live PHP registry
 * and committed. Every JS test that needs a node to have its real handles
 * registers this, so no test can pass against a spec the backend does not
 * actually send.
 */
import { setNodeOutputSpecs } from '../../../resources/js/composables/useNodeOutputs.js';

// Imported rather than read off disk so this module works unchanged under
// `node --test` and under Vitest (whose module URLs are not file URLs).
import specs from './node-output-specs.json' with { type: 'json' };

export { specs };

const KIND = {
    manual: 'triggers',
    send_email: 'actions',
    branch: 'logic',
    switch: 'logic',
    loop: 'logic',
    parallel: 'logic',
    stop: 'logic',
};

/**
 * @param {Object} extra Additional `handle → spec` entries (a third-party node).
 */
export function builtInLibrary(extra = {}) {
    const library = { triggers: [], logic: [], actions: [] };

    for (const [handle, outputs] of Object.entries({ ...specs, ...extra })) {
        library[KIND[handle] ?? 'actions'].push({ handle, label: handle, schema: [], outputs });
    }

    return library;
}

/** Register the built-in specs (plus any extras) as the canvas's library. */
export function useBuiltInOutputs(extra = {}) {
    const library = builtInLibrary(extra);
    setNodeOutputSpecs(library);

    return library;
}
