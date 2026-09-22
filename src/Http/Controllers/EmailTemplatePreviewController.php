<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;

/**
 * Backs the `send_email` node's email affordances in the CP builder: a rendered
 * template preview and a master-detail template picker.
 *
 * Both endpoints are guarded seams to the OPTIONAL `goldnead/statamic-email-templates`
 * addon — there's no hard composer dependency, so when the sibling is absent the
 * list degrades to an empty array and the preview 404s, never a fatal.
 *
 *   - GET  email-templates          → [{slug, title, subject, preview}]  (picker list)
 *   - GET  email-templates/preview  → {slug, title, subject, preview, html}
 *   - GET  automations/{flow}/mails/{nodeKey}/preview
 *                                   → {label, subject, html, source, snapshot}
 *   - POST automations/{flow}/mails/{nodeKey}/preview  {config: {…}}
 *                                   → dasselbe, aber aus dem Formular
 *
 * The third one is the mail of a stored node — the same mail the run sends,
 * template or inline body, rendered against sample data — plus a pointer to
 * what actually went out, if the suite's snapshot layer holds a version of it.
 * Der vierte ist derselbe Endpunkt mit der ungespeicherten Konfiguration im
 * Rumpf: die Vorschau im Node-Stack tippt mit. Siehe `node()`.
 *
 * The `preview` field is the template's preheader/preview text (a subtitle for
 * the list), NOT rendered HTML. The `html` field is the branded, email-layout
 * wrapped body with its {{ merge tokens }} resolved against SAMPLE data so the
 * author sees a representative render inside the sandboxed iframe.
 *
 * **Both endpoints are scoped to the same brand.** This is the fix for F17
 * (07.09.2026): the list used to be a plain collection query with no brand
 * filter, while the preview goes through `EmailTemplates::resolve()` →
 * `EmailTemplateCollectionManager::findBySlug()`, which filters by the current
 * brand. So on a multi-brand install the picker offered every brand's templates
 * and every foreign one answered 404 — the red "Vorschau nicht verfügbar" on
 * some templates and not others. Offering them was the lie, not the 404: the
 * send node resolves through the same brand-scoped lookup (the run's brand
 * rides along on the queue job), so a foreign-brand slug would not send either
 * — it would silently fall back to the node's inline body.
 *
 * Every failure here names its reason and writes a log line. The generic
 * "Vorschau nicht verfügbar" was the second half of the defect: the reason
 * existed and nobody could see it.
 */
class EmailTemplatePreviewController extends Controller
{
    /**
     * The suite's store of what went out, in email-templates. A string because
     * that addon is optional here; the name is frozen on the other side.
     */
    protected const SNAPSHOTS = 'Goldnead\\EmailTemplates\\Snapshots\\Snapshots';

