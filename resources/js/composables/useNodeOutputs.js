/**
 * Moved to `@goldnead/flow-canvas`.
 *
 * A pointer, not a copy. The spec evaluator knows no node types — it reads what
 * the server declared — so both this addon and `statamic-funnels` use the same
 * one, and a node's outputs mean the same thing on both canvases.
 */

export * from '@goldnead/flow-canvas/composables/useNodeOutputs.js';
