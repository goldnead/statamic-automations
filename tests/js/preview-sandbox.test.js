import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import axios from 'axios';

import MailPreviewPane from '../../resources/js/components/builder/MailPreviewPane.vue';

/**
 * Die Rahmen-Seite der Vorschau-Schranke.
 *
 * Im Vorschau-Rahmen steht HTML, das ein CP-Benutzer geschrieben hat — eine
 * Mail-Vorlage, der eigene Text eines Knotens. Der Rahmen hängt in einer Seite
 * des Control Panels, also ist das Dokument darin ohne `sandbox` gleicher
 * Herkunft wie das CP, und ein `<script>` in einer Vorlage läuft mit der Sitzung
 * dessen, der sich die Vorschau ansieht. `sandbox=""` setzt den Rahmen in eine
 * eigene, undurchsichtige Herkunft mit abgeschalteten Skripten; jedes wieder
 * hinzugefügte Token ist ein Loch, und zwei davon reißen genau dieses wieder
 * auf:
 *
 *   - `allow-scripts` schaltet die Ausführung wieder an.
 *   - `allow-same-origin` gibt dem Rahmen die Herkunft des Control Panels
 *     zurück, und zusammen mit `allow-scripts` ist das laut Spezifikation
 *     dasselbe wie gar kein Sandkasten — der Rahmen kann sich das Attribut
 *     dann selbst abnehmen.
 *
 * Die Behauptung ist deshalb nicht „es gibt ein sandbox-Attribut", sondern „das
 * sandbox-Attribut gibt keines dieser beiden her". Ein späteres `allow-popups`
 * aus gutem Grund darf durchgehen, ein `allow-same-origin` nicht.
 *
 * Dieselbe Logik steht in statamic-marketing (`tests/js/preview-sandbox.test.js`
 * dort, plus die Antwort-Seite mit dem CSP-Header). Hier gibt es keine
 * Antwort-Seite: das HTML geht als JSON-Feld in ein `srcdoc`, es wird nie als
 * Dokument ausgeliefert. Der Rahmen ist damit die ganze Schranke.
 *
 * Was hier bewusst NICHT geprüft wird: die beiden Schnappschuss-Rahmen
 * (`MailPreviewPane.vue`, `MailPreviewModal.vue`, Reiter „Wie es rausging").
 * Die zeigen kein `srcdoc`, sondern per `src` eine fertige CP-Seite aus
 * `goldnead/statamic-email-templates` — eine Seite dieses Systems mit eigenen
 * Schutzmaßnahmen, die ihre Herkunft braucht. Sie tragen `allow-same-origin`
 * und kein `allow-scripts`. Wer das ändert, ändert es dort und nicht hier.
 */

const FORBIDDEN = ['allow-scripts', 'allow-same-origin'];

vi.mock('axios', () => ({
    default: {
        post: vi.fn(() => Promise.resolve({ data: { data: {} } })),
        isCancel: () => false,
    },
}));

beforeEach(() => {
    vi.useFakeTimers();
    axios.post.mockClear();
    axios.post.mockImplementation(() =>
        Promise.resolve({
            data: {
                data: {
                    node_key: 'mail',
                    label: 'Mail',
                    subject: 'Hallo',
                    // Was ein CP-Benutzer geschrieben haben könnte.
                    html: '<p>Hallo</p><script>parent.document.cookie</script>',
                    source: 'inline',
                    snapshot: null,
                },
            },
        }),
    );
});

afterEach(() => {
    vi.useRealTimers();
});

describe('the preview frame in the node stack', () => {
    it('grants the frame neither scripts nor the Control Panel origin', async () => {
        const wrapper = mount(MailPreviewPane, {
            props: {
                apiBase: '/cp/automations/api',
                automationId: 7,
                nodeKey: 'mail',
                config: { subject: 'Hallo', body: '<p>Hallo</p>' },
            },
        });

        vi.advanceTimersByTime(0);
        await Promise.resolve();
        await Promise.resolve();
        await wrapper.vm.$nextTick();

        const frame = wrapper.find('iframe');

        expect(frame.exists()).toBe(true);

        const sandbox = frame.attributes('sandbox');

        expect(sandbox).toBeDefined();

        for (const token of FORBIDDEN) {
            expect(sandbox.split(/\s+/)).not.toContain(token);
        }
    });
});

/**
 * Der Rahmen im Stack ist nicht der einzige: dieselbe Mail steht auch im
 * Vorschau-Modal der Mails-Liste und im Vorlagen-Wähler. Jeder Rahmen, der
 * fremdes HTML über `srcdoc` bekommt, fällt unter dieselbe Regel — und ein
 * vierter, der morgen dazukommt, soll nicht darauf warten müssen, dass jemand
 * hier einen Testfall nachträgt.
 */
describe('every srcdoc frame in the Control Panel', () => {
    const __dirname = dirname(fileURLToPath(import.meta.url));
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

    it('is sandboxed without scripts and without the CP origin', () => {
        const offenders = [];

        for (const file of walk(jsDir)) {
            const source = readFileSync(file, 'utf8');

            for (const [frame] of source.matchAll(/<iframe\b[\s\S]*?\/?>/g)) {
                if (!/:?srcdoc[=\s]/.test(frame)) continue;

                // Eine gebundene Sandbox (`:sandbox="…"`) wäre hier ein Loch,
                // das wie ein Schloss aussieht: der Wert entsteht erst zur
                // Laufzeit, und dieser Test läse den Ausdruck als Zeichenkette
                // und fände nichts Verbotenes darin.
                if (/[:@]sandbox\s*=/.test(frame) || /\bv-bind:sandbox\s*=/.test(frame)) {
                    offenders.push(`${file}: srcdoc frame binds its sandbox attribute at runtime`);
                    continue;
                }

                const sandbox = frame.match(/(?<![:@\w-])sandbox="([^"]*)"/);

                if (!sandbox) {
                    offenders.push(`${file}: srcdoc frame without a sandbox attribute`);
                    continue;
                }

                for (const token of FORBIDDEN) {
                    if (sandbox[1].split(/\s+/).includes(token)) {
                        offenders.push(`${file}: srcdoc frame grants ${token}`);
                    }
                }
            }
        }

        expect(offenders).toEqual([]);
    });
});
