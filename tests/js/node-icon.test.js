/**
 * Guards the shared node-handle → icon mapping used by NodeCard and NodeLibrary.
 *
 * The icon overhaul renders a Statamic `<Icon :name="…">` chip per node. If a
 * mapped name is not a real icon that ships with `@statamic/cms`, the chip
 * silently renders empty in the CP. This test fails fast on such a typo by
 * checking every mapped name against the shipped SVG set, and verifies the
 * per-kind fallback behaviour.
 *
 * Run: `npx vitest run` — NOT `npm run test:js`. This pulls `nodeKinds.js`,
 * which imports `@goldnead/flow-canvas`, which pulls a `.vue` that bare Node
 * cannot load (ERR_UNKNOWN_FILE_EXTENSION). vite.config.js inlines that package
 * for exactly this reason, so the file belongs in the vitest suite.
 */
import { expect, test } from 'vitest';
import { readdirSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const iconsDir = resolve(__dirname, '../../vendor/statamic/cms/resources/svg/icons');

const { nodeIcon } = await import('../../resources/js/composables/useNodeIcon.js');

// Every node handle the backend registers (src/Nodes/**). Keep in sync.
const HANDLES = [
    // triggers
    'entry_published', 'entry_saved', 'entry_deleted', 'user_registered',
    'form_submitted', 'webhook_received', 'scheduled', 'manual',
    // logic
    'filter', 'branch', 'switch', 'parallel', 'wait_until', 'delay', 'throttle', 'loop', 'stop',
    // actions
    'send_email', 'send_webhook', 'add_log_entry', 'set_variable', 'update_entry',
    'create_entry', 'create_user', 'call_automation', 'ai_generate',
];

test('the Statamic icon set is present for validation', () => {
    expect(existsSync(iconsDir), `expected icon dir at ${iconsDir}`).toBe(true);
});

test('every mapped node handle resolves to a real Statamic icon', () => {
    const available = new Set(
        readdirSync(iconsDir).filter((f) => f.endsWith('.svg')).map((f) => f.replace(/\.svg$/, '')),
    );
    for (const handle of HANDLES) {
        const icon = nodeIcon(handle, 'action');
        expect(available.has(icon), `handle "${handle}" → "${icon}" is not a shipped icon`).toBe(true);
    }
});

test('per-kind fallbacks are real icons and distinct from the generic default', () => {
    const available = new Set(
        readdirSync(iconsDir).filter((f) => f.endsWith('.svg')).map((f) => f.replace(/\.svg$/, '')),
    );
    for (const kind of ['trigger', 'logic', 'action']) {
        const icon = nodeIcon('__unknown_handle__', kind);
        expect(available.has(icon), `fallback for kind "${kind}" → "${icon}" is not a shipped icon`).toBe(true);
    }
});

test('unknown handle with unknown kind still yields a valid icon name', () => {
    const icon = nodeIcon('__nope__', '__nope__');
    expect(typeof icon).toBe('string');
    expect(icon.length).toBeGreaterThan(0);
});