    /**
     * All managed `et_templates` as a flat list for the picker.
     */
    public function index(): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json(['data' => $this->templates()->values()->all()]);
    }

    /**
     * The rendered branded HTML for a single template slug, with sample merge
     * tokens resolved. 404 when the addon is absent or the slug is unknown.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeAction('view automations');

        $slug = trim((string) $request->query('slug', ''));

        if ($slug === '') {
            return $this->failure(__('Es wurde keine Vorlage angefragt.'), 404);
        }

        if (! $this->addonInstalled()) {
            return $this->failure(
                __('Das Addon "E-Mail-Vorlagen" ist nicht installiert, deshalb gibt es hier keine Vorlagen zum Anzeigen.'),
                404
            );
        }

        // Managed entry wins; no fallback callable, so an unknown slug resolves
        // to null (→ 404) rather than echoing back an empty template. The
        // resolver renders Bard and wraps the layout, so it can also throw —
        // and did, unseen, before this catch existed.
        try {
            $resolved = EmailTemplates::resolve($slug);
        } catch (\Throwable $e) {
            return $this->failure(
                __('Die Vorlage ":slug" ließ sich nicht laden: :grund', ['slug' => $slug, 'grund' => $e->getMessage()]),
                500,
                $e
            );
        }

        if ($resolved === null || (($resolved->body ?? '') === '' && ($resolved->subject ?? '') === '')) {
            return $this->failure($this->missingReason($slug), 404);
        }

        $sample = $this->sampleData();

        try {
            // Returned raw (unescaped): the caller renders it inside an iframe
            // with `sandbox=""` — no scripts, and not the Control Panel's
            // origin. Gegenprobe: tests/js/preview-sandbox.test.js.
            $html = $this->renderWithSample((string) ($resolved->body ?? ''), $sample);
            $subject = $this->renderWithSample((string) ($resolved->subject ?? ''), $sample);
        } catch (\Throwable $e) {
            return $this->failure(
                __('Die Vorlage ":slug" ließ sich nicht rendern: :grund', ['slug' => $slug, 'grund' => $e->getMessage()]),
                500,
                $e
            );
        }

        // Preheader/preview text + title live on the managed entry, not the
        // resolved DTO — look them up so the preview header mirrors the list.
        $meta = $this->templates()->firstWhere('slug', $slug) ?? [];

        return response()->json(['data' => [
            'slug' => $slug,
            'title' => (string) (($resolved->title ?? '') !== '' ? $resolved->title : ($meta['title'] ?? $slug)),
            'subject' => $subject,
            'preview' => (string) ($meta['preview'] ?? ''),
            'html' => $html,
        ]]);
    }

    /**
     * One stored mail of an automation, rendered.
     *
     * This is what the Mails view and the e-mail node both show. It answers two
     * questions side by side and never mixes them:
     *
     *  - `html` — the mail as the node stands **today**, with sample data in
     *    the placeholders. A managed template resolves through the same
     *    brand-scoped lookup the send uses; a node without a template renders
     *    its own body.
     *  - `snapshot` — a pointer to what **actually went out**, if the suite's
     *    snapshot layer holds a version of this node's mail. The URL is
     *    email-templates' own CP preview and goes straight into an `iframe src`
     *    — no route and no renderer here, and no second snapshot table.
     *
     * Null snapshot means: the layer is absent, switched off, or this node has
     * not sent anything yet. All three are the same answer for the caller — do
     * not offer the second tab.
     *
     * ── Gespeichert oder aus dem Formular ─────────────────────────────────
     *
     * Per GET ist es der Knoten aus der Datenbank. Per POST mit dem Rumpf
     * `{ config: { template, subject, body } }` ist es das, was gerade im
     * Formular steht — die Vorschau im Node-Stack tippt mit, und eine Vorschau,
     * die beim Tippen die vorige Fassung zeigt, ist schlechter als keine.
     *
     * Beide Wege gehen durch dieselbe Berechtigungsprüfung und denselben
     * Renderer; verschieden ist nur, woher die drei Werte kommen. Der
     * Schnappschuss bleibt am gespeicherten Knoten hängen (was rausging, hat
     * mit dem Entwurf nichts zu tun), und ein Knoten, den es in der Datenbank
     * noch gar nicht gibt, ist per POST trotzdem darstellbar — sonst bliebe
     * eine frisch eingefügte Mail bis zum ersten Speichern leer.
     */
    public function node(Request $request, Automation $automationFlow, string $nodeKey): JsonResponse
    {
        $this->authorizeAction('view automations');

        $draft = $this->draftConfig($request);

        $automationFlow->loadMissing('nodes');
        $node = $automationFlow->nodes->firstWhere('node_key', $nodeKey);

        if ($node === null && $draft === null) {
            return $this->failure(
                __('Dieser Ablauf hat keinen Schritt ":key". Vielleicht wurde er umbenannt oder gelöscht, seit die Liste geladen wurde.', ['key' => $nodeKey]),
                404
            );
        }

        $config = $draft ?? (is_array($node?->config) ? $node->config : []);
        $slug = (string) ($config['template'] ?? '');
        $subject = (string) ($config['subject'] ?? '');
        $body = (string) ($config['body'] ?? '');
        $source = 'inline';

        if ($slug !== '' && $this->addonInstalled()) {
            try {
                $resolved = EmailTemplates::resolve($slug);
            } catch (\Throwable $e) {
                return $this->failure(
                    __('Die Vorlage ":slug" ließ sich nicht laden: :grund', ['slug' => $slug, 'grund' => $e->getMessage()]),
                    500,
                    $e
                );
            }

            if ($resolved === null || (string) ($resolved->body ?? '') === '') {
                return $this->failure($this->missingReason($slug), 404);
            }

            $body = (string) $resolved->body;
            $source = 'template';

            if ($subject === '' && (string) ($resolved->subject ?? '') !== '') {
                $subject = (string) $resolved->subject;
            }
        } elseif ($body === '' && $subject === '') {
            // Ein frisch eingefügter Mail-Knoten steht genau hier: keine
            // Vorlage, kein Betreff, kein Text. Aus dem Formular heraus ist das
            // kein Fehler, sondern der Anfang — die Vorschau soll „noch nichts
            // anzuzeigen" sagen und nicht rot werden. Und sie fragt entprellt,
            // also schriebe ein 404 hier eine Log-Warnung pro Tastendruck.
            //
            // Ein GESPEICHERTER Knoten ohne beides ist etwas anderes: den hat
            // jemand so abgelegt, und die Mails-Liste soll sagen, dass daran
            // etwas fehlt. Deshalb bleibt der 404 auf dem gespeicherten Weg.
            if ($draft !== null) {
                return response()->json(['data' => [
                    'node_key' => $nodeKey,
                    'label' => (string) ($node?->label ?: $nodeKey),
                    'slug' => null,
                    'subject' => '',
                    'html' => '',
                    'source' => 'empty',
                    'snapshot' => $node !== null ? $this->snapshotFor($automationFlow, $node) : null,
                ]]);
            }

            return $this->failure(
                __('Dieser Schritt trägt weder eine Vorlage noch einen eigenen Text, es gibt also nichts anzuzeigen.'),
                404
            );
        }

        $sample = $this->sampleData();

        try {
            $html = $this->renderWithSample($body, $sample);
            $subject = $this->renderWithSample($subject, $sample);
        } catch (\Throwable $e) {
            return $this->failure(
                __('Die Mail ":key" ließ sich nicht rendern: :grund', ['key' => $nodeKey, 'grund' => $e->getMessage()]),
                500,
                $e
            );
        }

        return response()->json(['data' => [
            'node_key' => $nodeKey,
            'label' => (string) ($node?->label ?: $nodeKey),
            'slug' => $slug !== '' ? $slug : null,
            'subject' => $subject,
            // An inline body is plain text as often as not; the iframe would
            // swallow the line breaks. Wrapped only when nothing suggests HTML,
            // so a hand-written HTML body is untouched.
            'html' => $source === 'inline' && ! str_contains($html, '<') ? nl2br(e($html)) : $html,
            'source' => $source,
            'snapshot' => $node !== null ? $this->snapshotFor($automationFlow, $node) : null,
        ]]);
    }

    /**
     * Die ungespeicherte Konfiguration aus dem Rumpf — oder null, wenn keine
     * mitgeschickt wurde (dann gilt der Knoten aus der Datenbank).
     *
     * Nur die drei Schlüssel, aus denen eine Mail entsteht, und nur als
     * Zeichenkette. Alles andere im Rumpf ist für eine Vorschau bedeutungslos,
     * und was keine Zeichenkette ist, wird hier zu einer leeren — sonst stürbe
     * der Renderer weiter unten an einer Typumwandlung, für einen Wert, den das
     * Formular so gar nicht schicken kann.
     *
     * @return array<string, string>|null
     */
    protected function draftConfig(Request $request): ?array
    {
        if (! $request->isMethod('post')) {
            return null;
        }

        $config = $request->input('config');

        if (! is_array($config)) {
            return null;
        }

        $draft = [];

        foreach (['template', 'subject', 'body'] as $key) {
            $value = $config[$key] ?? null;
            $draft[$key] = is_string($value) ? $value : '';
        }

        return $draft;
    }

    /**
     * What this node actually sent, as `{url, send_count, last_sent_at}`, or
     * null when there is nothing to show.
     *
     * Everything is reached through the frozen class name and `class_exists` —
     * `goldnead/statamic-email-templates` is optional here, and the snapshot
     * table belongs to it alone. `$ownerType` and `$ownerId` are the shapes the
     * suite agreed on: `automations:node` and `"{flow uuid}:{node uuid}"`.
     *
     * @return array<string, mixed>|null
     */
    protected function snapshotFor(Automation $automation, AutomationNode $node): ?array
    {
        if (! class_exists(self::SNAPSHOTS)) {
            return null;
        }

        $class = self::SNAPSHOTS;

        try {
            if (! $class::available()) {
                return null;
            }

            $snapshot = $class::latestForOwner('automations:node', $automation->uuid.':'.$node->uuid);

            if ($snapshot === null) {
                return null;
            }

            $url = $class::previewUrl($snapshot);

            if ($url === null) {
                return null;
            }

            return [
                'url' => $url,
                'send_count' => (int) $snapshot->send_count,
                'last_sent_at' => $snapshot->last_sent_at?->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[automations] Der Versand-Schnappschuss dieses Mail-Knotens ließ sich nicht lesen.', [
                'node' => $node->node_key,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * The managed `et_templates` entries as `{slug, title, subject, preview}`,
     * scoped to the brand this request runs as — the same scope the resolver
     * and the send node use. Empty (never a fatal) when the addon or the
     * collection is absent.
     *
     * @return Collection<int, array{slug: string, title: string, subject: string, preview: string}>
     */
    protected function templates(): Collection
    {
        if (! $this->addonInstalled() || ! class_exists(Entry::class)) {
            return collect();
        }

        try {
            $query = Entry::query()->where('collection', 'et_templates');

            if (($brand = $this->currentBrand()) !== null) {
                $query->where('brand', $brand);
            }

            return collect($query->get())
                ->map(fn ($entry) => [
                    'slug' => (string) $entry->slug(),
                    'title' => (string) ($entry->value('title') ?? $entry->slug()),
                    'subject' => (string) ($entry->value('subject') ?? ''),
                    'preview' => (string) ($entry->value('preview') ?? $entry->value('preheader') ?? ''),
                ])
                ->values();
        } catch (\Throwable $e) {
            // An empty picker and a broken picker look identical to the author,
            // so the difference has to end up somewhere.
            Log::warning('[automations] Die Liste der E-Mail-Vorlagen ließ sich nicht laden.', [
                'exception' => $e,
            ]);

            return collect();
        }
    }

    /**
     * Why a slug did not resolve — as a sentence an author can act on.
     *
     * The case worth naming is the brand mismatch: the entry exists, it just
     * belongs to somebody else. "Nicht gefunden" would send the author looking
     * for a template that is sitting right there in the E-Mail-Vorlagen listing.
     */
    protected function missingReason(string $slug): string
    {
        $brand = $this->currentBrand();

        if ($brand !== null && class_exists(Entry::class)) {
            try {
                $entry = Entry::query()
                    ->where('collection', 'et_templates')
                    ->where('slug', $slug)
                    ->first();

                if ($entry !== null && (string) $entry->value('brand') !== $brand) {
                    return __('Die Vorlage ":slug" gehört zur Marke ":fremd". Dieser Ablauf läuft als ":aktuell" und kann sie weder anzeigen noch versenden.', [
                        'slug' => $slug,
                        'fremd' => (string) ($entry->value('brand') ?: '—'),
                        'aktuell' => $brand,
                    ]);
                }
            } catch (\Throwable) {
                // Falls through to the plain answer below; this lookup only
                // sharpens the wording.
            }
        }

        return __('Es gibt keine Vorlage mit dem Kürzel ":slug".', ['slug' => $slug]);
    }

    /**
     * One exit for every failure: the reason goes to the client AND to the log.
     *
     * The client half is what the picker shows instead of "Vorschau nicht
     * verfügbar"; the log half is what survives after the author has closed the
     * modal.
     */
    protected function failure(string $message, int $status, ?\Throwable $e = null): JsonResponse
    {
        Log::warning('[automations] E-Mail-Vorschau fehlgeschlagen: '.$message, array_filter([
            'exception' => $e,
        ]));

        return response()->json(['message' => $message], $status);
    }

    /**
     * The brand handle this request runs as, or null on a single-brand install
     * (or when nothing resolved). Mirrors what
     * `EmailTemplateCollectionManager::findBySlug()` does, because list and
     * preview have to agree — that disagreement was F17.
     */
    protected function currentBrand(): ?string
    {
        if (! app()->bound('brand-context')) {
            return null;
        }

        try {
            $manager = app('brand-context');

            if (! $manager->multiBrandEnabled() || ! $manager->hasCurrent()) {
                return null;
            }

            $handle = (string) ($manager->current()->handle ?? '');

            return $handle !== '' ? $handle : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the optional email-templates addon is installed.
     */
    protected function addonInstalled(): bool
    {
        return class_exists(EmailTemplates::class);
    }

    /**
     * A mail body or subject with sample values in the placeholders — and the
     * placeholder left standing wherever there is no sample for it.
     *
     * **Aufgelöst wird mit `TokenResolver`, demselben Renderer wie der
     * Versand.** Bis 2.18.1 stand hier ein eigener Ersetzer mit `data_get()`,
     * und der konnte weniger als der Motor: `{{ contact.first_name | upper }}`
     * blieb als Ganzes stehen, weil die Filterkette kein Bestandteil der
     * Ersetzung war. Die Vorschau zeigte damit nachweislich etwas anderes als
     * das, was rausging — der teuerste Fehler, den eine Vorschau machen kann.
     *
     * Eine Sache bleibt anders, und zwar mit Absicht: **ein Platzhalter, für
     * den es kein Beispiel gibt, bleibt stehen.** Der Motor macht aus einem
     * unbekannten Token eine leere Zeichenkette, was für einen Versand richtig
     * ist (ein fehlender Wert ist nichts, kein verirrtes `{{ … }}` in einem
     * fremden Posteingang) und für eine Vorschau falsch: ein Knoten mit
     * `{{ payment.name }}` las sich als „Hallo ," und „über Cent ()", also als
     * kaputte Mail statt als Platzhalter ohne Beispiel. Deshalb die Abfrage
     * davor: nur was der Kontext kennt, geht durch den Resolver.
     *
     * Die Ausnahme von der Ausnahme ist `| default:`. Dieser Filter existiert
     * genau für den fehlenden Wert, und der Versand schickt dafür den Ersatz
     * raus. Ein Platzhalter mit `default:` gehört also aufgelöst, sonst zeigt
     * die Vorschau einen rohen Token, wo eine Mail „Hallo Kunde" sagt.
     *
     * Nebenwirkung, die hier erwünscht ist: `{{ secret.* }}` kennt der Kontext
     * nicht, also fragt auch niemand den SecretStore. Ein Zugangsschlüssel
     * landet nicht in einer Vorschau.
     *
     * @param  array<string, mixed>  $sample
     */
    protected function renderWithSample(string $value, array $sample): string
    {
        if ($value === '') {
            return '';
        }

        $context = AutomationContext::make($sample);
        $resolver = app(TokenResolver::class);

        return (string) preg_replace_callback(
            '/\{\{\s*([\w.\-]+)\s*(\|[^}]*)?\}\}/',
            function (array $match) use ($context, $resolver): string {
                $hasDefault = preg_match('/\|\s*default\s*:/', $match[2] ?? '') === 1;

                if (! $hasDefault && $context->get($match[1]) === null) {
                    return $match[0];
                }

                $resolved = $resolver->resolveString($match[0], $context);

                return is_scalar($resolved) ? (string) $resolved : $match[0];
            },
            $value
        );
    }

    /**
     * Representative sample data for token resolution in the preview. Mirrors
     * the shapes an automation typically seeds (subscriber/contact/stimmanalyse)
     * so `{{ subscriber.first_name }}` etc. render to a plausible value.
     *
     * @return array<string, mixed>
     */
    protected function sampleData(): array
    {
        $person = [
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
        ];

        return [
            'subscriber' => $person,
            'contact' => $person,
            'stimmanalyse' => [
                'name' => 'Max',
                'variant' => 'live',
            ],
        ];
    }
}
