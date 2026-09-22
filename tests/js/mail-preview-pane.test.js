import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import axios from 'axios';

import MailPreviewPane from '../../resources/js/components/builder/MailPreviewPane.vue';

/**
 * Die Vorschau im Node-Stack, und was sie von der alten im Modal unterscheidet.
 *
 * Zwei Eigenschaften machen das Ticket aus, und beide sind nur im Browser
 * prüfbar: sie lädt **ohne einen zweiten Klick**, und sie lädt **den
 * Formularzustand**, nicht den gespeicherten Knoten. Eine Vorschau, die beim
 * Tippen die vorige Fassung zeigt, ist schlechter als keine, und genau das war
 * der Stand bis 2.18.1 (GET auf den Knoten aus der Datenbank).
 */

const answer = (subject = 'Zahlung bestätigt, Max', html = '<p>Hallo Max</p>') => ({
    data: {
        data: {
            node_key: 'mail',
            label: 'Mail',
            slug: null,
            subject,
            html,
            source: 'inline',
            snapshot: null,
        },
    },
});

vi.mock('axios', () => ({
    default: {
        post: vi.fn(() => Promise.resolve({ data: { data: {} } })),
        isCancel: () => false,
    },
}));

function pane(config = {}) {
    return mount(MailPreviewPane, {
        props: {
            apiBase: '/cp/automations/api',
            automationId: 7,
            nodeKey: 'mail',
            config: { subject: 'Zahlung bestätigt', body: 'Hallo', ...config },
        },
    });
}

/** Entprellung durchlaufen lassen und die Antwort ankommen lassen. */
async function settle(wrapper, ms = 400) {
    vi.advanceTimersByTime(ms);
    await Promise.resolve();
    await Promise.resolve();
    await wrapper.vm.$nextTick();
}

beforeEach(() => {
    vi.useFakeTimers();
    axios.post.mockClear();
    axios.post.mockImplementation(() => Promise.resolve(answer()));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('loading without a second click', () => {
    it('asks for the mail as soon as it is mounted', async () => {
        const wrapper = pane();

        await settle(wrapper, 0);

        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.post.mock.calls[0][0]).toBe('/cp/automations/api/automations/7/mails/mail/preview');
    });

    it('sends the form state, not just the node key', async () => {
        const wrapper = pane();

        await settle(wrapper, 0);

        // Der Rumpf ist der Unterschied zum alten GET: der Server rendert, was
        // im Formular steht, und nicht, was in der Datenbank liegt.
        expect(axios.post.mock.calls[0][1]).toEqual({
            config: { template: '', subject: 'Zahlung bestätigt', body: 'Hallo' },
        });
    });

    it('says so instead of showing an empty frame while the automation is unsaved', async () => {
        const wrapper = mount(MailPreviewPane, {
            props: {
                apiBase: '/cp/automations/api',
                automationId: null,
                nodeKey: 'mail',
                config: { subject: 'Hallo' },
            },
        });

        await settle(wrapper, 0);

        expect(axios.post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('The preview appears once this automation has been saved.');
    });
});

describe('following the form', () => {
    it('re-renders after the subject is typed, debounced', async () => {
        const wrapper = pane();
        await settle(wrapper, 0);
        expect(axios.post).toHaveBeenCalledTimes(1);

        await wrapper.setProps({ config: { subject: 'Zahlung bestätigt, endlich', body: 'Hallo' } });

        // Noch nicht: pro Tastendruck eine Anfrage wäre ein Sturm.
        vi.advanceTimersByTime(399);
        expect(axios.post).toHaveBeenCalledTimes(1);

        await settle(wrapper, 1);

        expect(axios.post).toHaveBeenCalledTimes(2);
        expect(axios.post.mock.calls[1][1].config.subject).toBe('Zahlung bestätigt, endlich');
    });

    it('ignores changes to fields that are not part of the mail', async () => {
        const wrapper = pane();
        await settle(wrapper, 0);

        // Empfänger, Liste, Bedingungen: ändern die Mail nicht, also auch
        // nicht die Vorschau — und lösen keine Anfrage aus.
        await wrapper.setProps({
            config: { subject: 'Zahlung bestätigt', body: 'Hallo', to: 'jemand@example.com' },
        });
        await settle(wrapper);

        expect(axios.post).toHaveBeenCalledTimes(1);
    });

    it('shows what came back', async () => {
        axios.post.mockImplementation(() => Promise.resolve(answer('Neuer Betreff', '<p>Neuer Text</p>')));

        const wrapper = pane();
        await settle(wrapper, 0);

        expect(wrapper.find('[data-sa-preview-subject]').text()).toBe('Neuer Betreff');
        expect(wrapper.find('iframe').attributes('srcdoc')).toBe('<p>Neuer Text</p>');
    });

    it('does not let an overtaken answer win', async () => {
        // Pro Tastendruck eine Anfrage, und die zuletzt eintreffende Antwort
        // ist nicht zwangsläufig die Antwort auf die letzte Frage. Ohne den
        // Abbruch und die `inflight === mine`-Abfrage schreibt die langsame
        // erste Antwort über die schnelle zweite — die Vorschau zeigt dann,
        // was zwei Tastendrücke vorher im Formular stand.
        const pending = [];
        axios.post.mockImplementation(
            () => new Promise((resolve) => { pending.push(resolve); }),
        );

        const wrapper = pane();
        vi.advanceTimersByTime(0);
        await Promise.resolve();

        await wrapper.setProps({ config: { subject: 'Die zweite Frage', body: 'Hallo' } });
        vi.advanceTimersByTime(400);
        await Promise.resolve();

        expect(pending).toHaveLength(2);

        // Die zweite antwortet zuerst, die erste danach.
        pending[1](answer('ANTWORT AUF DIE ZWEITE'));
        await Promise.resolve();
        await wrapper.vm.$nextTick();
        pending[0](answer('ANTWORT AUF DIE ERSTE'));
        await Promise.resolve();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-sa-preview-subject]').text()).toBe('ANTWORT AUF DIE ZWEITE');
    });

    it('shows the neutral empty state for a mail step with nothing in it yet', async () => {
        // Der Anfangszustand jedes frisch eingefügten Mail-Knotens. Der
        // Endpunkt antwortet darauf mit `source: 'empty'` und 200, nicht mit
        // 404 — sonst sähe der Normalfall aus wie ein Defekt, und die
        // entprellte Anfrage schriebe eine Log-Warnung pro Tastendruck.
        axios.post.mockImplementation(() =>
            Promise.resolve({
                data: { data: { node_key: 'mail', label: 'Mail', subject: '', html: '', source: 'empty', snapshot: null } },
            }),
        );

        const wrapper = pane({ subject: '', body: '' });
        await settle(wrapper, 0);

        expect(wrapper.find('iframe').exists()).toBe(false);
        expect(wrapper.text()).toContain('Nothing to preview yet.');
        expect(wrapper.text()).not.toContain('This mail cannot be displayed.');
    });

    it('names the reason the endpoint gave', async () => {
        axios.post.mockImplementation(() =>
            Promise.reject({ response: { data: { message: 'Die Vorlage "x" gehört zur Marke "b".' } } }),
        );

        const wrapper = pane();
        await settle(wrapper, 0);

        expect(wrapper.text()).toContain('Die Vorlage "x" gehört zur Marke "b".');
    });
});
