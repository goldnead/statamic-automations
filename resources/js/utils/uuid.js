/**
 * Generate a short, mostly-unique node key. Not a real UUID — just
 * good enough for client-side identity inside a single automation.
 */
export function nodeKey(prefix = 'node') {
    const rand = Math.random().toString(36).slice(2, 8);
    return `${prefix}_${rand}`;
}
