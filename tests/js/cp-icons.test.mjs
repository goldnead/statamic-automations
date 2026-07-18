/**
 * Guards the static icon names hardcoded in the CP Vue pages.
 *
 * The addon renders Statamic `<Icon name="…">` / `<Header icon="…">` /
 * `<EmptyStateItem icon="…">`. A name that isn't a shipped icon renders empty
 * and logs `Icon [name] not found` in the console (this is exactly how the
 * brand icon `hammer` slipped in). This test scans every `.vue` page for static
 * `name="…"` / `icon="…"` literals and asserts each is a real shipped icon.
 *
 * Only static string literals are checked; dynamic bindings (`:icon="…"`) are
 * out of scope (the node-icon mapping is covered by node-icon.test.mjs).
 *
 * Run: `npm run test:js`
 */
import assert from 'node:assert/strict';
import test from 'node:test';
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const iconsDir = resolve(__dirname, '../../vendor/statamic/cms/resources/svg/icons');
const jsDir = resolve(__dirname, '../../resources/js');

function walk(dir) {
    const out = [];
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = join(dir, entry.name);
        if (entry.isDirectory()) out.push(...walk(full));
        else if (entry.name.endsWith('.vue')) out.push(full);
    }
    return out;
}

// Match static `name="foo"` and `icon="foo"` (NOT `:icon="foo"` bindings).
const ICON_ATTR = /(?<![:@\w-])(?:name|icon)="([a-z0-9-]+)"/g;

test('the Statamic icon set is present for validation', () => {
    assert.ok(existsSync(iconsDir), `expected icon dir at ${iconsDir}`);
});

test('no CP page references the non-existent "hammer" icon', () => {
    for (const file of walk(jsDir)) {
        const src = readFileSync(file, 'utf8');
        assert.ok(!/["']hammer["']/.test(src), `${file} still references the "hammer" icon`);
    }
});

test('every static Icon name in the CP pages is a shipped Statamic icon', () => {
    const available = new Set(
        readdirSync(iconsDir).filter((f) => f.endsWith('.svg')).map((f) => f.replace(/\.svg$/, '')),
    );

    for (const file of walk(jsDir)) {
        const src = readFileSync(file, 'utf8');
        for (const match of src.matchAll(ICON_ATTR)) {
            const name = match[1];
            assert.ok(available.has(name), `${file}: icon "${name}" is not a shipped Statamic icon`);
        }
    }
});
