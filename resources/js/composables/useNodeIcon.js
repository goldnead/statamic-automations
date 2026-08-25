/**
 * Kept as a pointer for existing imports.
 *
 * The lookup moved to `@goldnead/flow-canvas`; the *map* stayed here, because
 * only this addon knows that `send_email` should be an envelope. It now lives
 * in `support/nodeKinds.js` next to the kind descriptors it belongs with.
 */

export { nodeIcon } from '../support/nodeKinds.js';

import { nodeIcon } from '../support/nodeKinds.js';

export function useNodeIcon() {
    return { nodeIcon };
}
